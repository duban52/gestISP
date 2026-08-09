<?php

namespace Tests\Feature\TechnicalOrders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\TechnicalOrderRejectedTechnician;
use App\Support\OrderLocationCheck;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ubicación del cierre de las órdenes técnicas.
 *
 * QUÉ SE PROTEGE AQUÍ
 * -------------------
 * Dos cosas que tiran en direcciones opuestas y que es fácil romper al
 * tocar una de ellas:
 *
 *  1. Que el dato se capture. Sin él no hay forma de distinguir un
 *     trabajo hecho en casa del cliente de uno cerrado desde el sofá.
 *
 *  2. Que NUNCA bloquee el cierre. Un permiso denegado o un sótano sin
 *     señal no pueden dejar a un técnico con el trabajo hecho y la
 *     orden abierta. Cuando falla se guarda el motivo, y esa es la
 *     diferencia entre "no se pudo" y "no se quiso".
 *
 * El veredicto se comprueba aparte porque tiene la trampa de siempre:
 * descuenta el margen de error del GPS antes de juzgar, y si alguien
 * quita ese descuento el indicador se pone rojo en media empresa y
 * deja de mirarse.
 */
class OrderLocationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $tecnico;
    private Plan $plan;

    /** Punto de la vivienda del cliente en todas las pruebas. */
    private const LATITUD = 6.2100000;
    private const LONGITUD = -75.5700000;

    /** PNG 1x1 válido en Data URL, hace de firma. */
    private const FIRMA_DEMO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ORD']);
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
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);
    }

    /**
     * Orden lista para procesar.
     *
     * El detalle por defecto NO es una instalación: esas exigen
     * material y aquí lo que se prueba es la ubicación.
     */
    private function orden(bool $contratoUbicado = true, string $detalle = 'Configuraciones'): TechnicalOrder
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'user_id' => $this->tecnico->id,
            'latitude' => $contratoUbicado ? self::LATITUD : null,
            'longitude' => $contratoUbicado ? self::LONGITUD : null,
        ]);

        return TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->tecnico->id,
            'created_by' => $this->tecnico->id,
            'type' => 'Servicio',
            'detail' => $detalle,
            'status' => 'Asignada',
            'initial_comment' => 'Orden de prueba',
        ]);
    }

    private function datosReporte(array $extra = []): array
    {
        return array_merge([
            'observations_technical' => 'Todo en orden',
            'client_observation' => 'Cliente conforme',
            'solution' => 'Servicio revisado',
            'client_signature' => self::FIRMA_DEMO,
        ], $extra);
    }

    // ==================== Captura ====================

    public function test_al_procesar_se_guarda_donde_estaba_el_tecnico(): void
    {
        $orden = $this->orden();

        $respuesta = $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'closing_latitude' => self::LATITUD,
            'closing_longitude' => self::LONGITUD,
            'closing_accuracy_m' => 12,
        ]));

        $respuesta->assertRedirect(route('technicals_orders.my_technical_orders'));

        $orden->refresh();

        $this->assertTrue($orden->hasClosingLocation());
        $this->assertSame(12, $orden->closing_accuracy_m);
        $this->assertNotNull($orden->closing_located_at);
        $this->assertNull($orden->closing_location_error);
        $this->assertSame('Prefinalizada', $orden->status);
    }

    public function test_sin_ubicacion_la_orden_se_cierra_igual_y_queda_el_motivo(): void
    {
        $orden = $this->orden();

        $respuesta = $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'closing_location_error' => 'Permiso de ubicación denegado en el navegador',
        ]));

        // Lo importante: el trabajo hecho no se queda sin cerrar
        $respuesta->assertRedirect(route('technicals_orders.my_technical_orders'));
        $respuesta->assertSessionHas('success');

        $orden->refresh();

        $this->assertSame('Prefinalizada', $orden->status);
        $this->assertFalse($orden->hasClosingLocation());
        $this->assertSame('Permiso de ubicación denegado en el navegador', $orden->closing_location_error);
    }

    public function test_el_punto_nulo_del_atlantico_se_descarta(): void
    {
        $orden = $this->orden();

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'closing_latitude' => 0,
            'closing_longitude' => 0,
        ]));

        $orden->refresh();

        // Guardarlo pondría todas las órdenes a diez mil kilómetros del
        // cliente y dejaría el indicador en rojo permanente.
        $this->assertFalse($orden->hasClosingLocation());
        $this->assertNotNull($orden->closing_location_error);
    }

    public function test_el_cierre_queda_en_la_trazabilidad_con_su_distancia(): void
    {
        $orden = $this->orden();

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'closing_latitude' => self::LATITUD,
            'closing_longitude' => self::LONGITUD,
        ]));

        $this->assertDatabaseHas('audits', [
            'action' => 'technical_orders.closed',
            'auditable_type' => TechnicalOrder::class,
            'auditable_id' => $orden->id,
            'category' => 'ordenes',
        ]);
    }

    // ==================== Veredicto ====================

    public function test_cerrar_en_la_puerta_del_cliente_da_coincide(): void
    {
        $orden = $this->orden();

        // ~55 m: el técnico en la acera de enfrente o en el poste
        $orden->update([
            'closing_latitude' => self::LATITUD + 0.0005,
            'closing_longitude' => self::LONGITUD,
            'closing_accuracy_m' => 10,
        ]);

        $verificacion = $orden->fresh()->locationCheck();

        $this->assertSame(OrderLocationCheck::MATCHES, $verificacion->status);
        $this->assertTrue($verificacion->isComparable());
        $this->assertLessThan(100, $verificacion->distanceM);
    }

    public function test_cerrar_a_kilometros_del_servicio_da_lejana(): void
    {
        $orden = $this->orden();

        // ~2,2 km: no hay GPS que explique eso
        $orden->update([
            'closing_latitude' => self::LATITUD + 0.02,
            'closing_longitude' => self::LONGITUD,
            'closing_accuracy_m' => 15,
        ]);

        $this->assertSame(OrderLocationCheck::FAR, $orden->fresh()->locationCheck()->status);
    }

    public function test_un_gps_malo_no_convierte_una_orden_buena_en_sospechosa(): void
    {
        $orden = $this->orden();

        // ~330 m de desvío, pero el propio dispositivo admitió 400 m de
        // margen de error: bajo techo o en zona rural es lo normal.
        $orden->update([
            'closing_latitude' => self::LATITUD + 0.003,
            'closing_longitude' => self::LONGITUD,
            'closing_accuracy_m' => 400,
        ]);

        $verificacion = $orden->fresh()->locationCheck();

        $this->assertSame(OrderLocationCheck::MATCHES, $verificacion->status);
        $this->assertSame(0.0, $verificacion->adjustedDistanceM);
    }

    public function test_sin_contrato_ubicado_no_hay_nada_que_comparar(): void
    {
        $orden = $this->orden(contratoUbicado: false);

        $orden->update([
            'closing_latitude' => self::LATITUD,
            'closing_longitude' => self::LONGITUD,
        ]);

        $verificacion = $orden->fresh()->locationCheck();

        // NO es una orden sospechosa: es una orden sin referencia.
        // Confundirlas marcaría en rojo todos los contratos viejos.
        $this->assertSame(OrderLocationCheck::WITHOUT_REFERENCE, $verificacion->status);
        $this->assertFalse($verificacion->isComparable());
        $this->assertNull($orden->fresh()->distanceToService());
    }

    public function test_sin_punto_de_cierre_el_estado_lo_dice_sin_acusar(): void
    {
        $orden = $this->orden();

        $this->assertSame(OrderLocationCheck::WITHOUT_LOCATION, $orden->locationCheck()->status);
        $this->assertSame('secondary', $orden->locationCheck()->color());
    }

    // ==================== Devolución del supervisor ====================

    /**
     * Deja la orden como la deja el técnico al procesarla, con su
     * ubicación de cierre, y la devuelve el supervisor.
     */
    private function ordenDevuelta(string $motivo = 'Falta la foto del empalme'): TechnicalOrder
    {
        $orden = $this->orden();

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'closing_latitude' => self::LATITUD,
            'closing_longitude' => self::LONGITUD,
            'closing_accuracy_m' => 8,
        ]));

        $this->put(route('technical_order.verification_process', $orden->id), [
            'verification_comment' => $motivo,
            'reject_order' => '1',
        ]);

        return $orden->fresh();
    }

    public function test_una_orden_devuelta_vuelve_a_la_bandeja_del_tecnico(): void
    {
        $orden = $this->ordenDevuelta();

        // "Asignada" y no "Pendiente": Mis Órdenes solo lista las
        // asignadas, y en Pendiente el técnico no se enteraba nunca.
        $this->assertSame('Asignada', $orden->status);
        $this->assertSame($this->tecnico->id, $orden->user_assigned);

        $respuesta = $this->get(route('technicals_orders.my_technical_orders'));

        $respuesta->assertOk();
        $respuesta->assertSee('Devuelta');
        $respuesta->assertSee('Falta la foto del empalme');
    }

    public function test_al_devolver_se_le_avisa_al_tecnico_con_el_motivo(): void
    {
        $orden = $this->ordenDevuelta('El material reportado no coincide');

        Notification::assertSentTo(
            $this->tecnico,
            TechnicalOrderRejectedTechnician::class,
        );

        $this->assertSame('El material reportado no coincide', $orden->returnReason());
    }

    public function test_la_ubicacion_del_primer_cierre_no_se_pisa_al_corregir(): void
    {
        $orden = $this->ordenDevuelta();

        $original = [
            'lat' => (float) $orden->closing_latitude,
            'lng' => (float) $orden->closing_longitude,
            'precision' => $orden->closing_accuracy_m,
        ];

        // El técnico corrige la orden al día siguiente, desde la
        // oficina: a 2 km del cliente.
        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'solution' => 'Corregido lo que pidió el supervisor',
            'closing_latitude' => self::LATITUD + 0.02,
            'closing_longitude' => self::LONGITUD,
            'closing_accuracy_m' => 5,
        ]));

        $orden->refresh();

        // Manda el primer cierre: es el que prueba que el trabajo se
        // hizo en el sitio del cliente.
        $this->assertEqualsWithDelta($original['lat'], (float) $orden->closing_latitude, 0.0000001);
        $this->assertEqualsWithDelta($original['lng'], (float) $orden->closing_longitude, 0.0000001);
        $this->assertSame($original['precision'], $orden->closing_accuracy_m);
        $this->assertSame(OrderLocationCheck::MATCHES, $orden->locationCheck()->status);

        // Y lo corregido sí se guardó
        $this->assertSame('Corregido lo que pidió el supervisor', $orden->solution);
        $this->assertSame('Prefinalizada', $orden->status);
    }

    public function test_al_corregir_no_se_vuelve_a_pedir_la_firma_del_cliente(): void
    {
        $orden = $this->ordenDevuelta();
        $firmaOriginal = $orden->client_signature;

        $this->assertNotNull($firmaOriginal);

        // Sin client_signature en el envío: el cliente ya no está
        // delante para volver a firmar.
        $datos = $this->datosReporte(['solution' => 'Texto corregido']);
        unset($datos['client_signature']);

        $respuesta = $this->post(route('technicals_orders.process', $orden->id), $datos);

        $respuesta->assertSessionHasNoErrors();
        $respuesta->assertRedirect(route('technicals_orders.my_technical_orders'));

        $orden->refresh();

        $this->assertSame($firmaOriginal, $orden->client_signature);
        $this->assertSame('Prefinalizada', $orden->status);
    }

    public function test_una_orden_sin_tecnico_asignado_queda_pendiente_en_oficina(): void
    {
        $orden = $this->orden();
        $orden->update(['user_assigned' => null, 'status' => 'Prefinalizada']);

        $this->put(route('technical_order.verification_process', $orden->id), [
            'verification_comment' => 'Sin técnico asignado',
            'reject_order' => '1',
        ]);

        // Mandarla a "Asignada" sin nadie asignado la escondería de
        // todas las bandejas.
        $this->assertSame('Pendiente', $orden->fresh()->status);
    }

    public function test_cerrar_la_orden_deja_de_marcarla_como_devuelta(): void
    {
        $orden = $this->ordenDevuelta();

        $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte());

        $this->put(route('technical_order.verification_process', $orden->id), [
            'verification_comment' => 'Ahora sí',
            'close_order' => '1',
        ]);

        $orden = $orden->fresh()->load('verifications');

        $this->assertSame('Cerrada', $orden->status);
        $this->assertNull($orden->returnReason());
    }

    // ==================== Sugerencia en la pantalla del técnico ====================

    public function test_la_pantalla_de_una_instalacion_propone_la_caja_mas_cercana(): void
    {
        $orden = $this->orden(detalle: 'Instalacion de servicio');

        $respuesta = $this->get(route('technicals_orders.show', $orden->id));

        $respuesta->assertOk();
        $respuesta->assertViewHas('napSuggestions');
    }

    public function test_una_averia_no_propone_cambiar_al_cliente_de_caja(): void
    {
        $orden = $this->orden(detalle: 'Sin servicio de internet');

        $respuesta = $this->get(route('technicals_orders.show', $orden->id));

        $respuesta->assertOk();
        $this->assertTrue($respuesta->viewData('napSuggestions')->isEmpty());
    }
}
