<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\MikrotikApiService;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aprovisionamiento de equipos SIN contrato.
 *
 * No todo lo que se conecta a la red le factura a alguien: ONTs de
 * prueba, repetidores propios, enlaces entre sedes de la empresa,
 * cámaras. Antes había que inventarles un contrato o configurarlos a
 * mano en el equipo, por fuera del sistema.
 *
 * Lo que se comprueba aquí:
 *  - Que el caso CON contrato siga siendo el de siempre (es la norma
 *    y es lo que no se puede romper).
 *  - Que sin contrato se exija una descripción, porque es lo único
 *    que dirá para qué existe ese equipo.
 *  - Que la excepción quede en la trazabilidad: un equipo suelto en
 *    la red tiene que poder rastrearse hasta quién lo autorizó.
 */
class StandaloneProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Olt $olt;
    private Router $router;
    private Contract $contrato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ENG']);
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($role);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $role->id,
        ]);

        $plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'contract_number' => 'ENG000777',
            'status' => 'Activo',
            // El contrato arranca sin equipo ni credenciales: así se
            // puede afirmar que un aprovisionamiento SIN contrato no
            // le escribió nada (el factory las rellena al azar).
            'cpe_sn' => null,
            'user_pppoe' => null,
            'password_pppoe' => null,
            'user_id' => $this->admin->id,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $this->branch->id, 'name' => 'OLT pruebas',
            'ip_address' => '10.0.0.1', 'ssh_port' => 22, 'telnet_port' => 23,
            'snmp_port' => 161, 'read_snmp_comunity' => 'public',
            'username' => 'admin', 'password' => 'x', 'brand' => 'huawei', 'uptime' => '0',
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router pruebas',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    /** La OLT responde como si la ONT se hubiera creado bien. */
    private function oltQueActiva(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('activateOnt')
                ->andReturn(['ont_id' => 7, 'service_port' => 123]);
            $mock->shouldReceive('getOntIfIndexes')->andReturn([]);
        });
    }

    /** Datos mínimos para autorizar una ONT. */
    private function datosOnt(array $extra = []): array
    {
        return array_merge([
            'olt_id' => $this->olt->id,
            'ont_sn' => 'HWTC-DD5BB00A',
            'ont_location' => '0/1/9',
            'vlan' => 100,
            'ont_lineprofile' => 10,
            'ont_srvprofile' => 10,
        ], $extra);
    }

    // ==================== ONT con contrato (la norma) ====================

    public function test_la_ont_con_contrato_sigue_funcionando_igual(): void
    {
        $this->oltQueActiva();

        $this->post(route('onts.activate'), $this->datosOnt([
            'contract_id' => $this->contrato->id,
            'description' => 'Juan Perez CC 123',
        ]))->assertRedirect();

        $ont = Ont::where('sn', 'HWTC-DD5BB00A')->firstOrFail();

        $this->assertSame($this->contrato->id, $ont->contract_id);
        $this->assertSame('Juan Perez CC 123', $ont->description);

        // El serial se copia al contrato, como siempre
        $this->assertSame('HWTC-DD5BB00A', $this->contrato->fresh()->cpe_sn);
    }

    public function test_sin_contrato_ni_casilla_no_se_autoriza(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldNotReceive('activateOnt');
        });

        $this->post(route('onts.activate'), $this->datosOnt([
            'description' => 'Algo',
        ]))->assertSessionHasErrors('contract_id');

        $this->assertSame(0, Ont::count());
    }

    // ==================== ONT sin contrato ====================

    public function test_la_ont_puede_autorizarse_sin_contrato(): void
    {
        $this->oltQueActiva();

        $this->post(route('onts.activate'), $this->datosOnt([
            'sin_contrato' => '1',
            'description' => 'Repetidor parque principal',
        ]))->assertRedirect();

        $ont = Ont::where('sn', 'HWTC-DD5BB00A')->firstOrFail();

        $this->assertNull($ont->contract_id);
        $this->assertSame('Repetidor parque principal', $ont->description);
    }

    public function test_sin_contrato_la_descripcion_es_obligatoria(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldNotReceive('activateOnt');
        });

        // Sin descripción no queda nada que identifique al equipo
        $this->post(route('onts.activate'), $this->datosOnt([
            'sin_contrato' => '1',
        ]))->assertSessionHasErrors('description');

        $this->assertSame(0, Ont::count());
    }

    public function test_marcar_sin_contrato_ignora_un_contrato_colado(): void
    {
        $this->oltQueActiva();

        // El campo oculto podría venir con valor de una selección
        // previa; manda la casilla, no el id.
        $this->post(route('onts.activate'), $this->datosOnt([
            'sin_contrato' => '1',
            'contract_id' => $this->contrato->id,
            'description' => 'ONT de laboratorio',
        ]))->assertRedirect();

        $this->assertNull(Ont::where('sn', 'HWTC-DD5BB00A')->firstOrFail()->contract_id);

        // Y el contrato no quedó marcado con un serial que no es suyo
        $this->assertNull($this->contrato->fresh()->cpe_sn);
    }

    public function test_autorizar_una_ont_queda_en_la_trazabilidad(): void
    {
        $this->oltQueActiva();

        $this->post(route('onts.activate'), $this->datosOnt([
            'sin_contrato' => '1',
            'description' => 'ONT de pruebas',
        ]))->assertRedirect();

        $this->assertDatabaseHas('audits', ['action' => 'onts.activated']);

        $registro = \App\Models\Audit::where('action', 'onts.activated')->firstOrFail();

        // La excepción tiene que verse en el registro
        $this->assertStringContainsString('sin contrato', $registro->description);
        $this->assertStringContainsString('ONT de pruebas', $registro->description);
        $this->assertSame('red', $registro->category);
    }

    // ============ Descripción que se manda a la OLT ============

    public function test_la_descripcion_no_puede_romper_el_comando_de_la_olt(): void
    {
        // El comando se arma como  desc "loquesea"  : una comilla
        // cerraría la cadena y lo de atrás lo ejecutaría la OLT.
        $sucia = 'Prueba" undo ont 1
 malicioso';

        $limpia = OltSshService::descripcionSegura($sucia);

        $this->assertStringNotContainsString('"', $limpia);
        $this->assertStringNotContainsString("\n", $limpia);
        $this->assertLessThanOrEqual(64, mb_strlen($limpia));
    }

    // ============ Caja NAP al autorizar la ONT ============

    /**
     * Prepara una caja NAP colgada de un puerto PON concreto.
     *
     * @return \App\Models\NapBox
     */
    private function cajaEnPuerto(int $slot, int $port, int $capacidad = 8)
    {
        $red = \App\Models\OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red pruebas',
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $this->olt->update(['optical_network_id' => $red->id]);

        $pon = \App\Models\PonPort::create([
            'optical_network_id' => $red->id,
            'olt_id' => $this->olt->id,
            'frame' => 0,
            'slot' => $slot,
            'port' => $port,
            'max_onts' => 64,
            'active' => true,
        ]);

        return app(\App\Services\OdnManager::class)->crearCaja($red, [
            'pon_port_id' => $pon->id,
            'capacity' => $capacidad,
            'address' => 'Calle 1 # 2-3',
            'latitude' => 6.2,
            'longitude' => -75.5,
            'status' => \App\Models\NapBox::OPERATIVA,
        ]);
    }

    public function test_al_autorizar_se_puede_anotar_la_caja_y_el_puerto(): void
    {
        $this->oltQueActiva();

        $caja = $this->cajaEnPuerto(1, 9);
        $puerto = $caja->ports->firstWhere('number', 3);

        $this->post(route('onts.activate'), $this->datosOnt([
            'contract_id' => $this->contrato->id,
            'description' => 'Juan Perez CC 123',
            'nap_port_id' => $puerto->id,
        ]))->assertRedirect();

        // El puerto queda ocupado por el CONTRATO: así lo modela el
        // inventario, porque lo que importa al intervenir una caja es
        // a qué clientes se deja sin servicio.
        $this->assertSame($puerto->id, $this->contrato->fresh()->nap_port_id);
        $this->assertSame('NAP001 / P3', $this->contrato->fresh()->nap_port);
    }

    /** La caja es opcional: sin ella la ONT se activa igual. */
    public function test_la_caja_nap_no_es_obligatoria(): void
    {
        $this->oltQueActiva();

        $this->post(route('onts.activate'), $this->datosOnt([
            'contract_id' => $this->contrato->id,
            'description' => 'Juan Perez CC 123',
        ]))->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, Ont::count());
        $this->assertNull($this->contrato->fresh()->nap_port_id);
    }

    /**
     * Una caja de OTRO puerto PON no se puede anotar.
     *
     * Sería una instalación físicamente imposible: la ONT cuelga del
     * puerto 0/1/9 y la caja de otro. El formulario solo ofrece las
     * correctas, pero el id llega del navegador.
     */
    public function test_no_admite_una_caja_de_otro_puerto_pon(): void
    {
        $this->oltQueActiva();

        // La caja está en el puerto 1/5; la ONT se activa en el 1/9
        $caja = $this->cajaEnPuerto(1, 5);

        $respuesta = $this->post(route('onts.activate'), $this->datosOnt([
            'contract_id' => $this->contrato->id,
            'description' => 'Juan Perez CC 123',
            'nap_port_id' => $caja->ports->first()->id,
        ]))->assertRedirect();

        // La ONT SÍ se activó —ya está escrita en la OLT— pero la caja
        // no se anotó, y el mensaje lo dice.
        $this->assertSame(1, Ont::count());
        $this->assertNull($this->contrato->fresh()->nap_port_id);
        $this->assertStringContainsString('no se anotó', session('success'));
    }

    /**
     * Sin contrato no hay a quién asignarle el puerto.
     *
     * Se avisa en vez de fallar en silencio: la ONT ya quedó activa.
     */
    public function test_sin_contrato_avisa_que_no_pudo_anotar_la_caja(): void
    {
        $this->oltQueActiva();

        $caja = $this->cajaEnPuerto(1, 9);

        $this->post(route('onts.activate'), $this->datosOnt([
            'sin_contrato' => 1,
            'description' => 'ONT de laboratorio',
            'nap_port_id' => $caja->ports->first()->id,
        ]))->assertRedirect();

        $this->assertSame(1, Ont::count());
        $this->assertStringContainsString('sin contrato', session('success'));
        $this->assertNull($caja->ports->first()->fresh()->contract);
    }

    /** El selector solo ofrece las cajas del puerto PON de la ONT. */
    public function test_el_selector_solo_trae_las_cajas_de_ese_puerto_pon(): void
    {
        $deEstePuerto = $this->cajaEnPuerto(1, 9);
        $deOtroPuerto = $this->cajaEnPuerto(1, 5);

        $datos = $this->getJson(route('naps.by_pon_port', [
            'olt' => $this->olt->id,
            'slot' => 1,
            'port' => 9,
        ]))->assertOk()->json();

        $this->assertCount(1, $datos);
        $this->assertSame($deEstePuerto->code, $datos[0]['codigo']);
        // Y trae sus puertos libres para elegir
        $this->assertCount(8, $datos[0]['puertos']);
    }

    // ==================== PPPoE con contrato (la norma) ====================

    /** El Mikrotik responde como si el secret se hubiera creado. */
    private function mikrotikQueCrea(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('createPppSecret')->andReturn('*A1');
        });
    }

    /** Datos mínimos para crear una cuenta. */
    private function datosCuenta(array $extra = []): array
    {
        return array_merge([
            'router_id' => $this->router->id,
            'username' => 'cuenta.prueba',
            'password' => 'clave123',
            'profile' => 'PLAN 150M',
        ], $extra);
    }

    public function test_la_cuenta_con_contrato_sigue_funcionando_igual(): void
    {
        $this->mikrotikQueCrea();

        $this->post(route('pppoe.store'), $this->datosCuenta([
            'contract_id' => $this->contrato->id,
            'comment' => 'Contrato ENG000777',
        ]))->assertRedirect();

        $cuenta = PppoeAccount::where('username', 'cuenta.prueba')->firstOrFail();

        $this->assertSame($this->contrato->id, $cuenta->contract_id);

        // Las credenciales se copian al contrato, como siempre
        $this->assertSame('cuenta.prueba', $this->contrato->fresh()->user_pppoe);
    }

    public function test_sin_contrato_ni_casilla_no_se_crea_la_cuenta(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldNotReceive('createPppSecret');
        });

        $this->post(route('pppoe.store'), $this->datosCuenta())
            ->assertSessionHasErrors('contract_id');

        $this->assertSame(0, PppoeAccount::count());
    }

    // ==================== PPPoE sin contrato ====================

    public function test_la_cuenta_puede_crearse_sin_contrato(): void
    {
        $this->mikrotikQueCrea();

        $this->post(route('pppoe.store'), $this->datosCuenta([
            'sin_contrato' => '1',
            'comment' => 'Enlace sede Yarumal',
        ]))->assertRedirect();

        $cuenta = PppoeAccount::where('username', 'cuenta.prueba')->firstOrFail();

        $this->assertNull($cuenta->contract_id);
        $this->assertSame('Enlace sede Yarumal', $cuenta->comment);
    }

    public function test_sin_contrato_el_comentario_es_obligatorio(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldNotReceive('createPppSecret');
        });

        // Sin comentario nadie sabría para qué existe esta cuenta
        $this->post(route('pppoe.store'), $this->datosCuenta(['sin_contrato' => '1']))
            ->assertSessionHasErrors('comment');

        $this->assertSame(0, PppoeAccount::count());
    }

    public function test_con_contrato_el_comentario_sigue_siendo_opcional(): void
    {
        $this->mikrotikQueCrea();

        $this->post(route('pppoe.store'), $this->datosCuenta([
            'contract_id' => $this->contrato->id,
        ]))->assertRedirect();

        $this->assertSame(1, PppoeAccount::count());
    }

    public function test_en_la_cuenta_marcar_sin_contrato_ignora_un_contrato_colado(): void
    {
        $this->mikrotikQueCrea();

        $this->post(route('pppoe.store'), $this->datosCuenta([
            'sin_contrato' => '1',
            'contract_id' => $this->contrato->id,
            'comment' => 'Cámara parque principal',
        ]))->assertRedirect();

        $this->assertNull(PppoeAccount::where('username', 'cuenta.prueba')->firstOrFail()->contract_id);

        // Y al contrato no le quedaron credenciales que no son suyas
        $this->assertNull($this->contrato->fresh()->user_pppoe);
    }

    public function test_crear_una_cuenta_queda_en_la_trazabilidad(): void
    {
        $this->mikrotikQueCrea();

        $this->post(route('pppoe.store'), $this->datosCuenta([
            'sin_contrato' => '1',
            'comment' => 'Antena repetidora',
        ]))->assertRedirect();

        $registro = \App\Models\Audit::where('action', 'pppoe.created')->firstOrFail();

        $this->assertStringContainsString('sin contrato', $registro->description);
        $this->assertStringContainsString('Antena repetidora', $registro->description);
        $this->assertSame('red', $registro->category);
    }

    // ==================== Pantallas ====================

    public function test_los_modales_ofrecen_la_opcion_sin_contrato(): void
    {
        $this->get(route('pppoe.index'))
            ->assertOk()
            ->assertSee('no pertenece a un contrato', false);

        $this->get(route('onts.no-authorized'))
            ->assertOk()
            ->assertSee('no pertenece a un contrato', false)
            // La descripción dejó de ser un campo oculto: ahora se ve
            // qué se le va a escribir a la OLT.
            ->assertSee('Descripción en la OLT', false);
    }
}
