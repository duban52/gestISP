<?php

namespace Tests\Feature\TechnicalOrders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\NapBox;
use App\Models\NapPort;
use App\Models\Olt;
use App\Models\OpticalNetwork;
use App\Models\Plan;
use App\Models\PonPort;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OdnManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Traslado de servicio: la caja NAP donde queda el cliente.
 *
 * POR QUÉ EXISTE ESTA PRUEBA
 * --------------------------
 * Un traslado es la única clase de orden que mueve el servicio de una
 * caja a otra, y el único que sabe a cuál es quien estuvo allí. Antes
 * la pantalla del técnico solo mostraba cajas cercanas como sugerencia
 * y el puerto se registraba después, a mano, en la ficha del contrato:
 * entre una cosa y otra se perdía, y la ocupación de las cajas dejaba
 * de coincidir con la realidad justo en las órdenes que la cambian.
 *
 * Lo que se defiende aquí:
 *
 *  1. Que un traslado NO se pueda cerrar sin decir dónde quedó.
 *  2. Que el resto de órdenes no queden bloqueadas por esta regla.
 *  3. Que el puerto viejo se libere y el nuevo quede ocupado.
 *  4. Que un id manipulado no mueva un contrato a otra sucursal.
 *  5. Que se pueda cerrar sin caja cuando no está documentada, pero
 *     nunca en silencio.
 */
class RelocationNapTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $tecnico;
    private Plan $plan;
    private OpticalNetwork $red;
    private PonPort $pon;

    /** PNG 1x1 válido en Data URL, hace de firma en las pruebas. */
    private const FIRMA_DEMO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'TRA']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole($rol);
        $this->tecnico->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        Warehouse::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
            'description' => 'Almacén del técnico',
        ]);

        $this->plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
        ]);

        $this->actingAs($this->tecnico)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        [$this->red, $this->pon] = $this->redConPuertoPon($this->branch);
    }

    // ==================== Andamiaje ====================

    /** @return array{0: OpticalNetwork, 1: PonPort} */
    private function redConPuertoPon(Branch $sucursal): array
    {
        $red = OpticalNetwork::create([
            'branch_id' => $sucursal->id,
            'name' => 'Red ' . $sucursal->id,
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->tecnico->id,
        ]);

        $olt = Olt::create([
            'branch_id' => $sucursal->id,
            'optical_network_id' => $red->id,
            'name' => 'OLT ' . $sucursal->id,
            'ip_address' => '10.0.' . $sucursal->id . '.10',
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

    private function caja(?OpticalNetwork $red = null, ?PonPort $pon = null): NapBox
    {
        return app(OdnManager::class)->crearCaja($red ?? $this->red, [
            'pon_port_id' => ($pon ?? $this->pon)->id,
            'capacity' => 8,
            'address' => 'Calle 19 # 23-46',
            'latitude' => 6.2442,
            'longitude' => -75.5812,
            'status' => NapBox::OPERATIVA,
        ]);
    }

    private function contrato(): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'user_id' => $this->tecnico->id,
        ]);
    }

    private function orden(Contract $contrato, string $detalle = 'Traslado de servicio'): TechnicalOrder
    {
        return TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->tecnico->id,
            'created_by' => $this->tecnico->id,
            'type' => 'Servicio',
            'detail' => $detalle,
            'status' => 'Asignada',
            'initial_comment' => 'El cliente se muda',
        ]);
    }

    private function datosReporte(array $extra = []): array
    {
        return array_merge([
            'observations_technical' => 'Acometida nueva tendida',
            'client_observation' => 'Cliente conforme',
            'solution' => 'Servicio trasladado',
            'client_signature' => self::FIRMA_DEMO,
        ], $extra);
    }

    // ==================== El detalle se reconoce ====================

    /**
     * @dataProvider variantesDelDetalle
     */
    public function test_reconoce_las_variantes_del_detalle(string $detalle, bool $esTraslado): void
    {
        // El detalle llega con y sin tilde y con sufijos. Comparar
        // cadenas dejaría fuera media docena de variantes reales, por
        // eso se resuelve con OrderDetailMap.
        $orden = $this->orden($this->contrato(), $detalle);

        $this->assertSame($esTraslado, $orden->esTraslado());
    }

    public static function variantesDelDetalle(): array
    {
        return [
            ['Traslado de servicio', true],
            ['traslado de servicio', true],
            ['Instalación de servicio', false],
            ['Instalacion de servicio (creación automática)', false],
            ['Sin servicio de internet', false],
        ];
    }

    // ==================== La regla ====================

    public function test_un_traslado_no_se_cierra_sin_decir_donde_quedo(): void
    {
        $orden = $this->orden($this->contrato());

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte())
            ->assertSessionHasErrors('nap_port_id');

        // No avanzó: sigue asignada
        $this->assertSame('Asignada', $orden->fresh()->status);
    }

    public function test_las_demas_ordenes_no_piden_caja(): void
    {
        // La regla no puede bloquear una avería, donde el cliente ya
        // está conectado a una caja concreta.
        $orden = $this->orden($this->contrato(), 'Sin servicio de internet');

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte())
            ->assertSessionHasNoErrors();

        $this->assertSame('Prefinalizada', $orden->fresh()->status);
    }

    public function test_el_traslado_ocupa_el_puerto_nuevo_y_libera_el_viejo(): void
    {
        $contrato = $this->contrato();

        $cajaVieja = $this->caja();
        $puertoViejo = $cajaVieja->ports()->orderBy('number')->first();
        app(OdnManager::class)->asignarPuerto($contrato, $puertoViejo);

        $cajaNueva = $this->caja();
        $puertoNuevo = $cajaNueva->ports()->orderBy('number')->first();

        $orden = $this->orden($contrato);

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'nap_port_id' => $puertoNuevo->id,
        ]))->assertSessionHasNoErrors();

        $contrato->refresh();

        $this->assertSame($puertoNuevo->id, $contrato->nap_port_id);
        $this->assertSame(
            $cajaNueva->code . ' / P' . $puertoNuevo->number,
            $contrato->nap_port,
        );

        // La ocupación NO se guarda: se deduce de qué contrato apunta
        // al puerto. El viejo debe quedar libre solo.
        $this->assertFalse($puertoViejo->fresh()->estaOcupado());
        $this->assertTrue($puertoNuevo->fresh()->estaOcupado());
    }

    public function test_el_traslado_queda_en_la_trazabilidad(): void
    {
        $contrato = $this->contrato();
        $puerto = $this->caja()->ports()->orderBy('number')->first();

        $this->post(
            route('technicals_orders.process', $this->orden($contrato)->id),
            $this->datosReporte(['nap_port_id' => $puerto->id]),
        );

        $this->assertDatabaseHas('audits', ['action' => 'naps.port_assigned']);
    }

    // ==================== Sin caja documentada ====================

    public function test_se_puede_cerrar_sin_caja_explicando_por_que(): void
    {
        // El técnico está en la calle: si la caja no está en el sistema
        // no puede inventársela, pero tampoco puede quedarse con la
        // orden abierta.
        $orden = $this->orden($this->contrato());

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'nap_no_registrada' => '1',
            'nap_motivo' => 'Caja nueva sin documentar en la 20 con 15',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Prefinalizada', $orden->fresh()->status);
    }

    public function test_sin_caja_hay_que_explicar_por_que(): void
    {
        $orden = $this->orden($this->contrato());

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'nap_no_registrada' => '1',
        ]))->assertSessionHasErrors('nap_motivo');
    }

    public function test_cerrar_sin_caja_no_pasa_en_silencio(): void
    {
        // Si esto no quedara registrado, el servicio se quedaría sin
        // caja y nadie se enteraría nunca.
        $contrato = $this->contrato();

        $this->post(
            route('technicals_orders.process', $this->orden($contrato)->id),
            $this->datosReporte([
                'nap_no_registrada' => '1',
                'nap_motivo' => 'Caja nueva sin documentar en la 20 con 15',
            ]),
        );

        $this->assertDatabaseHas('audits', ['action' => 'naps.port_pending']);

        $registro = \App\Models\Audit::where('action', 'naps.port_pending')->firstOrFail();

        $this->assertStringContainsString('sin documentar', $registro->description);
    }

    // ==================== Aislamiento entre sucursales ====================

    public function test_no_se_puede_mover_a_una_caja_de_otra_sucursal(): void
    {
        $otra = Branch::factory()->create(['contract_prefix' => 'OTR']);
        [$redAjena, $ponAjeno] = $this->redConPuertoPon($otra);

        $puertoAjeno = $this->caja($redAjena, $ponAjeno)
            ->ports()->orderBy('number')->first();

        $contrato = $this->contrato();
        $orden = $this->orden($contrato);

        // Un id manipulado desde el navegador no puede mover el
        // contrato a la caja de otra sede.
        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'nap_port_id' => $puertoAjeno->id,
        ]));

        $this->assertNull($contrato->fresh()->nap_port_id);
        $this->assertSame('Asignada', $orden->fresh()->status);
    }

    // ==================== La pantalla ====================

    public function test_la_pantalla_del_traslado_ofrece_las_cajas(): void
    {
        $this->caja();
        $orden = $this->orden($this->contrato());

        $this->get(route('technicals_orders.show', $orden->id))
            ->assertOk()
            ->assertSee('Caja NAP donde queda el servicio')
            ->assertSee('nap_port_id', false)
            ->assertSee('napNoRegistrada', false);
    }

    public function test_las_demas_ordenes_no_muestran_el_bloque(): void
    {
        $this->caja();
        $orden = $this->orden($this->contrato(), 'Sin servicio de internet');

        $this->get(route('technicals_orders.show', $orden->id))
            ->assertOk()
            ->assertDontSee('Caja NAP donde queda el servicio');
    }

    // ==================== Liberar el puerto viejo ====================

    public function test_avisa_al_tecnico_que_libere_el_puerto_viejo(): void
    {
        // El puerto ya figura libre en el sistema, pero en la caja
        // sigue puesta la acometida. Si nadie va a quitarla, el
        // proximo cliente se encuentra el puerto ocupado de verdad.
        $contrato = $this->contrato();

        $cajaVieja = $this->caja();
        $puertoViejo = $cajaVieja->ports()->orderBy('number')->first();
        app(OdnManager::class)->asignarPuerto($contrato, $puertoViejo);

        $puertoNuevo = $this->caja()->ports()->orderBy('number')->first();

        $respuesta = $this->post(
            route('technicals_orders.process', $this->orden($contrato)->id),
            $this->datosReporte(['nap_port_id' => $puertoNuevo->id]),
        );

        $respuesta->assertSessionHas('nap_liberar');

        $aviso = session('nap_liberar');

        $this->assertSame($cajaVieja->code, $aviso['caja']);
        $this->assertSame($puertoViejo->number, $aviso['puerto']);
        // La direccion va incluida: el aviso sale cuando el tecnico ya
        // cerro y puede estar a varias cuadras.
        $this->assertSame($cajaVieja->address, $aviso['direccion']);
    }

    public function test_el_aviso_se_ve_en_la_bandeja_del_tecnico(): void
    {
        $contrato = $this->contrato();
        $cajaVieja = $this->caja();
        app(OdnManager::class)->asignarPuerto(
            $contrato,
            $cajaVieja->ports()->orderBy('number')->first(),
        );

        $puertoNuevo = $this->caja()->ports()->orderBy('number')->first();

        $this->post(
            route('technicals_orders.process', $this->orden($contrato)->id),
            $this->datosReporte(['nap_port_id' => $puertoNuevo->id]),
        );

        $this->get(route('technicals_orders.my_technical_orders'))
            ->assertOk()
            ->assertSee('Falta liberar el puerto donde estaba el cliente')
            ->assertSee($cajaVieja->code);
    }

    public function test_sin_puerto_anterior_no_hay_nada_que_avisar(): void
    {
        // Un contrato que nunca tuvo caja registrada no deja nada que
        // desconectar: el aviso seria ruido.
        $contrato = $this->contrato();
        $puertoNuevo = $this->caja()->ports()->orderBy('number')->first();

        $this->post(
            route('technicals_orders.process', $this->orden($contrato)->id),
            $this->datosReporte(['nap_port_id' => $puertoNuevo->id]),
        )->assertSessionMissing('nap_liberar');
    }

    public function test_confirmar_el_mismo_puerto_no_avisa(): void
    {
        // Pasa al cerrar una orden devuelta: el tecnico confirma el
        // puerto que ya estaba. No hay nada que desconectar.
        $contrato = $this->contrato();
        $puerto = $this->caja()->ports()->orderBy('number')->first();
        app(OdnManager::class)->asignarPuerto($contrato, $puerto);

        $this->post(
            route('technicals_orders.process', $this->orden($contrato)->id),
            $this->datosReporte(['nap_port_id' => $puerto->id]),
        )->assertSessionMissing('nap_liberar');

        $this->assertSame($puerto->id, $contrato->fresh()->nap_port_id);
    }

    public function test_sin_caja_registrada_tambien_suelta_el_puerto_viejo(): void
    {
        // El cliente ya no esta ahi aunque no sepamos donde quedo.
        // Dejarlo apuntando al puerto viejo lo bloquearia para
        // siempre: la caja diria que lo ocupa alguien que se mudo.
        $contrato = $this->contrato();
        $cajaVieja = $this->caja();
        $puertoViejo = $cajaVieja->ports()->orderBy('number')->first();
        app(OdnManager::class)->asignarPuerto($contrato, $puertoViejo);

        $respuesta = $this->post(
            route('technicals_orders.process', $this->orden($contrato)->id),
            $this->datosReporte([
                'nap_no_registrada' => '1',
                'nap_motivo' => 'Caja nueva sin documentar en la 20 con 15',
            ]),
        );

        $contrato->refresh();

        $this->assertNull($contrato->nap_port_id);
        $this->assertFalse($puertoViejo->fresh()->estaOcupado());
        $respuesta->assertSessionHas('nap_liberar');

        // Y queda constancia de las dos cosas: por que no se registro
        // la caja nueva, y que se solto la vieja.
        $this->assertDatabaseHas('audits', ['action' => 'naps.port_pending']);
        $this->assertDatabaseHas('audits', ['action' => 'naps.port_released']);
    }

    public function test_liberar_limpia_tambien_el_texto_legible(): void
    {
        // contracts.nap_port es el texto que ve quien abre la ficha sin
        // entrar al modulo de redes. liberarPuerto solo borraba el id,
        // asi que la ficha seguia mostrando la caja vieja de un
        // contrato que ya no ocupaba ningun puerto.
        $contrato = $this->contrato();
        app(OdnManager::class)->asignarPuerto(
            $contrato,
            $this->caja()->ports()->orderBy('number')->first(),
        );

        $this->assertNotNull($contrato->fresh()->nap_port);

        app(OdnManager::class)->liberarPuerto($contrato->fresh());

        $contrato->refresh();

        $this->assertNull($contrato->nap_port_id);
        $this->assertNull($contrato->nap_port);
    }
}
