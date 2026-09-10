<?php

namespace Tests\Feature\Billing;

use App\Billing\Services\ElectronicInvoicingDecider;
use App\Models\Invoice;
use App\Billing\Delivery\InvoicePdf;
use App\Notifications\InvoiceGenerated;
use RuntimeException;

/**
 * Lo que le llega al cliente cuando se emite su factura.
 *
 * LA REGLA
 * --------
 * A la factura INTERNA se le adjunta el PDF. A la ELECTRÓNICA no — en
 * ese momento todavía no está validada, y su PDF diría «Pendiente de
 * validación por la DIAN»: mandarle al cliente un documento que dice eso
 * es peor que no mandarle ninguno. La electrónica recibe su paquete
 * completo cuando la DIAN la acepta.
 *
 * POR QUÉ SE COMPRUEBA
 * --------------------
 * Porque el adjunto se arma dentro de una notificación EN COLA, sin
 * petición HTTP. Es justo el escenario donde el código anterior fallaba:
 * el código de barras se cargaba por URL y dependía de que el servidor
 * pudiera pedirse a sí mismo.
 */
class InvoiceDeliveryTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Los adjuntos del correo que produce la notificación. */
    private function adjuntosDe(Invoice $factura): array
    {
        $cliente = $factura->contract->client;

        return (new InvoiceGenerated($factura))->toMail($cliente)->rawAttachments;
    }

    public function test_la_factura_interna_va_con_el_pdf_adjunto(): void
    {
        $factura = $this->emitir($this->createBillableContract());

        $this->assertSame(
            ElectronicInvoicingDecider::INTERNO,
            $factura->document_kind,
            'La prueba no vale si la factura salió electrónica.',
        );

        $adjuntos = $this->adjuntosDe($factura);

        $this->assertCount(1, $adjuntos);
        $this->assertStringStartsWith('%PDF-', $adjuntos[0]['data']);
        $this->assertSame('factura-' . $factura->displayNumber() . '.pdf', $adjuntos[0]['name']);
    }

    public function test_la_factura_electronica_no_lleva_pdf_todavia(): void
    {
        // No es un olvido: es la regla. Su PDF diría «pendiente de
        // validación», porque en este momento lo está.
        $factura = $this->facturaElectronica();

        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $factura->document_kind);
        $this->assertEmpty($this->adjuntosDe($factura));
    }

    public function test_si_el_pdf_falla_el_aviso_sale_igual(): void
    {
        // Que el cliente reciba «su factura es de $80.000 y vence el 28»
        // vale mucho mas que no recibir nada porque el PDF no se pudo
        // armar. Se hace fallar al generador a proposito, que es la
        // unica forma honesta de probar el rescate.
        $factura = $this->emitir($this->createBillableContract());

        $this->mock(InvoicePdf::class, function ($simulado) {
            $simulado->shouldReceive('bytes')->andThrow(new RuntimeException('dompdf se cayo'));
        });

        $correo = (new InvoiceGenerated($factura))->toMail($factura->contract->client);

        $this->assertNotEmpty($correo->subject);
        $this->assertEmpty($correo->rawAttachments, 'Sin PDF, pero el aviso sale.');
    }
}
