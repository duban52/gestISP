<?php

namespace Tests\Feature\Orders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ubicar el predio al cerrar la orden.
 *
 * EL HUECO QUE LLENA
 * ------------------
 * Un contrato sin punto en el mapa no puede sugerir la caja NAP más
 * cercana ni comprobar que sus órdenes se cierran donde está el
 * servicio. Y la única ocasión en que alguien está en la vivienda del
 * cliente con un teléfono capaz de dar coordenadas es, precisamente,
 * cuando se instala o se traslada el servicio.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 *  1. Que se OFREZCA solo donde tiene sentido: instalación o
 *     traslado, y con el contrato sin ubicar. En una incidencia el
 *     técnico puede estar en el poste, y en una administrativa no hay
 *     nadie en casa de nadie.
 *  2. Que se PREGUNTE y no se asuma. Cerrar la orden sin marcar la
 *     casilla no toca el contrato.
 *  3. Que NO PISE una ubicación que ya exista. La puso alguien sobre
 *     el mapa mirando, con más criterio que un GPS bajo un alero.
 *  4. Que la casilla sola no baste: las condiciones se comprueban
 *     contra la base, porque lo que llega del navegador no es de fiar
 *     y un punto equivocado no se nota — se usa para sugerir cajas y
 *     para auditar cierres, y las dos cosas empiezan a mentir en
 *     silencio.
 */
class UbicarPredioAlCerrarTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $tecnico;

    /** Un PNG de 1x1: la firma no es lo que se prueba aquí. */
    private const FIRMA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Coordenadas de la vivienda, tomadas por el técnico. */
    private const LAT = 6.2518400;
    private const LON = -75.5635900;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->tecnico = User::factory()->create(['number_phone' => '3000000000']);
        $this->tecnico->assignRole($rol);
        $this->tecnico->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        // El cierre exige que el técnico tenga almacén propio, incluso
        // en órdenes que no consumen material.
        \App\Models\Warehouse::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
            'description' => 'Almacén del técnico',
        ]);

        $this->actingAs($this->tecnico)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);
    }

    private function contrato(bool $ubicado = false): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
            'number_phone' => '3111111111',
        ]);

        $plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->tecnico->id,
            'latitude' => $ubicado ? 1.0 : null,
            'longitude' => $ubicado ? 1.0 : null,
        ]);
    }

    private function orden(Contract $contrato, string $detalle): TechnicalOrder
    {
        return TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->tecnico->id,
            'user_assigned' => $this->tecnico->id,
            'type' => TechnicalOrder::SERVICIO,
            'status' => 'Asignada',
            'detail' => $detalle,
            'initial_comment' => 'x',
        ]);
    }

    // ==================== Cuándo se ofrece ====================

    /** @dataProvider trabajosEnLaVivienda */
    public function test_se_ofrece_en_instalacion_y_traslado(string $detalle): void
    {
        $orden = $this->orden($this->contrato(), $detalle);

        $this->assertTrue($orden->puedeUbicarElPredio());
    }

    public static function trabajosEnLaVivienda(): array
    {
        return [
            'instalación' => ['Instalación de servicio'],
            'traslado' => ['Traslado de servicio'],
            // Sin tilde y con otra caja: OrderDetailMap normaliza, y
            // comparar textos a pelo dejaría fuera media docena de
            // variantes reales.
            'instalación sin tilde' => ['Instalacion de Servicio'],
        ];
    }

    /** @dataProvider trabajosQueNoSonEnLaVivienda */
    public function test_no_se_ofrece_en_los_demas_trabajos(string $detalle): void
    {
        // En una incidencia el técnico puede estar en la caja o en el
        // poste; guardar ESO como la casa del cliente es peor que no
        // guardar nada.
        $orden = $this->orden($this->contrato(), $detalle);

        $this->assertFalse($orden->puedeUbicarElPredio());
    }

    public static function trabajosQueNoSonEnLaVivienda(): array
    {
        return [
            'sin internet' => ['Sin servicio de internet'],
            'configuraciones' => ['Configuraciones'],
            'retiro' => ['Retiro de servicio'],
        ];
    }

    public function test_no_se_ofrece_si_el_contrato_ya_esta_ubicado(): void
    {
        $orden = $this->orden($this->contrato(ubicado: true), 'Instalación de servicio');

        $this->assertFalse($orden->puedeUbicarElPredio());
    }

    public function test_la_pantalla_ensena_la_pregunta_solo_cuando_procede(): void
    {
        $conHueco = $this->orden($this->contrato(), 'Instalación de servicio');
        $yaUbicado = $this->orden($this->contrato(ubicado: true), 'Instalación de servicio');

        $this->get(route('technicals_orders.show', $conHueco))
            ->assertOk()
            ->assertSee('ubicacion_predio', escape: false);

        $this->get(route('technicals_orders.show', $yaUbicado))
            ->assertOk()
            ->assertDontSee('ubicacion_predio', escape: false);
    }

    // ==================== Qué pasa al cerrar ====================

    /** @param  array<string, mixed>  $extra */
    private function cerrar(TechnicalOrder $orden, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('technicals_orders.process', $orden), array_merge([
            'observations_technical' => 'Trabajo realizado',
            'client_observation' => 'Cliente conforme',
            'solution' => 'Instalado',
            'client_signature' => self::FIRMA,
            'closing_latitude' => self::LAT,
            'closing_longitude' => self::LON,
            'closing_accuracy_m' => 12,
            // Un traslado exige decir donde quedo el servicio.
            'nap_no_registrada' => 1,
            'nap_motivo' => 'Caja sin registrar todavia',
        ], $extra));
    }

    /**
     * Cierra la orden Y COMPRUEBA QUE SE CERRÓ.
     *
     * Sin esto las pruebas mienten. Las que verifican el efecto
     * —«el contrato quedó ubicado»— fallan con un «false no es true»
     * que no dice nada cuando el cierre se cayó antes; y las que
     * verifican la ausencia de efecto —«el contrato NO cambió»— dan
     * VERDE por el motivo equivocado. Las dos cosas pasaron aquí.
     *
     * Se mira el estado de la orden y no la bolsa de errores: el
     * controlador rechaza con `back()->with('error')`, que es un flash
     * y NO un error de validación, así que `assertSessionHasNoErrors()`
     * lo deja pasar tan tranquilo. Ese despiste costó dos vueltas.
     */
    private function cerrarDeVerdad(TechnicalOrder $orden, array $extra = []): void
    {
        $this->cerrar($orden, $extra);

        $this->assertSame(
            'Prefinalizada',
            $orden->refresh()->status,
            'La orden no llegó a cerrarse: ' . (session('error') ?: 'sin motivo en sesión'),
        );
    }

    /**
     * Los cierres se prueban con un TRASLADO y no con una instalacion.
     *
     * No es por comodidad: una instalacion exige material reportado
     * (ver requiresMaterial), y montar almacen y existencias aqui
     * probaria el modulo de inventario, no esto. El traslado es el
     * OTRO caso que el requisito cubre y recorre exactamente el mismo
     * camino de ubicacion.
     */
    private const TRABAJO_EN_SITIO = 'Traslado de servicio';

    public function test_marcando_la_casilla_el_contrato_queda_ubicado(): void
    {
        $contrato = $this->contrato();
        $orden = $this->orden($contrato, self::TRABAJO_EN_SITIO);

        $this->cerrarDeVerdad($orden, ['ubicacion_predio' => 'cierre']);

        $contrato->refresh();

        $this->assertTrue($contrato->isGeolocated());
        $this->assertSame(self::LAT, (float) $contrato->latitude);
        $this->assertSame(self::LON, (float) $contrato->longitude);
        $this->assertSame(Contract::LOCATION_SOURCE_ORDER, $contrato->location_source);
    }

    public function test_sin_marcarla_el_contrato_se_queda_como_estaba(): void
    {
        // La otra mitad de lo pedido: tiene que poder decirse que no.
        $contrato = $this->contrato();
        $orden = $this->orden($contrato, self::TRABAJO_EN_SITIO);

        $this->cerrarDeVerdad($orden);

        $this->assertFalse($contrato->refresh()->isGeolocated());
    }

    public function test_la_casilla_no_basta_en_una_orden_que_no_corresponde(): void
    {
        // Mandarla a mano en una incidencia no puede ubicar el predio:
        // la vista no la pinta ahí, pero una petición sí puede traerla.
        $contrato = $this->contrato();
        $orden = $this->orden($contrato, 'Sin servicio de internet');

        $this->cerrarDeVerdad($orden, ['ubicacion_predio' => 'cierre']);

        $this->assertFalse($contrato->refresh()->isGeolocated());
    }

    public function test_no_pisa_una_ubicacion_que_ya_existia(): void
    {
        // La puso alguien sobre el mapa, mirando. Gana ella.
        $contrato = $this->contrato(ubicado: true);
        $orden = $this->orden($contrato, self::TRABAJO_EN_SITIO);

        $this->cerrarDeVerdad($orden, ['ubicacion_predio' => 'cierre']);

        $contrato->refresh();

        $this->assertSame(1.0, (float) $contrato->latitude);
    }

    public function test_sin_ubicacion_de_cierre_no_se_inventa_nada(): void
    {
        // El GPS falló pero la casilla llegó marcada: no hay punto que
        // guardar, y el cierre no puede caerse por eso.
        $contrato = $this->contrato();
        $orden = $this->orden($contrato, self::TRABAJO_EN_SITIO);

        $this->cerrarDeVerdad($orden, [
            'ubicacion_predio' => 'cierre',
            'closing_latitude' => null,
            'closing_longitude' => null,
            'closing_accuracy_m' => null,
            'closing_location_error' => 'Permiso denegado',
        ]);

        $this->assertFalse($contrato->refresh()->isGeolocated());
        $this->assertSame('Prefinalizada', $orden->refresh()->status);
    }

    // ==================== Marcarla a mano en el mapa ====================

    /**
     * La tercera opción: el técnico dice dónde está la vivienda.
     *
     * Existe porque el GPS no siempre sirve —poca señal, un edificio
     * donde el punto cae en el vecino, una orden que se cierra desde
     * la calle—. Quien estuvo allí sabe corregirlo mejor que el
     * teléfono, y sin esta opción su único recurso era no ubicar.
     */
    public function test_se_puede_marcar_el_predio_a_mano(): void
    {
        $contrato = $this->contrato();
        $orden = $this->orden($contrato, self::TRABAJO_EN_SITIO);

        $this->cerrarDeVerdad($orden, [
            'ubicacion_predio' => 'mapa',
            'predio_latitude' => 6.3000000,
            'predio_longitude' => -75.6000000,
        ]);

        $contrato->refresh();

        $this->assertTrue($contrato->isGeolocated());
        $this->assertSame(6.3, (float) $contrato->latitude);
        $this->assertSame(-75.6, (float) $contrato->longitude);
    }

    public function test_el_punto_marcado_a_mano_manda_sobre_el_del_cierre(): void
    {
        // Si eligió marcarlo, es porque el del cierre no le servía.
        $contrato = $this->contrato();
        $orden = $this->orden($contrato, self::TRABAJO_EN_SITIO);

        $this->cerrarDeVerdad($orden, [
            'ubicacion_predio' => 'mapa',
            'predio_latitude' => 6.3000000,
            'predio_longitude' => -75.6000000,
        ]);

        $this->assertSame(6.3, (float) $contrato->refresh()->latitude);
        $this->assertNotSame(self::LAT, (float) $contrato->latitude);
    }

    public function test_elegir_el_mapa_sin_marcar_punto_se_rechaza(): void
    {
        // Mejor un mensaje que un cierre que dice haber ubicado algo
        // y no ubicó nada.
        $contrato = $this->contrato();
        $orden = $this->orden($contrato, self::TRABAJO_EN_SITIO);

        $this->cerrar($orden, ['ubicacion_predio' => 'mapa'])
            ->assertSessionHasErrors('predio_latitude');

        $this->assertFalse($contrato->refresh()->isGeolocated());
    }
}
