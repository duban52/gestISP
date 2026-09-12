<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Reports\ConsecutiveAudit;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * El reporte de consecutivos autorizados.
 *
 * PARA QUÉ
 * --------
 * La DIAN autoriza un rango de numeración y espera poder pedir cuenta
 * de cada número: qué documento salió con él, o por qué no salió
 * ninguno. El propio código lo reconocía en tres comentarios distintos
 * —«un rechazo deja un hueco que hay que justificar»— sin que hubiera
 * dónde verlos: la única forma era consultar la base a mano.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que los tres modos de quemar un consecutivo se distingan y se
 * encuentren:
 *
 *   · SIN DOCUMENTO — el número se reservó y no quedó factura. Es el
 *     que menos rastro deja y por eso el que más importa.
 *   · RECHAZADO — hay factura, la DIAN no la validó.
 *   · ANULADO — hay factura y se anuló.
 *
 * Y que un consecutivo aceptado NO aparezca como problema: un informe
 * que marca lo correcto se deja de leer.
 */
class ConsecutiveAuditTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas {
        setUp as prepararFacturacionElectronica;
    }

    protected function setUp(): void
    {
        $this->prepararFacturacionElectronica();

        Permission::firstOrCreate(
            ['name' => 'dian.documents', 'guard_name' => 'web'],
            ['description' => 'Documentos DIAN'],
        );

        Role::where('name', 'superadministrador')->firstOrFail()
            ->givePermissionTo('dian.documents');
    }

    /**
     * El informe TAL COMO LO PRODUCE LA PANTALLA.
     *
     * Por la ruta y no llamando al servicio a pelo: el alcance de
     * empresa y de sucursal lo pone el middleware, así que invocarlo
     * suelto probaría un camino que en producción no existe — y que
     * además devuelve vacío, porque sin contexto el servicio se niega.
     */
    private function informe(): array
    {
        $rangos = $this->get(route('dian.log.consecutivos'))
            ->assertOk()
            ->viewData('rangos');

        $this->assertNotEmpty($rangos, 'No se encontró el rango autorizado de la prueba.');

        return $rangos->first();
    }

    private function documentoDe(Invoice $factura): ElectronicDocument
    {
        return ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail();
    }

    public function test_un_consecutivo_aceptado_no_es_un_problema(): void
    {
        $factura = $this->facturaElectronica();

        $this->documentoDe($factura)
            ->forceFill(['status' => ElectronicDocument::ACEPTADO, 'accepted_at' => now()])
            ->save();

        $informe = $this->informe();

        $this->assertSame(1, $informe['usados']);
        $this->assertSame(1, $informe['conteos'][ConsecutiveAudit::OK]);
        $this->assertCount(0, $informe['problemas']);
    }

    public function test_un_rechazo_deja_el_consecutivo_por_justificar(): void
    {
        $factura = $this->facturaElectronica();

        $this->documentoDe($factura)
            ->forceFill(['status' => ElectronicDocument::RECHAZADO])
            ->save();

        $informe = $this->informe();

        $this->assertCount(1, $informe['problemas']);
        $this->assertSame(ConsecutiveAudit::RECHAZADO, $informe['problemas']->first()['estado']);
        $this->assertSame($factura->number, $informe['problemas']->first()['numero']);
    }

    public function test_una_anulada_tambien(): void
    {
        $factura = $this->facturaElectronica();
        $factura->forceFill(['status' => InvoiceStatus::Anulada->value])->save();

        $informe = $this->informe();

        $this->assertSame(ConsecutiveAudit::ANULADO, $informe['problemas']->first()['estado']);
    }

    public function test_un_numero_reservado_sin_factura_es_el_peor_caso(): void
    {
        // El contador avanzó y no quedó documento: una transacción que
        // se revirtió después de numerar, o una factura borrada. Es el
        // hueco que menos rastro deja, y el que la DIAN preguntaría.
        $this->facturaElectronica();

        // Se quema el siguiente consecutivo sin emitir nada con él.
        $this->rango->refresh()->update(['current_number' => $this->rango->current_number + 1]);

        $informe = $this->informe();

        $this->assertSame(2, $informe['usados']);
        $this->assertCount(1, $informe['problemas']);
        $this->assertSame(ConsecutiveAudit::SIN_DOCUMENTO, $informe['problemas']->first()['estado']);
        $this->assertNull($informe['problemas']->first()['factura']);
    }

    public function test_un_rango_sin_emitir_no_inventa_huecos(): void
    {
        // `current_number` arranca en 0 y `range_start` en 990000000: si
        // el intervalo se calculara mal, saldrían millones de huecos.
        $informe = $this->informe();

        $this->assertSame(0, $informe['usados']);
        $this->assertCount(0, $informe['problemas']);
        $this->assertCount(0, $informe['consecutivos']);
    }

    public function test_el_informe_dice_cuanto_queda_del_rango(): void
    {
        $this->facturaElectronica();

        $informe = $this->informe();

        $this->assertSame((int) $this->rango->range_start, $informe['autorizado_desde']);
        $this->assertSame((int) $this->rango->range_end, $informe['autorizado_hasta']);
        $this->assertSame($this->rango->fresh()->restantes(), $informe['restantes']);
    }

    // ==================== La pantalla ====================

    public function test_la_pantalla_carga_y_señala_lo_que_hay_que_justificar(): void
    {
        $factura = $this->facturaElectronica();

        $this->documentoDe($factura)
            ->forceFill(['status' => ElectronicDocument::RECHAZADO])
            ->save();

        $this->get(route('dian.log.consecutivos'))
            ->assertOk()
            ->assertSee('Consecutivos autorizados', false)
            ->assertSee('Rechazado por la DIAN', false)
            ->assertSee($factura->full_number, false);
    }

    public function test_sin_permiso_no_se_ve(): void
    {
        // Lleva la numeración fiscal completa y datos de clientes.
        Role::where('name', 'superadministrador')->firstOrFail()
            ->revokePermissionTo('dian.documents');

        $this->get(route('dian.log.consecutivos'))->assertForbidden();
    }
}
