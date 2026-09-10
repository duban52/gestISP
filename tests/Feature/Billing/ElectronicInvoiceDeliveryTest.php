<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\SelfSignedCertificate;
use App\Billing\Delivery\InvoiceDownloadLink;
use App\Billing\Delivery\InvoicePackage;
use App\Billing\Events\ElectronicDocumentAccepted;
use App\Models\DianCertificate;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use App\Notifications\ElectronicInvoiceDelivered;
use Illuminate\Support\Facades\Notification;
use ZipArchive;

/**
 * La entrega de la factura electrónica al adquiriente.
 *
 * Emitir ante la DIAN y entregarle el documento al cliente son dos
 * obligaciones distintas. Esto cubre la segunda.
 *
 * LO QUE MÁS IMPORTA AQUÍ ES QUE NO SE ENVÍE DOS VECES
 * ----------------------------------------------------
 * La transmisión se reintenta, los trabajos de la cola se reencolan
 * solos, y `dian:transmitir` se puede correr a mano. Cualquiera de esas
 * tres cosas puede volver a aceptar un documento ya aceptado. Una
 * factura que no llegó se detecta y se reenvía; una que llegó dos veces
 * ya está en el buzón del cliente.
 */
class ElectronicInvoiceDeliveryTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Una factura electrónica con su documento aceptado por la DIAN. */
    private function facturaValidada(): Invoice
    {
        $factura = $this->facturaElectronica();

        ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->update([
                'status' => ElectronicDocument::ACEPTADO,
                'accepted_at' => now(),
                'dian_response_xml' => '<ApplicationResponse><IsValid>true</IsValid></ApplicationResponse>',
            ]);

        return $factura->fresh();
    }

    private function documentoDe(Invoice $factura): ElectronicDocument
    {
        return ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail();
    }

    /** Un certificado vigente para la empresa, con su .p12 en disco. */
    private function conCertificado(): void
    {
        $ruta = storage_path('app/prueba-contenedor.p12');

        file_put_contents($ruta, (new SelfSignedCertificate())->generar(
            ['countryName' => 'CO', 'commonName' => 'Prueba Paquete'],
            'clave',
        ));

        DianCertificate::withoutGlobalScopes()->create([
            'company_id' => $this->branch->company_id,
            'name' => 'Certificado de prueba',
            'path' => $ruta,
            'password' => 'clave',
            'valid_from' => now()->subMonth(),
            'valid_until' => now()->addMonth(),
            'active' => true,
            'self_signed' => true,
        ]);
    }

    /** @return array<int, string> los nombres de los archivos del ZIP */
    private function archivosDelPaquete(Invoice $factura): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'prueba');
        file_put_contents($ruta, app(InvoicePackage::class)->bytes($factura));

        $zip = new ZipArchive();
        $zip->open($ruta);

        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($ruta);

        return $nombres;
    }

    // ==================== El paquete ====================

    public function test_sin_certificado_el_paquete_lleva_los_xml_sueltos(): void
    {
        // El RESPALDO. El contenedor va firmado, asi que sin certificado
        // vigente no se puede armar — y eso no puede dejar al cliente
        // sin su factura: recibe los dos XML sueltos, que llevan la
        // misma informacion.
        //
        // Pasa de verdad: un documento aceptado ANTES de que se
        // empezara a guardar el acuse tampoco tiene con que armarlo.
        $factura = $this->facturaValidada();

        $ruta = tempnam(sys_get_temp_dir(), 'prueba');
        file_put_contents($ruta, app(InvoicePackage::class)->bytes($factura));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($ruta) === true);

        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }

        $numero = $factura->displayNumber();

        $this->assertContains($numero . '.xml', $nombres, 'Falta la factura firmada.');
        $this->assertContains($numero . '-respuesta-dian.xml', $nombres, 'Falta el acuse de la DIAN.');
        $this->assertContains('factura-' . $numero . '.pdf', $nombres, 'Falta la representación gráfica.');

        // Es el XML GUARDADO, no uno rehecho al vuelo: lleva el CUFE
        // con el que la DIAN lo valido. Rehacerlo daria otro CUFE —la
        // hora de emision entra en el calculo— y el cliente tendria un
        // documento que no cuadra con el que la DIAN tiene.
        $guardado = $zip->getFromName($numero . '.xml');
        $cufe = $this->documentoDe($factura)->cufe;

        $this->assertNotEmpty($cufe);
        $this->assertStringContainsString($cufe, $guardado);

        $zip->close();
        @unlink($ruta);
    }

    public function test_con_certificado_el_paquete_lleva_el_attacheddocument(): void
    {
        // Es lo que reciben los clientes de verdad: el contenedor
        // estandar, que lleva los dos XML dentro y que los sistemas
        // contables cargan de un tiron.
        //
        // La otra prueba ejercita el RESPALDO —los XML sueltos—, que es
        // lo que sale cuando no hay certificado con que firmarlo.
        $factura = $this->facturaValidada();
        $this->conCertificado();

        $nombres = $this->archivosDelPaquete($factura);
        $numero = $factura->displayNumber();

        $this->assertContains('ad-' . $numero . '.xml', $nombres, 'Falta el AttachedDocument.');
        $this->assertContains('factura-' . $numero . '.pdf', $nombres);

        // Los sueltos ya no hacen falta: van dentro del contenedor.
        $this->assertNotContains($numero . '.xml', $nombres);
    }

    public function test_sin_documento_electronico_no_hay_paquete(): void
    {
        $interna = $this->emitir($this->createBillableContract());

        $this->assertFalse(app(InvoicePackage::class)->disponiblePara($interna));
    }

    // ==================== La entrega ====================

    public function test_cuando_la_dian_acepta_se_le_entrega_al_cliente(): void
    {
        Notification::fake();

        $factura = $this->facturaValidada();

        event(new ElectronicDocumentAccepted($this->documentoDe($factura)));

        Notification::assertSentTo($factura->contract->client, ElectronicInvoiceDelivered::class);
        $this->assertNotNull($this->documentoDe($factura)->delivered_at);
    }

    public function test_no_se_entrega_dos_veces(): void
    {
        // El caso real: `dian:transmitir` corrido a mano sobre un
        // documento que ya se acepto, o un job de la cola que se
        // reencola.
        Notification::fake();

        $factura = $this->facturaValidada();
        $documento = $this->documentoDe($factura);

        event(new ElectronicDocumentAccepted($documento));
        event(new ElectronicDocumentAccepted($documento->fresh()));
        event(new ElectronicDocumentAccepted($documento->fresh()));

        Notification::assertSentToTimes($factura->contract->client, ElectronicInvoiceDelivered::class, 1);
    }

    public function test_una_factura_interna_no_dispara_entrega(): void
    {
        // No tiene documento electronico, asi que nunca hay un
        // ElectronicDocumentAccepted que la entregue. Se comprueba que
        // emitirla no le manda al cliente la notificacion de entrega.
        Notification::fake();

        $interna = $this->emitir($this->createBillableContract());

        Notification::assertNotSentTo(
            $interna->contract->client,
            ElectronicInvoiceDelivered::class,
        );
    }

    // ==================== El enlace del paquete ====================

    public function test_el_enlace_del_paquete_entrega_el_zip(): void
    {
        $factura = $this->facturaValidada();

        $respuesta = $this->get(app(InvoiceDownloadLink::class)->paraElPaquete($factura));

        $respuesta->assertOk();
        $this->assertStringStartsWith('PK', $respuesta->streamedContent());
    }

    public function test_el_enlace_del_paquete_de_una_interna_da_404(): void
    {
        // No hay XML ni acuse que meter: mejor decir que no hay nada
        // que entregar un ZIP a medias.
        $interna = $this->emitir($this->createBillableContract());

        $this->get(app(InvoiceDownloadLink::class)->paraElPaquete($interna))
            ->assertNotFound();
    }

    public function test_el_enlace_del_paquete_tambien_exige_firma(): void
    {
        $factura = $this->facturaValidada();

        $this->get(route('invoices.public_zip', ['invoice' => $factura->id]))
            ->assertForbidden();
    }
}
