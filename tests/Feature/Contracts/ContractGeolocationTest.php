<?php

namespace Tests\Feature\Contracts;

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
use App\Services\NapFinder;
use App\Services\OdnManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Georreferenciación del servicio.
 *
 * QUÉ SE PROTEGE AQUÍ
 * -------------------
 * Una coordenada equivocada es peor que ninguna: manda al técnico a la
 * casa de otro y hace que las órdenes bien cerradas parezcan
 * sospechosas. Por eso las pruebas se centran en lo que impide que
 * entre basura —el (0,0) del Atlántico que devuelven los GPS cuando no
 * fijan posición, y los contratos de otra sucursal— y en que quede
 * anotado quién puso el punto.
 *
 * La otra mitad es la sugerencia de caja NAP: el valor está en que el
 * puerto que propone esté DE VERDAD libre, así que se comprueba que
 * salte los ocupados y los dañados en vez de contar puertos.
 */
class ContractGeolocationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Plan $plan;
    private OpticalNetwork $red;
    private PonPort $pon;

    /** Parque del Poblado, Medellín: sirve de referencia para todo. */
    private const LATITUD = 6.2100000;
    private const LONGITUD = -75.5700000;

    protected function setUp(): void
    {
        parent::setUp();

        // El alta de contrato manda la bienvenida al cliente: aquí no
        // se prueba eso y no puede salir de verdad.
        Notification::fake();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'GEO']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create();
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
            'name' => 'Red sur',
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'optical_network_id' => $this->red->id,
            'name' => 'OLT sur',
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

    private function contrato(?int $branchId = null): Contract
    {
        $branchId ??= $this->branch->id;

        $cliente = Client::factory()->create([
            'branch_id' => $branchId,
            'user_id' => $this->admin->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $branchId,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'user_id' => $this->admin->id,
        ]);
    }

    /**
     * Crea una caja a un desplazamiento dado del punto de referencia.
     *
     * 0.001 grados de latitud son ~111 m, que es la escala en la que se
     * mueve todo esto.
     */
    private function caja(float $desplazamiento, array $datos = []): NapBox
    {
        return app(OdnManager::class)->crearCaja($this->red, array_merge([
            'pon_port_id' => $this->pon->id,
            'capacity' => 4,
            'latitude' => self::LATITUD + $desplazamiento,
            'longitude' => self::LONGITUD,
            'status' => NapBox::OPERATIVA,
        ], $datos));
    }

    // ==================== Fijar el punto ====================

    public function test_ubicar_un_contrato_guarda_el_punto_con_su_origen_y_su_autor(): void
    {
        $contrato = $this->contrato();

        $respuesta = $this->put(route('contracts.location.update', $contrato), [
            'latitude' => self::LATITUD,
            'longitude' => self::LONGITUD,
            'location_source' => 'dispositivo',
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        $contrato->refresh();

        $this->assertTrue($contrato->isGeolocated());
        $this->assertEqualsWithDelta(self::LATITUD, (float) $contrato->latitude, 0.0000001);
        $this->assertEqualsWithDelta(self::LONGITUD, (float) $contrato->longitude, 0.0000001);
        $this->assertSame(Contract::LOCATION_SOURCE_DEVICE, $contrato->location_source);
        $this->assertSame($this->admin->id, $contrato->located_by);
        $this->assertNotNull($contrato->located_at);
    }

    public function test_ubicar_un_contrato_queda_en_la_trazabilidad(): void
    {
        $contrato = $this->contrato();

        $this->put(route('contracts.location.update', $contrato), [
            'latitude' => self::LATITUD,
            'longitude' => self::LONGITUD,
        ]);

        // La entrada legible, la que se puede buscar meses después
        $this->assertDatabaseHas('audits', [
            'action' => 'contracts.located',
            'auditable_type' => Contract::class,
            'auditable_id' => $contrato->id,
            'category' => 'contratos',
        ]);
    }

    public function test_el_punto_nulo_del_atlantico_se_rechaza(): void
    {
        $contrato = $this->contrato();

        // (0,0) es lo que devuelven algunos dispositivos cuando
        // responden sin haber conseguido posición.
        $respuesta = $this->put(route('contracts.location.update', $contrato), [
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertFalse($contrato->fresh()->isGeolocated());
    }

    public function test_no_se_puede_ubicar_un_contrato_de_otra_sucursal(): void
    {
        $otraSucursal = Branch::factory()->create();
        $ajeno = $this->contrato($otraSucursal->id);

        $this->put(route('contracts.location.update', $ajeno), [
            'latitude' => self::LATITUD,
            'longitude' => self::LONGITUD,
        ])->assertForbidden();

        $this->assertFalse($ajeno->fresh()->isGeolocated());
    }

    public function test_se_puede_quitar_una_ubicacion_mal_puesta(): void
    {
        $contrato = $this->contrato();

        $this->put(route('contracts.location.update', $contrato), [
            'latitude' => self::LATITUD,
            'longitude' => self::LONGITUD,
        ]);

        // Enviar las dos coordenadas vacías es la forma de decir "esto
        // estaba mal": mejor sin punto que con uno falso.
        $this->put(route('contracts.location.update', $contrato), [
            'latitude' => '',
            'longitude' => '',
        ])->assertSessionHas('success');

        $contrato->refresh();

        $this->assertFalse($contrato->isGeolocated());
        $this->assertNull($contrato->located_at);
        $this->assertNull($contrato->location_source);
    }

    public function test_una_latitud_sin_su_longitud_no_pasa(): void
    {
        $contrato = $this->contrato();

        $this->put(route('contracts.location.update', $contrato), [
            'latitude' => self::LATITUD,
        ])->assertSessionHasErrors('longitude');

        $this->assertFalse($contrato->fresh()->isGeolocated());
    }

    // ==================== Alta con ubicación ====================

    public function test_el_alta_de_contrato_guarda_el_punto_marcado_en_el_formulario(): void
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $respuesta = $this->post(route('contracts.store'), [
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'department' => 'Antioquia',
            'municipality' => 'Medellín',
            'neighborhood' => 'El Poblado',
            'address' => 'Calle 10 # 43-20',
            'home_type' => 'Propia',
            'social_stratum' => '4',
            'latitude' => self::LATITUD,
            'longitude' => self::LONGITUD,
            'location_source' => 'mapa',
        ]);

        $respuesta->assertRedirect(route('contracts.index'));

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertTrue($contrato->isGeolocated());
        $this->assertSame(Contract::LOCATION_SOURCE_MAP, $contrato->location_source);
        $this->assertSame($this->admin->id, $contrato->located_by);
    }

    public function test_una_coordenada_imposible_no_impide_dar_de_alta_el_contrato(): void
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $respuesta = $this->post(route('contracts.store'), [
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'department' => 'Antioquia',
            'municipality' => 'Medellín',
            'neighborhood' => 'El Poblado',
            'address' => 'Calle 10 # 43-20',
            'home_type' => 'Propia',
            'social_stratum' => '4',
            // El punto nulo del Atlántico: se descarta, pero el
            // contrato tiene que quedar creado igual.
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $respuesta->assertRedirect(route('contracts.index'));

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertFalse($contrato->isGeolocated());
        $this->assertNotNull($contrato->contract_number);
    }

    // ==================== Sugerencia de caja NAP ====================

    public function test_se_sugiere_la_caja_mas_cercana_con_su_siguiente_puerto_libre(): void
    {
        $contrato = $this->contrato();
        $contrato->update(['latitude' => self::LATITUD, 'longitude' => self::LONGITUD]);

        $lejana = $this->caja(0.004);   // ~440 m
        $cercana = $this->caja(0.0005); // ~55 m

        $sugerencias = app(NapFinder::class)->forContract($contrato->fresh());

        $this->assertCount(2, $sugerencias);
        $this->assertSame($cercana->code, $sugerencias->first()->napBox->code);
        $this->assertSame($lejana->code, $sugerencias->last()->napBox->code);

        // Caja recién creada: el primero de sus puertos
        $this->assertSame(1, $sugerencias->first()->nextFreePort->number);
        $this->assertLessThan(100, $sugerencias->first()->distanceM);
    }

    public function test_el_puerto_sugerido_salta_los_ocupados_y_los_danados(): void
    {
        $contrato = $this->contrato();
        $contrato->update(['latitude' => self::LATITUD, 'longitude' => self::LONGITUD]);

        $caja = $this->caja(0.0005);

        // El 1 lo ocupa otro cliente y el 2 está quemado: el sistema
        // debe proponer el 3, no "hay 3 libres".
        $vecino = $this->contrato();
        $caja->ports()->where('number', 1)->first()->contract()->save($vecino);
        $caja->ports()->where('number', 2)->update(['status' => NapPort::DANADO]);

        $sugerencia = app(NapFinder::class)->forContract($contrato->fresh())->first();

        $this->assertSame(3, $sugerencia->nextFreePort->number);
        $this->assertSame(2, $sugerencia->freePorts);
        $this->assertSame($caja->code . ' / P3', $sugerencia->portLabel());
    }

    public function test_una_caja_llena_se_sugiere_pero_sin_puerto(): void
    {
        $contrato = $this->contrato();
        $contrato->update(['latitude' => self::LATITUD, 'longitude' => self::LONGITUD]);

        $caja = $this->caja(0.0005, ['capacity' => 1]);
        $caja->ports()->first()->contract()->save($this->contrato());

        $sugerencia = app(NapFinder::class)->forContract($contrato->fresh())->first();

        // Se sigue mostrando: saber que la caja de al lado está llena
        // es justo lo que dice que hay que ampliar la red ahí.
        $this->assertNotNull($sugerencia);
        $this->assertFalse($sugerencia->hasRoom());
        $this->assertNull($sugerencia->portLabel());
    }

    public function test_una_caja_en_mantenimiento_no_se_sugiere(): void
    {
        $contrato = $this->contrato();
        $contrato->update(['latitude' => self::LATITUD, 'longitude' => self::LONGITUD]);

        $this->caja(0.0005, ['status' => NapBox::MANTENIMIENTO]);

        $this->assertTrue(app(NapFinder::class)->forContract($contrato->fresh())->isEmpty());
    }

    public function test_un_contrato_sin_ubicar_no_recibe_sugerencias_inventadas(): void
    {
        $contrato = $this->contrato();
        $this->caja(0.0005);

        $this->assertTrue(app(NapFinder::class)->forContract($contrato)->isEmpty());

        $respuesta = $this->getJson(route('contracts.nearby_naps', $contrato));

        $respuesta->assertOk();
        $respuesta->assertJson(['georreferenciado' => false, 'sugerencias' => []]);
    }

    public function test_las_cajas_cercanas_llegan_a_la_ficha_con_el_puerto_propuesto(): void
    {
        $contrato = $this->contrato();
        $contrato->update(['latitude' => self::LATITUD, 'longitude' => self::LONGITUD]);

        $caja = $this->caja(0.0005);

        $respuesta = $this->getJson(route('contracts.nearby_naps', $contrato));

        $respuesta->assertOk();
        $respuesta->assertJsonPath('georreferenciado', true);
        $respuesta->assertJsonPath('sugerencias.0.caja', $caja->code);
        $respuesta->assertJsonPath('sugerencias.0.puerto_sugerido', 1);
    }

    // ==================== Listado ====================

    public function test_el_listado_sabe_separar_los_contratos_sin_ubicar(): void
    {
        $ubicado = $this->contrato();
        $ubicado->update(['latitude' => self::LATITUD, 'longitude' => self::LONGITUD]);

        $sinUbicar = $this->contrato();

        $consulta = app(\App\Services\ContractQuery::class);

        $pendientes = $consulta->construir(['has_location' => 'no'])->pluck('id');
        $hechos = $consulta->construir(['has_location' => 'si'])->pluck('id');

        $this->assertTrue($pendientes->contains($sinUbicar->id));
        $this->assertFalse($pendientes->contains($ubicado->id));

        $this->assertTrue($hechos->contains($ubicado->id));
        $this->assertFalse($hechos->contains($sinUbicar->id));
    }
}
