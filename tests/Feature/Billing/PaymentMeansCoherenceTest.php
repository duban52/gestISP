<?php

namespace Tests\Feature\Billing;

use App\Models\FiscalCatalog;
use App\Models\Invoice;

/**
 * Que el papel y el XML digan lo mismo sobre la forma de pago.
 *
 * EL DEFECTO
 * ----------
 * El XML deducía contado/crédito comparando el vencimiento con la
 * emisión, y la representación gráfica imprimía «Crédito» y «EFECTIVO»
 * escritos a mano. Con un vencimiento igual a la emisión, el XML
 * declaraba CONTADO y el papel decía CRÉDITO: dos documentos de la
 * misma factura contradiciéndose, que es justo lo que se mira en una
 * revisión.
 *
 * Y el medio de pago del grupo de afinidad —configurable desde hace
 * tiempo, con su campo en el formulario— no llegaba nunca a la factura:
 * el XML acababa declarando siempre «10» (efectivo) dijera lo que
 * dijera el grupo.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Una sola definición, en el modelo, que usan el XML y el PDF.
 */
class PaymentMeansCoherenceTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Lo que el XML declara en `cac:PaymentMeans`. */
    private function pagoDelXml(Invoice $factura): array
    {
        $xml = app(\App\Billing\Dian\InvoiceXmlBuilder::class)->construir(
            $factura->load('invoice_items', 'contract.client.taxResponsibilities'),
            $this->rango->fresh(),
            $this->configuracion->fresh(),
        )['xml'];

        $xpath = $this->xpath($xml);

        return [
            'id' => $xpath->query('//cac:PaymentMeans/cbc:ID')->item(0)?->nodeValue,
            'medio' => $xpath->query('//cac:PaymentMeans/cbc:PaymentMeansCode')->item(0)?->nodeValue,
        ];
    }

    private function htmlDe(Invoice $factura): string
    {
        return view('gestisp.invoices.pdf', [
            'invoice' => $factura->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100',
            'dian' => null,
            'logo' => null,
        ])->render();
    }

    public function test_a_credito_el_papel_y_el_xml_coinciden(): void
    {
        $factura = $this->facturaElectronica();

        // Vencimiento posterior a la emisión: es a crédito.
        $factura->forceFill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
        ])->save();

        $factura = $factura->fresh();

        $this->assertTrue($factura->esACredito());
        $this->assertSame('2', $this->pagoDelXml($factura)['id']);
        $this->assertStringContainsString('Crédito', $this->htmlDe($factura));
    }

    public function test_de_contado_el_papel_ya_no_dice_credito(): void
    {
        // EL CASO DEL DEFECTO. Vencimiento el mismo día de la emisión:
        // el XML declaraba contado y el papel seguía diciendo crédito.
        $factura = $this->facturaElectronica();

        $factura->forceFill([
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
        ])->save();

        $factura = $factura->fresh();

        $this->assertFalse($factura->esACredito());
        $this->assertSame('1', $this->pagoDelXml($factura)['id']);

        $html = $this->htmlDe($factura);

        $this->assertStringContainsString('Contado', $html);
        // Y ya no aparece la palabra escrita a mano en la casilla.
        $this->assertStringNotContainsString('<td>Crédito</td>', $html);
    }

    public function test_el_medio_de_pago_del_grupo_llega_a_la_factura(): void
    {
        // El campo existía en el grupo y no lo copiaba nadie: el XML
        // declaraba «10» siempre.
        $contrato = $this->contratoElectronico();
        $contrato->affinityGroup->update(['default_payment_means_code' => '31']); // transferencia

        $factura = $this->emitir($contrato->fresh());

        $this->assertSame('31', $factura->payment_means_code);
        $this->assertSame('31', $this->pagoDelXml($factura)['medio']);
    }

    public function test_sin_medio_configurado_se_declara_efectivo(): void
    {
        $factura = $this->facturaElectronica();

        $this->assertSame('10', $factura->medioDePagoCodigo());
        $this->assertSame('10', $this->pagoDelXml($factura)['medio']);
    }

    public function test_el_papel_nombra_el_medio_que_declara_el_xml(): void
    {
        // El nombre sale del catálogo de la DIAN, no de una traducción
        // nuestra: el papel tiene que decir lo mismo que el XML.
        FiscalCatalog::create([
            'catalog' => FiscalCatalog::MEDIO_PAGO,
            'code' => '31',
            'name' => 'Transferencia crédito',
            'active' => true,
            'sort_order' => 0,
        ]);

        $contrato = $this->contratoElectronico();
        $contrato->affinityGroup->update(['default_payment_means_code' => '31']);

        $factura = $this->emitir($contrato->fresh());

        $this->assertSame('Transferencia crédito', $factura->medioDePagoLegible());
        $this->assertStringContainsString('Transferencia crédito', $this->htmlDe($factura));
        $this->assertStringNotContainsString('<td>EFECTIVO</td>', $this->htmlDe($factura));
    }
}
