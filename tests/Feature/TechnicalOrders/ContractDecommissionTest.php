<?php

namespace Tests\Feature\TechnicalOrders;

use App\Billing\Enums\ContractStatus;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\NapBox;
use App\Models\OpticalNetwork;
use App\Models\PonPort;
use App\Models\NapPort;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Services\MikrotikApiService;
use App\Services\OdnManager;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dar de baja un contrato suelta lo que ocupaba.
 *
 * EL PROBLEMA
 * -----------
 * Retirar un contrato solo le cambiaba el estado. El puerto de la caja
 * NAP seguía figurando ocupado, la cuenta PPPoE seguía habilitada y la
 * ONT seguía provisionada en la OLT. Cajas que parecen llenas teniendo
 * espacio, retirados que conservan internet, y ONTs de nadie.
 *
 * LO QUE SE DEFIENDE, Y ES LO DIFÍCIL
 * -----------------------------------
 * Que la contabilidad NO dependa de que el equipo conteste. La OLT se
 * cae y el router se vuelve inalcanzable; si el cierre de la orden
 * dependiera de eso, un corte de red impediría dar de baja a un cliente.
 *
 * Y la excepción deliberada: la ficha de la ONT **no** se borra si la
 * OLT no confirmó el comando. Borrarla dejaría el equipo provisionado
 * allí sin nadie siguiéndole el rastro.
 *
 * Los servicios que hablan con equipos van simulados: una prueba no
 * puede abrir un SSH ni llamar a un router.
 */
class ContractDecommissionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Plan $plan;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->assignRole($this->rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        Permission::firstOrCreate(
            ['name' => 'contracts.status', 'guard_name' => 'web'],
            ['description' => 'Cambiar el estado de contratos'],
        );
        $this->rol->givePermissionTo('contracts.status');

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    /** Un contrato activo con puerto, cuenta y ONT. */
    private function contratoConTodo(): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'user_id' => $this->admin->id,
        ]);

        // La caja NAP cuelga de una red óptica y de un puerto PON: hay
        // que armar la cadena entera, igual que en RelocationNapTest.
        [$red, $pon] = $this->redConPuertoPon();

        $caja = app(OdnManager::class)->crearCaja($red, [
            'pon_port_id' => $pon->id,
            'capacity' => 8,
            'address' => 'Calle 19 # 23-46',
            'latitude' => 6.2442,
            'longitude' => -75.5812,
            'status' => NapBox::OPERATIVA,
        ]);

        // `crearCaja` ya crea sus puertos según la capacidad.
        $puerto = NapPort::where('nap_box_id', $caja->id)->orderBy('number')->firstOrFail();

        $contrato->update([
            'nap_port_id' => $puerto->id,
            'nap_port' => $caja->code . ' / P' . $puerto->number,
            'user_pppoe' => 'cliente01',
            'password_pppoe' => 'secreto',
            'cpe_sn' => 'HWTC12345678',
        ]);

        $router = Router::create([
            'branch_id' => $this->branch->id,
            'name' => 'Router de prueba',
            'ip_address' => '10.0.0.1',
            'username' => 'admin',
            'password' => 'admin',
        ]);

        PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $router->id,
            'contract_id' => $contrato->id,
            'username' => 'cliente01',
            'password' => 'secreto',
            'profile' => 'default',
            'mikrotik_id' => '*1A',
            'disabled' => false,
        ]);

        $olt = Olt::where('branch_id', $this->branch->id)->firstOrFail();

        Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $olt->id,
            'contract_id' => $contrato->id,
            'slot' => '1',
            'port' => '2',
            'onu_id' => 7,
            'service_port' => 100,
            'sn' => 'HWTC12345678',
            'status' => 'online',
        ]);

        return $contrato->fresh();
    }

    /**
     * Red óptica, OLT y puerto PON: lo que necesita una caja NAP.
     *
     * @return array{0: OpticalNetwork, 1: PonPort}
     */
    private function redConPuertoPon(): array
    {
        $red = OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red de prueba',
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'optical_network_id' => $red->id,
            'name' => 'OLT de prueba',
            'ip_address' => '10.0.0.10',
            'ssh_port' => 22,
            'telnet_port' => 23,
            'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'root',
            'password' => 'admin',
            'brand' => 'huawei',
            'uptime' => '0',
        ]);

        $pon = PonPort::create([
            'optical_network_id' => $red->id,
            'olt_id' => $olt->id,
            'frame' => 0,
            'slot' => 1,
            'port' => 1,
            'max_onts' => 64,
            'active' => true,
        ]);

        return [$red, $pon];
    }

    /** Los equipos contestan bien. */
    private function equiposQueResponden(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('setPppSecretState')->andReturnNull();
        });

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('deleteOnt')->andReturnNull();
        });
    }

    /** La OLT está caída; el router responde. */
    private function oltCaida(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('setPppSecretState')->andReturnNull();
        });

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('deleteOnt')->andThrow(new RuntimeException('OLT inalcanzable'));
        });
    }

    private function retirar(Contract $contrato)
    {
        return $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $contrato->id,
            'target_contract_status' => ContractStatus::Retirado->value,
            'initial_comment' => 'El cliente pidió el retiro.',
        ]);
    }

    // ==================== Todo responde ====================

    public function test_al_retirar_se_libera_el_puerto(): void
    {
        $this->equiposQueResponden();
        $contrato = $this->contratoConTodo();

        $this->retirar($contrato)->assertRedirect();

        $contrato->refresh();

        // Se limpian LOS DOS campos: el id y el texto legible. Dejar el
        // texto haría que la ficha siguiera mostrando la caja y el
        // puerto de un contrato que ya no ocupa ninguno.
        $this->assertNull($contrato->nap_port_id);
        $this->assertNull($contrato->nap_port);
    }

    public function test_la_cuenta_pppoe_se_corta_y_se_desvincula(): void
    {
        $this->equiposQueResponden();
        $contrato = $this->contratoConTodo();

        $this->retirar($contrato)->assertRedirect();

        $cuenta = PppoeAccount::where('username', 'cliente01')->firstOrFail();

        $this->assertTrue((bool) $cuenta->disabled, 'La cuenta quedó habilitada: el retirado conserva internet.');
        $this->assertNull($cuenta->contract_id);

        // La cuenta NO se borra del sistema: su historial sigue
        // haciendo falta si el cliente reclama.
        $this->assertDatabaseHas('pppoe_accounts', ['username' => 'cliente01']);

        // Y el contrato deja de reflejar unas credenciales sin dueño.
        $this->assertNull($contrato->fresh()->user_pppoe);
    }

    public function test_la_ont_se_elimina(): void
    {
        $this->equiposQueResponden();
        $contrato = $this->contratoConTodo();

        $this->retirar($contrato)->assertRedirect();

        $this->assertDatabaseMissing('onts', ['sn' => 'HWTC12345678']);
        $this->assertNull($contrato->fresh()->cpe_sn);
    }

    public function test_el_usuario_ve_lo_que_se_libero(): void
    {
        $this->equiposQueResponden();
        $contrato = $this->contratoConTodo();

        $this->retirar($contrato)->assertSessionHas('success');
    }

    // ==================== Cuando un equipo no responde ====================

    public function test_la_baja_no_depende_de_que_la_olt_conteste(): void
    {
        // Si el cierre dependiera del equipo, un corte de red impediría
        // dar de baja a un cliente.
        $this->oltCaida();
        $contrato = $this->contratoConTodo();

        $this->retirar($contrato)->assertRedirect();

        $this->assertSame(ContractStatus::Retirado->value, $contrato->fresh()->status);
        // Y lo que sí es base de datos se hizo igual.
        $this->assertNull($contrato->fresh()->nap_port_id);
    }

    public function test_si_la_olt_falla_la_ont_no_se_borra(): void
    {
        // LA EXCEPCIÓN DELIBERADA. Borrar la ficha dejaría la ONT
        // provisionada en la OLT sin nadie siguiéndole el rastro: un
        // equipo fantasma ocupando un onu_id que el sistema cree libre.
        $this->oltCaida();
        $contrato = $this->contratoConTodo();

        $this->retirar($contrato)->assertRedirect();

        $this->assertDatabaseHas('onts', ['sn' => 'HWTC12345678']);

        // Pero se suelta del contrato, que ya no existe como cliente.
        $this->assertNull(Ont::where('sn', 'HWTC12345678')->first()->contract_id);
    }

    public function test_lo_pendiente_se_avisa_en_vez_de_enterrarse(): void
    {
        // Enterrar «la OLT no respondió» dentro de un mensaje verde de
        // «cerrado correctamente» es la forma segura de que nadie lo
        // termine.
        $this->oltCaida();
        $contrato = $this->contratoConTodo();

        $respuesta = $this->retirar($contrato);

        $respuesta->assertSessionHas('warning');
        $respuesta->assertSessionMissing('success');

        $this->assertStringContainsString(
            'HWTC12345678',
            session('warning'),
            'El aviso no dice qué ONT quedó sin eliminar.',
        );
    }

    // ==================== Alcance ====================

    public function test_un_cambio_que_no_es_baja_no_libera_nada(): void
    {
        // Suspender no es retirar: el cliente puede volver y su puerto,
        // su cuenta y su ONT tienen que seguir donde estaban.
        $this->equiposQueResponden();
        $contrato = $this->contratoConTodo();

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $contrato->id,
            'target_contract_status' => ContractStatus::Suspendido->value,
            'initial_comment' => 'Corte por no pago.',
        ])->assertRedirect();

        $this->assertNotNull($contrato->fresh()->nap_port_id);
        $this->assertDatabaseHas('onts', ['sn' => 'HWTC12345678']);
        $this->assertFalse((bool) PppoeAccount::where('username', 'cliente01')->first()->disabled);
    }

    public function test_cerrar_una_orden_de_retiro_tambien_libera(): void
    {
        // Las dos vías dan de baja, así que las dos tienen que soltar.
        // Por eso la liberación cuelga del ESTADO y no del tipo de orden.
        $this->equiposQueResponden();
        $contrato = $this->contratoConTodo();

        $orden = TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->admin->id,
            'created_by' => $this->admin->id,
            'type' => TechnicalOrder::SERVICIO,
            'detail' => 'Retiro de servicio',
            'status' => 'Prefinalizada',
            'initial_comment' => 'Retiro',
        ]);

        $this->put(route('technical_order.verification_process', $orden), [
            'verification_comment' => 'Verificada',
            'close_order' => '1',
        ])->assertRedirect();

        $this->assertSame(ContractStatus::Retirado->value, $contrato->fresh()->status);
        $this->assertNull($contrato->fresh()->nap_port_id);
        $this->assertDatabaseMissing('onts', ['sn' => 'HWTC12345678']);
    }

    public function test_un_contrato_sin_nada_asignado_no_revienta(): void
    {
        $this->equiposQueResponden();

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $pelado = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Por Instalar',
            'user_id' => $this->admin->id,
        ]);

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $pelado->id,
            'target_contract_status' => ContractStatus::Anulado->value,
            'initial_comment' => 'Nunca tomó el servicio.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(ContractStatus::Anulado->value, $pelado->fresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
