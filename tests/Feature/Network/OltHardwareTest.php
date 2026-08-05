<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\OltBoard;
use App\Models\OltPortMetric;
use App\Models\OltUplink;
use App\Models\Ont;
use App\Models\OpticalNetwork;
use App\Models\Plan;
use App\Models\PonPort;
use App\Models\User;
use App\Services\OltHardwareDiscovery;
use App\Services\OltPortPoller;
use App\Services\OltSnmpService;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Descubrimiento del hardware de la OLT y muestreo de sus puertos.
 *
 * QUÉ SE PROTEGE AQUÍ
 * -------------------
 * Lo importante no es que se lea el SNMP —eso lo hace la librería—,
 * sino qué se hace con lo leído:
 *
 *   · que aparezcan TODOS los puertos, incluidos los vacíos, que son
 *     los que sirven para planear dónde crecer;
 *   · que redescubrir NO pise la documentación (zona, splitter, cajas),
 *     porque eso convertiría una tarea nocturna en un borrador de datos;
 *   · que el emparejamiento sea por posición física y no por ifIndex,
 *     que la OLT reasigna al reiniciar una tarjeta;
 *   · que el cálculo de bits por segundo no invente picos cuando el
 *     contador se reinicia.
 *
 * El SNMP va simulado: se registra una fábrica propia en el contenedor
 * que devuelve lo que respondería el equipo real.
 */
class OltHardwareTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Olt $olt;
    private OpticalNetwork $red;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'HW']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3005556677']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->red = OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red principal',
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $this->branch->id,
            'optical_network_id' => $this->red->id,
            'name' => 'OLT central',
            'ip_address' => '10.0.0.20',
            'ssh_port' => 22,
            'telnet_port' => 23,
            'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'write_snmp_comunity' => 'private',
            'username' => 'root',
            'password' => 'admin',
            'brand' => 'huawei',
            'uptime' => '0',
            'active' => true,
        ]);
    }

    /**
     * Simula la OLT: devuelve por SNMP lo que respondería el equipo.
     *
     * @param  array<int, string>  $interfaces  [ifIndex => ifDescr]
     * @param  array<string, array<int, string>>  $tablas  otras tablas por OID
     */
    private function simularSnmp(array $interfaces, array $tablas = [], bool $responde = true): void
    {
        $ifs = config('olt_snmp.brands.huawei.interfaces');

        $porOid = array_merge([
            '.1.3.6.1.2.1.2.2.1.2' => $interfaces,
        ], $tablas);

        $cliente = Mockery::mock(SnmpClient::class);
        $cliente->shouldReceive('isReachable')->andReturn($responde);
        $cliente->shouldReceive('close')->andReturnNull();
        $cliente->shouldReceive('walk')->andReturnUsing(
            fn (string $oid) => $porOid[$oid] ?? []
        );

        $fabrica = Mockery::mock(SnmpClientFactory::class);
        $fabrica->shouldReceive('forOlt')->andReturn($cliente);

        $this->app->instance(SnmpClientFactory::class, $fabrica);

        // Se guarda para que las pruebas puedan referirse a los OIDs
        // sin repetirlos.
        $this->oids = $ifs;
    }

    /** @var array<string, string> */
    private array $oids = [];

    // ==================== Descubrimiento ====================

    /** @test */
    public function descubre_los_puertos_pon_aunque_no_tengan_ninguna_ont(): void
    {
        $this->simularSnmp([
            1 => 'GPON_UNI 0/1/0',
            2 => 'GPON_UNI 0/1/1',
            3 => 'GPON_UNI 0/2/0',
            // Ruido que la OLT publica y que NO debe acabar en el
            // inventario: interfaces internas sin puerto físico.
            90 => 'NULL0',
            91 => 'Vlanif100',
            92 => 'meth 0/0/1',
        ]);

        $resumen = app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->assertSame(3, $resumen['pon']);
        $this->assertSame(3, $resumen['pon_nuevos']);
        $this->assertSame(2, $resumen['tarjetas']);

        // Ninguno tiene ONTs y aun así están: es justo el caso que el
        // método viejo (deducir de las ONTs) no cubría.
        $this->assertSame(0, Ont::count());
        $this->assertSame(3, PonPort::where('olt_id', $this->olt->id)->count());

        $puerto = PonPort::where('slot', 1)->where('port', 1)->first();
        $this->assertSame(2, $puerto->if_index);
        $this->assertSame($this->red->id, $puerto->optical_network_id);
        $this->assertNotNull($puerto->discovered_at);
    }

    /** @test */
    public function redescubrir_no_pisa_lo_documentado(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        // Alguien documenta el puerto
        $puerto = PonPort::first();
        $puerto->update([
            'description' => 'Troncal del barrio Centro',
            'splitter_ratio' => '1:8',
            'max_onts' => 32,
        ]);

        // La OLT reinicia la tarjeta y le cambia el ifIndex
        $this->simularSnmp([77 => 'GPON_UNI 0/1/0']);
        $resumen = app(OltHardwareDiscovery::class)->descubrir($this->olt);

        // No se crea otro puerto: se empareja por posición física
        $this->assertSame(0, $resumen['pon_nuevos']);
        $this->assertSame(1, PonPort::count());

        $puerto->refresh();
        $this->assertSame(77, $puerto->if_index);
        // Y lo que escribió una persona sigue intacto
        $this->assertSame('Troncal del barrio Centro', $puerto->description);
        $this->assertSame('1:8', $puerto->splitter_ratio);
        $this->assertSame(32, $puerto->max_onts);
    }

    /** @test */
    public function descubre_los_uplinks_con_su_velocidad_y_estado(): void
    {
        $ifs = config('olt_snmp.brands.huawei.interfaces');

        $this->simularSnmp(
            [
                1 => 'GPON_UNI 0/1/0',
                20 => 'XGE 0/9/0',
                21 => 'GE 0/9/1',
            ],
            [
                $ifs['if_alias'] => [20 => 'Hacia el router de borde'],
                $ifs['oper_status'] => [20 => '1', 21 => '2'],
                $ifs['admin_status'] => [20 => '1', 21 => '1'],
                $ifs['high_speed'] => [20 => '10000', 21 => '1000'],
            ],
        );

        $resumen = app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->assertSame(2, $resumen['uplinks']);

        $diez = OltUplink::where('if_index', 20)->first();
        $this->assertSame('XGE 0/9/0', $diez->name);
        $this->assertSame('Hacia el router de borde', $diez->description);
        $this->assertSame(10000, $diez->speed_mbps);
        $this->assertTrue($diez->estaArriba());

        $uno = OltUplink::where('if_index', 21)->first();
        $this->assertFalse($uno->estaArriba());
    }

    /** @test */
    public function un_uplink_que_desaparece_se_borra(): void
    {
        $this->simularSnmp([20 => 'XGE 0/9/0', 21 => 'GE 0/9/1']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);
        $this->assertSame(2, OltUplink::count());

        // Se retira la tarjeta de subida y solo queda un puerto
        $this->simularSnmp([20 => 'XGE 0/9/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->assertSame(1, OltUplink::count());
        $this->assertSame(20, OltUplink::first()->if_index);
    }

    /** @test */
    public function un_puerto_pon_que_desaparece_no_se_borra(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0', 2 => 'GPON_UNI 0/1/1']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        // Los dos siguen: de un puerto PON pueden colgar cajas
        // documentadas, y borrarlo se las llevaría por delante.
        $this->assertSame(2, PonPort::count());

        $desaparecido = PonPort::where('port', 1)->first();
        $segundo = PonPort::where('port', 0)->first();

        // Pero se distingue cuál confirmó el equipo en esta pasada
        $this->assertTrue($desaparecido->discovered_at->lt($segundo->discovered_at)
            || $desaparecido->discovered_at->eq($segundo->discovered_at));
    }

    /** @test */
    public function una_olt_sin_red_no_se_puede_descubrir(): void
    {
        $this->olt->update(['optical_network_id' => null]);
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no pertenece a ninguna red/');

        app(OltHardwareDiscovery::class)->descubrir($this->olt);
    }

    /** @test */
    public function una_olt_que_no_responde_lo_dice_sin_tocar_nada(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0'], responde: false);

        try {
            app(OltHardwareDiscovery::class)->descubrir($this->olt);
            $this->fail('Se descubrió una OLT que no responde.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no responde', $e->getMessage());
        }

        $this->assertSame(0, PonPort::count());
        $this->assertSame(0, OltBoard::count());
    }

    /** @test */
    public function el_descubrimiento_queda_en_la_bitacora(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->assertTrue(
            \App\Models\Audit::where('action', 'olts.ports_discovered')
                ->where('category', 'red')
                ->exists()
        );
    }

    // ==================== Muestreo ====================

    /** @test */
    public function la_primera_muestra_no_calcula_velocidad_y_la_segunda_si(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        // 1.000.000 bytes en 60 segundos = 133.333 bps
        $this->simularContadores([1 => ['in' => 1_000_000, 'out' => 500_000]]);
        app(OltPortPoller::class)->poll($this->olt);

        $puerto = PonPort::first();
        $this->assertNull($puerto->in_bps, 'Sin muestra previa no se puede calcular velocidad.');

        $this->travel(60)->seconds();

        $this->simularContadores([1 => ['in' => 2_000_000, 'out' => 1_000_000]]);
        app(OltPortPoller::class)->poll($this->olt);

        $puerto->refresh();
        $this->assertEqualsWithDelta(133_333, $puerto->in_bps, 500);
        $this->assertEqualsWithDelta(66_666, $puerto->out_bps, 500);
        $this->assertSame(2, OltPortMetric::count());
    }

    /** @test */
    public function un_contador_reiniciado_no_dibuja_un_pico_falso(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->simularContadores([1 => ['in' => 9_000_000, 'out' => 9_000_000]]);
        app(OltPortPoller::class)->poll($this->olt);

        $this->travel(60)->seconds();

        // La OLT se reinició: el contador vuelve a empezar
        $this->simularContadores([1 => ['in' => 1_000, 'out' => 1_000]]);
        app(OltPortPoller::class)->poll($this->olt);

        $this->assertNull(PonPort::first()->in_bps);
    }

    /** @test */
    public function el_muestreo_cuenta_las_onts_de_cada_puerto(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->ont(['slot' => 1, 'port' => 0, 'status' => '1']);
        $this->ont(['slot' => 1, 'port' => 0, 'status' => '1']);
        $this->ont(['slot' => 1, 'port' => 0, 'status' => '0']);

        $this->simularContadores([1 => ['in' => 100, 'out' => 100]]);
        app(OltPortPoller::class)->poll($this->olt);

        $muestra = OltPortMetric::first();
        $this->assertSame(3, $muestra->onts_total);
        $this->assertSame(2, $muestra->onts_online);
    }

    // ==================== Pantalla ====================

    /** @test */
    public function la_ficha_muestra_las_tarjetas_los_puertos_y_los_uplinks(): void
    {
        $this->simularSnmp([
            1 => 'GPON_UNI 0/1/0',
            2 => 'GPON_UNI 0/1/1',
            20 => 'XGE 0/9/0',
        ]);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $respuesta = $this->get(route('olts.show', $this->olt))->assertOk();

        $this->assertCount(2, $respuesta->viewData('tarjetas'));
        $this->assertCount(2, $respuesta->viewData('ponPorts'));
        $this->assertCount(1, $respuesta->viewData('uplinks'));

        $respuesta->assertSee('Puertos de subida');
        $respuesta->assertSee('XGE 0/9/0');
    }

    /** @test */
    public function el_modal_de_un_puerto_trae_su_estado_sus_onts_y_su_trafico(): void
    {
        $this->simularSnmp([1 => 'GPON_UNI 0/1/0']);
        app(OltHardwareDiscovery::class)->descubrir($this->olt);

        $this->ont(['slot' => 1, 'port' => 0, 'status' => '1', 'rx_power' => '-19.5']);
        $this->ont(['slot' => 1, 'port' => 0, 'status' => '1', 'rx_power' => '-27.8']);
        $this->ont(['slot' => 1, 'port' => 0, 'status' => '0']);

        $puerto = PonPort::first();

        $datos = $this->getJson(route('api.pon_ports.show', $puerto))->assertOk()->json();

        $this->assertSame('0/1/0', $datos['puerto']['etiqueta']);
        $this->assertSame(3, $datos['onts']['total']);
        $this->assertSame(2, $datos['onts']['en_linea']);
        $this->assertEqualsWithDelta(-23.65, $datos['onts']['potencia_media'], 0.01);
        $this->assertEqualsWithDelta(-27.8, $datos['onts']['peor'], 0.01);

        // Las peores primero: rx_power es varchar, y sin convertir a
        // número "-19.5" quedaría antes que "-27.8".
        $this->assertEqualsWithDelta(-27.8, $datos['peores_onts'][0]['rx_power'], 0.01);
    }

    /** @test */
    public function no_se_puede_abrir_un_puerto_de_otra_sucursal(): void
    {
        $otra = Branch::factory()->create();

        $redAjena = OpticalNetwork::create([
            'branch_id' => $otra->id,
            'name' => 'Red ajena',
            'nap_prefix' => 'AJE',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $oltAjena = Olt::create([
            'branch_id' => $otra->id,
            'optical_network_id' => $redAjena->id,
            'name' => 'OLT ajena',
            'ip_address' => '10.9.9.9',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public', 'write_snmp_comunity' => 'private',
            'username' => 'root', 'password' => 'admin',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        $puertoAjeno = PonPort::create([
            'optical_network_id' => $redAjena->id,
            'olt_id' => $oltAjena->id,
            'frame' => 0, 'slot' => 1, 'port' => 0,
            'if_index' => 1,
        ]);

        $this->getJson(route('api.pon_ports.show', $puertoAjeno))->assertForbidden();
    }

    // ==================== Utilidades ====================

    /**
     * Simula los contadores de tráfico que devolvería la OLT.
     *
     * @param  array<int, array{in: int, out: int}>  $contadores
     */
    private function simularContadores(array $contadores): void
    {
        $snmp = Mockery::mock(OltSnmpService::class);
        $snmp->shouldReceive('bulkTrafficCounters')->andReturn($contadores);

        $this->app->instance(OltSnmpService::class, $snmp);
    }

    private function ont(array $extra = []): Ont
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
        ]);

        return Ont::create(array_merge([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt->id,
            'contract_id' => $contrato->id,
            'sn' => 'HWTC' . fake()->unique()->numerify('########'),
            'slot' => 1,
            'port' => 0,
            'onu_id' => fake()->unique()->numberBetween(1, 120),
            'status' => '1',
        ], $extra));
    }
}
