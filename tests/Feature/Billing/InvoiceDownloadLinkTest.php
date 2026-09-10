<?php

namespace Tests\Feature\Billing;

use App\Billing\Delivery\InvoiceDownloadLink;
use App\Models\Invoice;
use App\Notifications\InvoiceGenerated;
use Illuminate\Support\Facades\URL;

/**
 * El enlace con el que el cliente descarga su factura.
 *
 * QUÉ SE DEFIENDE, Y POR QUÉ IMPORTA
 * ----------------------------------
 * Esta es la ÚNICA ruta del sistema que atiende a alguien sin sesión: el
 * cliente del ISP recibe un enlace por WhatsApp. No hay usuario, no hay
 * permisos, no hay alcance de empresa.
 *
 * Lo único que la protege es la firma. Si se pudiera cambiar el número
 * de factura en la barra del navegador, cualquier cliente vería las
 * facturas de todos los demás — de todas las empresas del sistema. Eso
 * es lo que estas pruebas impiden que ocurra en silencio.
 */
class InvoiceDownloadLinkTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    private function facturaInterna(): Invoice
    {
        return $this->emitir($this->createBillableContract());
    }

    public function test_el_enlace_firmado_entrega_el_pdf(): void
    {
        $factura = $this->facturaInterna();

        $respuesta = $this->get(app(InvoiceDownloadLink::class)->para($factura));

        $respuesta->assertOk();
        $this->assertStringStartsWith('%PDF-', $respuesta->streamedContent());
    }

    public function test_sin_firma_no_se_entrega_nada(): void
    {
        $factura = $this->facturaInterna();

        $this->get(route('invoices.public_pdf', ['invoice' => $factura->id]))
            ->assertForbidden();
    }

    public function test_no_se_puede_cambiar_la_factura_en_la_url(): void
    {
        // El ataque obvio: pedir un enlace de la factura propia y
        // editarle el número para ver la del vecino. La firma cubre la
        // URL entera, así que al cambiarla deja de valer.
        $mia = $this->facturaInterna();
        $ajena = $this->facturaInterna();

        $enlace = app(InvoiceDownloadLink::class)->para($mia);
        $manipulado = str_replace(
            '/facturas/' . $mia->id . '/',
            '/facturas/' . $ajena->id . '/',
            $enlace,
        );

        $this->assertNotSame($enlace, $manipulado, 'La prueba no vale si la URL no cambió.');
        $this->get($manipulado)->assertForbidden();
    }

    public function test_el_enlace_caduca(): void
    {
        $factura = $this->facturaInterna();

        $enlace = URL::temporarySignedRoute(
            'invoices.public_pdf',
            now()->subMinute(),
            ['invoice' => $factura->id],
        );

        $this->get($enlace)->assertForbidden();
    }

    public function test_la_interna_manda_el_enlace_por_whatsapp(): void
    {
        $factura = $this->facturaInterna();
        $cliente = $factura->contract->client;

        $mensaje = (new InvoiceGenerated($factura))->toWhatsApp($cliente);

        $this->assertStringContainsString('/facturas/' . $factura->id . '/descargar', $mensaje->body);
        $this->assertStringContainsString('signature=', $mensaje->body);
    }

    public function test_la_electronica_todavia_no_manda_enlace(): void
    {
        // Mismo motivo que el adjunto del correo: en este momento su PDF
        // diría «pendiente de validación por la DIAN».
        $factura = $this->facturaElectronica();

        $mensaje = (new InvoiceGenerated($factura))->toWhatsApp($factura->contract->client);

        $this->assertStringNotContainsString('/descargar', $mensaje->body);
    }

    public function test_el_quinto_parametro_de_la_plantilla_esta_apagado_de_fabrica(): void
    {
        // La plantilla aprobada en Meta tiene CUATRO huecos. Mandarle un
        // quinto hace que Meta rechace el envío entero y el cliente se
        // quede sin aviso de factura.
        //
        // Por eso arranca apagado: primero se actualiza y se aprueba la
        // plantilla, y solo entonces se enciende.
        $factura = $this->facturaInterna();

        $sinEncender = (new InvoiceGenerated($factura))->toWhatsApp($factura->contract->client);

        $this->assertCount(4, $sinEncender->templateParams);

        config(['notifications.whatsapp.meta.invoice_link_in_template' => true]);

        $encendido = (new InvoiceGenerated($factura))->toWhatsApp($factura->contract->client);

        $this->assertCount(5, $encendido->templateParams);
    }
}
