<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\InvoiceVoider;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use RuntimeException;

/**
 * Una factura electrónica validada por la DIAN NO se anula: se le emite
 * una nota crédito.
 *
 * EL DEFECTO
 * ----------
 * `InvoiceVoider` cambiaba el estado a «Anulada» sin mirar si la DIAN ya
 * había aceptado el documento. Pero cuando la DIAN acepta, la factura
 * deja de ser nuestra: existe en sus registros con su CUFE. Cambiarle el
 * estado aquí no la borra de allí — solo hace que nuestra contabilidad y
 * la suya dejen de coincidir, y esa diferencia aparece justo cuando
 * alguien cruza los dos lados.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que el atajo esté cerrado y que el mensaje diga por dónde se va. La
 * nota crédito ya existe en el sistema (`NoteIssuer`): lo que faltaba
 * era impedir la vía que descuadra.
 *
 * Y que siga siendo posible anular lo que NUNCA fue documento fiscal:
 * una interna, o una electrónica rechazada o sin transmitir.
 */
class VoidAcceptedInvoiceTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    private function conDocumentoEn(Invoice $factura, string $estado): Invoice
    {
        ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail()
            ->forceFill([
                'status' => $estado,
                'accepted_at' => $estado === ElectronicDocument::ACEPTADO ? now() : null,
            ])->save();

        return $factura->fresh();
    }

    private function anular(Invoice $factura): Invoice
    {
        return app(InvoiceVoider::class)->void($factura, 'Prueba', $this->admin->id);
    }

    public function test_una_electronica_validada_no_se_puede_anular(): void
    {
        $factura = $this->conDocumentoEn($this->facturaElectronica(), ElectronicDocument::ACEPTADO);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/NOTA CRÉDITO/');

        $this->anular($factura);
    }

    public function test_y_sigue_sin_anular_despues_de_intentarlo(): void
    {
        // Que la excepción salte no basta: hay que comprobar que no dejó
        // el estado a medias.
        $factura = $this->conDocumentoEn($this->facturaElectronica(), ElectronicDocument::ACEPTADO);

        try {
            $this->anular($factura);
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertNotSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);
        $this->assertNull($factura->fresh()->voided_at);
    }

    public function test_una_electronica_rechazada_si_se_anula(): void
    {
        // Nunca llegó a ser documento fiscal. Deja el consecutivo
        // quemado, que es otro asunto y se ve en el reporte de
        // consecutivos.
        $factura = $this->conDocumentoEn($this->facturaElectronica(), ElectronicDocument::RECHAZADO);

        $this->anular($factura);

        $this->assertSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);
    }

    public function test_una_electronica_sin_transmitir_si_se_anula(): void
    {
        $factura = $this->conDocumentoEn($this->facturaElectronica(), ElectronicDocument::FIRMADO);

        $this->anular($factura);

        $this->assertSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);
    }

    public function test_una_interna_si_se_anula(): void
    {
        // No hay nada que descuadrar: la DIAN no sabe que existe.
        $factura = $this->emitir($this->createBillableContract());

        $this->anular($factura);

        $this->assertSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);
    }

    public function test_desde_la_pantalla_se_avisa_en_vez_de_reventar(): void
    {
        // El controlador ya captura la excepción; se comprueba que el
        // usuario vea el motivo y no un 500.
        $factura = $this->conDocumentoEn($this->facturaElectronica(), ElectronicDocument::ACEPTADO);

        $this->post(route('invoices.void', $factura), ['void_reason' => 'Me equivoqué'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);
    }
}
