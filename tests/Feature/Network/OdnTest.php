<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\NapBox;
use App\Models\NapPort;
use App\Models\Olt;
use App\Models\OpticalNetwork;
use App\Models\Plan;
use App\Models\PonPort;
use App\Models\User;
use App\Services\OdnManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Red óptica documentada: cajas NAP, puertos y su ocupación.
 *
 * QUÉ SE PROTEGE AQUÍ
 * -------------------
 * El valor del módulo está en que la ocupación NO se guarda: se deduce
 * de qué contrato apunta a cada puerto. Ese es el punto que hay que
 * defender con pruebas, porque el día que alguien añada un campo
 * "ocupado" para "ir más rápido", el inventario empieza a mentir: se
 * desvincula un contrato por otro lado y la caja sigue diciendo que
 * está llena.
 *
 * Lo demás que se comprueba son las reglas que evitan datos imposibles:
 * dos clientes en un puerto, reducir una caja dejando contratos
 * colgando, o tocar cajas de otra sucursal.
 */
class OdnTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private OpticalNetwork $red;
    private PonPort $pon;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ODN']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3001112233']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->red = OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red centro',
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'optical_network_id' => $this->red->id,
            'name' => 'OLT centro',
            'ip_address' => '10.0.0.10',
            'ssh_port' => 22,
            'telnet_port' => 23,
            'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'write_snmp_comunity' => 'private',
            'username' => 'root',
            'password' => 'admin',
            'brand' => 'huawei',
            'uptime' => '0',
        ]);

        $this->pon = PonPort::create([
            'optical_network_id' => $this->red->id,
            'olt_id' => $olt->id,
            'frame' => 0,
            'slot' => 1,
            'port' => 1,
            'max_onts' => 64,
            'active' => true,
        ]);
    }

    private function caja(array $datos = []): NapBox
    {
        return app(OdnManager::class)->crearCaja($this->red, array_merge([
            'pon_port_id' => $this->pon->id,
            'capacity' => 8,
            'address' => 'Calle 19 # 23-46',
            'latitude' => 6.2442,
            'longitude' => -75.5812,
            'status' => NapBox::OPERATIVA,
        ], $datos));
    }

    private function contrato(): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'user_id' => $this->admin->id,
        ]);
    }

    // ==================== Cajas y puertos ====================

    /** @test */
    public function crear_una_caja_genera_sus_puertos_y_toma_el_consecutivo(): void
    {
        $caja = $this->caja(['capacity' => 16]);

        $this->assertSame('NAP001', $caja->code);
        $this->assertCount(16, $caja->ports);
        $this->assertSame([1, 16], [
            $caja->ports->min('number'),
            $caja->ports->max('number'),
        ]);

        // La segunda caja no puede repetir número
        $this->assertSame('NAP002', $this->caja()->code);
    }

    /** @test */
    public function la_ocupacion_se_deduce_de_los_contratos_no_de_un_campo_guardado(): void
    {
        $caja = $this->caja(['capacity' => 4]);
        $contrato = $this->contrato();

        $this->assertSame(0, $caja->puertosOcupados());
        $this->assertSame(4, $caja->puertosDisponibles());

        app(OdnManager::class)->asignarPuerto($contrato, $caja->ports->first());

        // Sin refrescar relaciones el conteo sería el viejo: se
        // recarga igual que haría una petición nueva.
        $caja = $caja->fresh(['ports.contract']);

        $this->assertSame(1, $caja->puertosOcupados());
        $this->assertSame(3, $caja->puertosDisponibles());
        $this->assertSame(25.0, $caja->ocupacion()['porcentaje']);

        // Y al liberar, la caja vuelve a tener cupo sin tocar nada más
        app(OdnManager::class)->liberarPuerto($contrato->fresh());

        $caja = $caja->fresh(['ports.contract']);
        $this->assertSame(0, $caja->puertosOcupados());
        $this->assertSame(4, $caja->puertosDisponibles());
    }

    /** @test */
    public function asignar_un_puerto_deja_el_texto_legible_en_sintonia(): void
    {
        $caja = $this->caja();
        $contrato = $this->contrato();
        $puerto = $caja->ports->firstWhere('number', 3);

        app(OdnManager::class)->asignarPuerto($contrato, $puerto);

        $contrato->refresh();

        $this->assertSame($puerto->id, $contrato->nap_port_id);
        $this->assertSame('NAP001 / P3', $contrato->nap_port);
    }

    /** @test */
    public function un_puerto_ocupado_no_admite_un_segundo_contrato(): void
    {
        $caja = $this->caja();
        $puerto = $caja->ports->first();

        app(OdnManager::class)->asignarPuerto($this->contrato(), $puerto);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya está ocupado/');

        app(OdnManager::class)->asignarPuerto($this->contrato(), $puerto->fresh());
    }

    /** @test */
    public function un_puerto_danado_no_se_ofrece_ni_se_puede_ocupar(): void
    {
        $caja = $this->caja();
        $puerto = $caja->ports->first();
        $puerto->update(['status' => NapPort::DANADO]);

        $this->assertFalse($puerto->estaDisponible());
        $this->assertSame(7, $caja->fresh(['ports.contract'])->puertosDisponibles());

        $this->expectException(RuntimeException::class);

        app(OdnManager::class)->asignarPuerto($this->contrato(), $puerto->fresh());
    }

    /** @test */
    public function no_se_puede_reducir_una_caja_dejando_contratos_colgando(): void
    {
        $caja = $this->caja(['capacity' => 16]);
        $contrato = $this->contrato();

        app(OdnManager::class)->asignarPuerto($contrato, $caja->ports->firstWhere('number', 12));

        try {
            app(OdnManager::class)->ajustarCapacidad($caja->fresh(['ports.contract']), 8);
            $this->fail('Se permitió reducir la caja dejando un contrato en un puerto inexistente.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('puerto 12', $e->getMessage());
        }

        // Y nada quedó a medias: la caja sigue con sus 16 puertos
        $this->assertSame(16, $caja->fresh()->capacity);
        $this->assertCount(16, $caja->fresh()->ports);
    }

    /** @test */
    public function ampliar_una_caja_solo_agrega_los_puertos_que_faltan(): void
    {
        $caja = $this->caja(['capacity' => 8]);
        $idsOriginales = $caja->ports->pluck('id');

        app(OdnManager::class)->ajustarCapacidad($caja, 16);

        $caja = $caja->fresh(['ports']);

        $this->assertCount(16, $caja->ports);
        // Los puertos viejos son los MISMOS: si se recrearan, los
        // contratos que apuntaban a ellos se quedarían huérfanos.
        $this->assertCount(
            $idsOriginales->count(),
            $caja->ports->pluck('id')->intersect($idsOriginales),
        );
    }

    // ==================== Pantallas ====================

    /** @test */
    public function la_caja_exige_direccion_y_punto_en_el_mapa(): void
    {
        $this->from(route('naps.create'))
            ->post(route('naps.store'), [
                'optical_network_id' => $this->red->id,
                'pon_port_id' => $this->pon->id,
                'capacity' => 8,
                'status' => NapBox::OPERATIVA,
                'address' => '',
            ])
            ->assertSessionHasErrors(['address', 'latitude', 'longitude']);

        $this->assertSame(0, NapBox::count());
    }

    /** @test */
    public function no_se_puede_colgar_una_caja_de_un_pon_de_otra_red(): void
    {
        $otraRed = OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red norte',
            'nap_prefix' => 'NRT',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $this->post(route('naps.store'), [
            'optical_network_id' => $otraRed->id,
            // El PON es de la red del setUp, no de esta
            'pon_port_id' => $this->pon->id,
            'capacity' => 8,
            'address' => 'Calle 1',
            'latitude' => 6.2,
            'longitude' => -75.5,
            'status' => NapBox::OPERATIVA,
        ])->assertSessionHasErrors('pon_port_id');
    }

    /**
     * Una caja completa en OTRA sucursal, con su red, su OLT y su PON.
     *
     * Es el escenario con el que se comprueba el aislamiento: si algo
     * de esto se pudiera ver o tocar desde la sucursal activa, un
     * usuario podría instalar clientes en la red de otra sede.
     */
    private function cajaDeOtraSucursal(): NapBox
    {
        $otraSucursal = Branch::factory()->create();

        $redAjena = OpticalNetwork::create([
            'branch_id' => $otraSucursal->id,
            'name' => 'Red ajena',
            'nap_prefix' => 'AJE',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $oltAjena = Olt::create([
            'branch_id' => $otraSucursal->id,
            'optical_network_id' => $redAjena->id,
            'name' => 'OLT ajena',
            'ip_address' => '10.9.9.9',
            'ssh_port' => 22,
            'telnet_port' => 23,
            'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'write_snmp_comunity' => 'private',
            'username' => 'root',
            'password' => 'admin',
            'brand' => 'huawei',
            'uptime' => '0',
        ]);

        $ponAjeno = PonPort::create([
            'optical_network_id' => $redAjena->id,
            'olt_id' => $oltAjena->id,
            'frame' => 0,
            'slot' => 1,
            'port' => 1,
            'max_onts' => 64,
            'active' => true,
        ]);

        return app(OdnManager::class)->crearCaja($redAjena, [
            'pon_port_id' => $ponAjeno->id,
            'capacity' => 8,
            'address' => 'Otra ciudad',
            'latitude' => 4.6,
            'longitude' => -74.0,
            'status' => NapBox::OPERATIVA,
        ]);
    }

    /** @test */
    public function una_caja_de_otra_sucursal_no_se_puede_abrir(): void
    {
        $this->get(route('naps.show', $this->cajaDeOtraSucursal()))->assertForbidden();
    }

    /** @test */
    public function el_mapa_entrega_las_cajas_con_su_ocupacion(): void
    {
        $caja = $this->caja(['capacity' => 4, 'name' => 'Parque']);
        app(OdnManager::class)->asignarPuerto($this->contrato(), $caja->ports->first());

        $datos = $this->getJson(route('naps.map_data'))->assertOk()->json();

        $this->assertCount(1, $datos);
        $this->assertSame('NAP001', $datos[0]['codigo']);
        $this->assertEqualsWithDelta(25.0, $datos[0]['porcentaje'], 0.01);
        $this->assertSame(3, $datos[0]['disponibles']);
    }

    // ==================== Contrato ====================

    /** @test */
    public function desde_el_contrato_se_ocupa_y_se_libera_el_puerto(): void
    {
        $caja = $this->caja();
        $contrato = $this->contrato();
        $puerto = $caja->ports->firstWhere('number', 2);

        $this->put(route('contracts.update', $contrato), [
            'cpe_sn' => 'HWTC1234',
            'nap_port_id' => $puerto->id,
        ])->assertRedirect();

        $this->assertSame($puerto->id, $contrato->fresh()->nap_port_id);

        // Vacío = "sin caja asignada": suelta el puerto
        $this->put(route('contracts.update', $contrato), [
            'cpe_sn' => 'HWTC1234',
            'nap_port_id' => '',
        ])->assertRedirect();

        $this->assertNull($contrato->fresh()->nap_port_id);
    }

    /** @test */
    public function el_contrato_no_puede_ocupar_un_puerto_de_otra_sucursal(): void
    {
        $ajena = $this->cajaDeOtraSucursal();
        $contrato = $this->contrato();

        $this->put(route('contracts.update', $contrato), [
            'cpe_sn' => 'HWTC1234',
            'nap_port_id' => $ajena->ports->first()->id,
        ])->assertForbidden();

        $this->assertNull($contrato->fresh()->nap_port_id);
    }

    /** @test */
    public function el_listado_de_contratos_filtra_por_caja(): void
    {
        $caja = $this->caja();
        $otra = $this->caja();

        $enCaja = $this->contrato();
        $enOtra = $this->contrato();
        $sinCaja = $this->contrato();

        app(OdnManager::class)->asignarPuerto($enCaja, $caja->ports->first());
        app(OdnManager::class)->asignarPuerto($enOtra, $otra->ports->first());

        $filtrados = app(\App\Services\ContractQuery::class)
            ->construir(['nap_box_id' => $caja->id])
            ->pluck('id');

        $this->assertTrue($filtrados->contains($enCaja->id));
        $this->assertFalse($filtrados->contains($enOtra->id));
        $this->assertFalse($filtrados->contains($sinCaja->id));

        // Y los que todavía no están documentados
        $sinDocumentar = app(\App\Services\ContractQuery::class)
            ->construir(['has_nap' => 'no'])
            ->pluck('id');

        $this->assertTrue($sinDocumentar->contains($sinCaja->id));
        $this->assertFalse($sinDocumentar->contains($enCaja->id));
    }

    /** @test */
    public function el_diagnostico_del_contrato_incluye_la_caja(): void
    {
        $caja = $this->caja(['capacity' => 4]);
        $contrato = $this->contrato();

        app(OdnManager::class)->asignarPuerto($contrato, $caja->ports->first());
        app(OdnManager::class)->asignarPuerto($this->contrato(), $caja->ports->firstWhere('number', 2));

        $nap = $this->getJson(route('contracts.diagnostics', $contrato->fresh()))
            ->assertOk()
            ->json('nap');

        $this->assertSame('NAP001', $nap['caja']);
        $this->assertSame(1, $nap['puerto']);
        $this->assertEqualsWithDelta(50.0, $nap['porcentaje'], 0.01);
        // El dato que decide si se manda un técnico
        $this->assertSame(1, $nap['otros_clientes']);
    }

    /**
     * @test
     *
     * Prueba de humo de todas las pantallas del módulo.
     *
     * No comprueba contenido: comprueba que abren. Un error de sintaxis
     * en un Blade no lo ve nadie hasta que alguien entra a esa página, y
     * este módulo tiene once vistas. Con esto, un @endphp mal puesto se
     * cae aquí y no en producción.
     */
    public function todas_las_pantallas_del_modulo_abren(): void
    {
        $caja = $this->caja();
        $contrato = $this->contrato();

        app(OdnManager::class)->asignarPuerto($contrato, $caja->ports->first());

        foreach ([
            route('networks.index'),
            route('networks.create'),
            route('networks.show', $this->red),
            route('networks.edit', $this->red),
            route('naps.index'),
            route('naps.create'),
            route('naps.map'),
            route('naps.show', $caja),
            route('naps.edit', $caja),
            // Las dos pantallas de contratos que ahora tocan el módulo
            route('contracts.index'),
            route('contracts.show', $contrato),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    // ==================== Trazabilidad ====================

    /** @test */
    public function todo_lo_que_pasa_en_la_red_queda_en_la_bitacora(): void
    {
        $caja = $this->caja();
        $contrato = $this->contrato();

        app(OdnManager::class)->asignarPuerto($contrato, $caja->ports->first());
        app(OdnManager::class)->liberarPuerto($contrato->fresh());

        $acciones = \App\Models\Audit::pluck('action');

        $this->assertTrue($acciones->contains('naps.created'));
        $this->assertTrue($acciones->contains('naps.port_assigned'));
        $this->assertTrue($acciones->contains('naps.port_released'));

        // La categoría tiene que ser una de las filtrables del módulo
        $this->assertTrue(
            \App\Models\Audit::where('action', 'naps.port_assigned')
                ->where('category', 'red')
                ->exists(),
        );
    }
}
