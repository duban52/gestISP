<?php

namespace Tests\Feature\TechnicalOrders;

use App\Billing\Services\ContractStatusFromOrder;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\TechnicalOrder;
use App\Models\TechnicalOrderDetail;
use App\Models\User;
use App\Services\MikrotikApiService;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cerrar una orden actúa sobre los equipos del cliente.
 *
 * QUÉ SE DEFIENDE
 * ---------------
 * Un corte deshabilita la cuenta PPPoE y la ONT; una reconexión las
 * vuelve a habilitar. Antes el corte solo cambiaba el estado del
 * contrato: el cliente quedaba «Suspendido» en el sistema y navegando
 * en la realidad hasta que alguien se acordara de cortarlo a mano.
 *
 * Y LA REGLA DE SIEMPRE: que el equipo no conteste no puede impedir
 * cerrar la orden. Lo que no se pudo hacer se devuelve como pendiente.
 *
 * Los servicios que hablan con equipos van simulados: una prueba no
 * puede abrir un SSH ni llamar a un router.
 */
class OrderEquipmentEffectsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);
    }

    private function contrato(string $estado = 'Activo'): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
            'status' => $estado,
        ]);
    }

    private function cuenta(Contract $contrato, bool $deshabilitada = false): PppoeAccount
    {
        $router = Router::create([
            'branch_id' => $this->branch->id,
            'name' => 'Router de prueba',
            'ip_address' => '10.0.0.1',
            'username' => 'admin',
            'password' => 'secret',
            'api_port' => 8728,
        ]);

        return PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $router->id,
            'contract_id' => $contrato->id,
            'username' => 'cliente01',
            'password' => 'clave',
            'profile' => 'PLAN-100M',
            'mikrotik_id' => '*1',
            'disabled' => $deshabilitada,
        ]);
    }

    private function ont(Contract $contrato, bool $habilitada = true): Ont
    {
        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'name' => 'OLT de prueba',
            'ip_address' => '10.0.0.10',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'root', 'password' => 'admin',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        return Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $olt->id,
            'contract_id' => $contrato->id,
            'slot' => '1', 'port' => '2', 'onu_id' => 7,
            'sn' => 'HWTC12345678',
            'admin_enabled' => $habilitada,
        ]);
    }

    private function cerrar(Contract $contrato, string $detalle): ContractStatusFromOrder
    {
        $orden = TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->admin->id,
            'created_by' => $this->admin->id,
            'type' => TechnicalOrder::SERVICIO,
            'detail' => $detalle,
            'status' => 'Prefinalizada',
            'initial_comment' => $detalle,
        ]);

        $servicio = app(ContractStatusFromOrder::class);
        $servicio->aplicar($orden);

        return $servicio;
    }

    // ==================== Corte ====================

    public function test_un_corte_deshabilita_la_cuenta_y_la_ont(): void
    {
        $contrato = $this->contrato();
        $cuenta = $this->cuenta($contrato);
        $ont = $this->ont($contrato);

        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')
            ->once()
            ->withArgs(fn ($router, $c, $deshabilitar) => $deshabilitar === true));

        $this->mock(OltSshService::class, fn ($m) => $m->shouldReceive('setOntAdminState')
            ->once()
            ->withArgs(fn ($olt, $o, $habilitar) => $habilitar === false));

        $this->cerrar($contrato, 'Corte de servicio');

        $this->assertTrue($cuenta->fresh()->disabled);
        $this->assertFalse($ont->fresh()->admin_enabled);
        $this->assertSame('Suspendido', $contrato->fresh()->status);
    }

    public function test_una_reconexion_las_vuelve_a_habilitar(): void
    {
        $contrato = $this->contrato('Suspendido');
        $cuenta = $this->cuenta($contrato, deshabilitada: true);
        $ont = $this->ont($contrato, habilitada: false);

        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')
            ->once()
            ->withArgs(fn ($router, $c, $deshabilitar) => $deshabilitar === false));

        $this->mock(OltSshService::class, fn ($m) => $m->shouldReceive('setOntAdminState')
            ->once()
            ->withArgs(fn ($olt, $o, $habilitar) => $habilitar === true));

        $this->cerrar($contrato, 'Reconexión');

        $this->assertFalse($cuenta->fresh()->disabled);
        $this->assertTrue($ont->fresh()->admin_enabled);
        $this->assertSame('Activo', $contrato->fresh()->status);
    }

    public function test_una_incidencia_no_toca_los_equipos(): void
    {
        $contrato = $this->contrato();
        $cuenta = $this->cuenta($contrato);

        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldNotReceive('setPppSecretState'));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState'));

        $this->cerrar($contrato, 'Sin servicio de internet');

        $this->assertFalse($cuenta->fresh()->disabled);
        // Y tampoco el estado: una avería resuelta no reactiva nada.
        $this->assertSame('Activo', $contrato->fresh()->status);
    }

    public function test_lo_que_ya_estaba_como_se_pide_no_se_vuelve_a_tocar(): void
    {
        // Mandarle otra vez «deshabilitar» a un equipo ya deshabilitado
        // es una sesión SSH de 40 s para no cambiar nada.
        $contrato = $this->contrato();
        $this->cuenta($contrato, deshabilitada: true);
        $this->ont($contrato, habilitada: false);

        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldNotReceive('setPppSecretState'));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState'));

        $this->cerrar($contrato, 'Corte de servicio');

        $this->assertSame('Suspendido', $contrato->fresh()->status);
    }

    // ==================== Cuando el equipo no contesta ====================

    public function test_si_el_router_falla_la_orden_se_cierra_igual_y_queda_el_pendiente(): void
    {
        $contrato = $this->contrato();
        $cuenta = $this->cuenta($contrato);

        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')
            ->andThrow(new \RuntimeException('Router inalcanzable')));

        $servicio = $this->cerrar($contrato, 'Corte de servicio');

        // El estado sí cambia: la contabilidad no depende de que el
        // equipo conteste.
        $this->assertSame('Suspendido', $contrato->fresh()->status);
        // La cuenta NO se marca cortada: decirlo sin que lo esté es
        // peor que no decir nada.
        $this->assertFalse($cuenta->fresh()->disabled);

        $parte = $servicio->ultimoParte();

        $this->assertNotEmpty($parte['pendientes']);
        $this->assertStringContainsString('cliente01', implode(' ', $parte['pendientes']));
    }

    public function test_el_corte_actua_aunque_el_contrato_ya_estuviera_suspendido(): void
    {
        // El estado dice lo que el sistema cree; los equipos, lo que el
        // cliente tiene. Esta orden se cerró para arreglar lo segundo.
        $contrato = $this->contrato('Suspendido');
        $cuenta = $this->cuenta($contrato);

        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')->once());
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState'));

        $this->cerrar($contrato, 'Corte de servicio');

        $this->assertTrue($cuenta->fresh()->disabled);
    }

    // ==================== Un detalle creado hoy ====================

    public function test_un_detalle_nuevo_aplica_su_efecto(): void
    {
        $servicioTipo = \App\Models\TechnicalOrderType::where('name', 'Servicio')->firstOrFail();

        TechnicalOrderDetail::create([
            'technical_order_type_id' => $servicioTipo->id,
            'name' => 'Corte por fraude',
            'key' => 'corte por fraude',
            'target_contract_status' => 'Suspendido',
            'pppoe_action' => TechnicalOrderDetail::DESHABILITAR,
            'ont_action' => TechnicalOrderDetail::SIN_ACCION,
            'active' => true,
        ]);

        $contrato = $this->contrato();
        $cuenta = $this->cuenta($contrato);

        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')->once());

        $this->cerrar($contrato, 'Corte por fraude');

        $this->assertTrue($cuenta->fresh()->disabled);
        $this->assertSame('Suspendido', $contrato->fresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
