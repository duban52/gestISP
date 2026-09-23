<?php

namespace Tests\Feature\Notifications;

use App\Models\ElectronicDocument;
use App\Notifications\ElectronicInvoiceDelivered;
use App\Notifications\InvoiceGenerated;
use App\Notifications\Messages\WhatsAppMessage;
use App\Notifications\WhatsApp\MetaCloudGateway;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Feature\Billing\ConstruyeFacturasElectronicas;

/**
 * Que la factura LLEGUE por WhatsApp, no solo el aviso de que existe.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que el aviso lleve el PDF, sea la factura electrónica o interna.
 *    Antes la electrónica salía sin enlace —esperaba a que la DIAN la
 *    validara— y el cliente recibía un mensaje sin nada que abrir.
 * 2. Que el archivo NO se mande cuando la plantilla aprobada en Meta no
 *    tiene cabecera de documento: eso hace que Meta rechace el envío
 *    entero y el cliente se quede hasta sin aviso.
 * 3. Que fuera de plantilla —la ventana de 24 h— el PDF se mande igual,
 *    con el texto de pie.
 */
class WhatsAppFacturaPdfTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    // ==================== El mensaje lleva el PDF ====================

    public function test_el_aviso_de_una_factura_interna_lleva_su_pdf(): void
    {
        $factura = $this->emitir($this->createBillableContract());

        $mensaje = (new InvoiceGenerated($factura))->toWhatsApp($factura->contract->client);

        $this->assertNotNull($mensaje->documentUrl);
        $this->assertStringContainsString('/facturas/' . $factura->id . '/descargar', $mensaje->documentUrl);
        // Firmado y con caducidad: el enlace es público, así que no
        // puede ser adivinable ni eterno.
        $this->assertStringContainsString('signature=', $mensaje->documentUrl);
        $this->assertStringContainsString('.pdf', $mensaje->documentName);
    }

    public function test_el_aviso_de_una_factura_electronica_tambien_lo_lleva(): void
    {
        // Antes esto salía sin enlace a propósito, porque la DIAN
        // todavía no la había validado. El cliente quiere su factura.
        $factura = $this->facturaElectronica();

        $mensaje = (new InvoiceGenerated($factura))->toWhatsApp($factura->contract->client);

        $this->assertNotNull($mensaje->documentUrl);
        $this->assertStringContainsString('Descárguela aquí', $mensaje->body);
    }

    public function test_la_entrega_de_la_electronica_validada_manda_el_pdf(): void
    {
        $factura = $this->facturaElectronica();

        ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail()
            ->forceFill([
                'status' => ElectronicDocument::ACEPTADO,
                'accepted_at' => now(),
                'dian_response_xml' => '<ApplicationResponse>ok</ApplicationResponse>',
            ])->save();

        $mensaje = (new ElectronicInvoiceDelivered($factura->fresh()))
            ->toWhatsApp($factura->contract->client);

        // El PDF y no el paquete: WhatsApp no acepta archivos ZIP.
        $this->assertStringContainsString('/descargar', (string) $mensaje->documentUrl);
    }

    // ==================== Lo que se manda a Meta ====================

    public function test_sin_cabecera_aprobada_el_documento_no_viaja(): void
    {
        $this->configurarMeta(['notifications.whatsapp.meta.invoice_document_in_template' => false]);

        $this->enviar();

        Http::assertSent(function ($request) {
            $componentes = $request->data()['template']['components'];

            return collect($componentes)->doesntContain(fn ($c) => $c['type'] === 'header');
        });
    }

    public function test_con_la_cabecera_aprobada_el_pdf_va_en_la_plantilla(): void
    {
        $this->configurarMeta(['notifications.whatsapp.meta.invoice_document_in_template' => true]);

        $this->enviar();

        Http::assertSent(function ($request) {
            $cabecera = collect($request->data()['template']['components'])
                ->firstWhere('type', 'header');

            return $cabecera
                && $cabecera['parameters'][0]['type'] === 'document'
                && $cabecera['parameters'][0]['document']['link'] === 'https://gestisp.test/factura.pdf'
                && $cabecera['parameters'][0]['document']['filename'] === 'factura-FAC-1.pdf';
        });
    }

    public function test_fuera_de_plantilla_el_pdf_va_con_el_texto_de_pie(): void
    {
        // En la ventana de 24 h no hace falta plantilla: el mensaje se
        // manda como documento y el texto viaja como pie de foto.
        $this->configurarMeta(['notifications.whatsapp.meta.use_templates' => false]);

        $this->enviar();

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return $cuerpo['type'] === 'document'
                && $cuerpo['document']['link'] === 'https://gestisp.test/factura.pdf'
                && str_contains($cuerpo['document']['caption'], 'su factura');
        });
    }

    // ==================== Apoyo ====================

    private function configurarMeta(array $extra = []): void
    {
        config(array_merge([
            'notifications.whatsapp.meta.phone_number_id' => '123456',
            'notifications.whatsapp.meta.token' => 'un-token',
            'notifications.whatsapp.meta.api_version' => 'v21.0',
            'notifications.whatsapp.meta.use_templates' => true,
            'notifications.whatsapp.meta.template_language' => 'es',
        ], $extra));

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);
    }

    private function enviar(): void
    {
        $mensaje = WhatsAppMessage::make('Hola Ana, se generó su factura FAC-1 por $80.000.')
            ->template('factura_generada', ['Ana', 'FAC-1', '$80.000', '13/10/2026'])
            ->document('https://gestisp.test/factura.pdf', 'factura-FAC-1.pdf');

        (new MetaCloudGateway())->send('573155554433', $mensaje);
    }
}
