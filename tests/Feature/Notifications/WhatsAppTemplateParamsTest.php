<?php

namespace Tests\Feature\Notifications;

use App\Models\ElectronicDocument;
use App\Notifications\ElectronicInvoiceDelivered;
use App\Notifications\InvoiceGenerated;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Feature\Billing\ConstruyeFacturasElectronicas;

/**
 * Cuántos parámetros lleva la plantilla `factura_generada`.
 *
 * POR QUÉ ES UNA PRUEBA Y NO UN DETALLE
 * -------------------------------------
 * Meta exige que el número de parámetros coincida EXACTAMENTE con el de
 * la plantilla aprobada. Ni uno más ni uno menos: si no coincide,
 * rechaza el envío entero y el cliente no recibe nada. No hay aviso en
 * pantalla — solo una línea en el log.
 *
 * Y la plantilla la comparten DOS notificaciones: el aviso de que la
 * factura se generó y la entrega de la electrónica ya validada. Las dos
 * tienen que mandar lo mismo, o al encender el interruptor una seguiría
 * funcionando y la otra no. Un fallo a medias es peor que uno entero,
 * porque nadie lo nota.
 *
 * EL INTERRUPTOR
 * --------------
 * `WHATSAPP_META_INVOICE_LINK` arranca apagado a propósito. El orden es:
 * actualizar la plantilla en Meta → esperar la aprobación → encenderlo.
 * Encenderlo antes deja a los clientes sin aviso de factura.
 */
class WhatsAppTemplateParamsTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    private function conElInterruptor(bool $encendido): void
    {
        config(['notifications.whatsapp.meta.invoice_link_in_template' => $encendido]);
    }

    /** Una electrónica ya aceptada por la DIAN, lista para entregar. */
    private function facturaEntregable()
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

        return $factura->fresh();
    }

    public function test_apagado_las_dos_notificaciones_mandan_cuatro(): void
    {
        $this->conElInterruptor(false);

        $interna = $this->emitir($this->createBillableContract());
        $electronica = $this->facturaEntregable();

        $this->assertCount(
            4,
            (new InvoiceGenerated($interna))->toWhatsApp($interna->contract->client)->templateParams,
        );

        $this->assertCount(
            4,
            (new ElectronicInvoiceDelivered($electronica))->toWhatsApp($electronica->contract->client)->templateParams,
        );
    }

    public function test_encendido_las_dos_mandan_cinco(): void
    {
        // EL CASO QUE ROMPÍA. `ElectronicInvoiceDelivered` no miraba el
        // interruptor: seguía mandando cuatro, así que al actualizar la
        // plantilla Meta habría rechazado justo la entrega de la factura
        // validada, que es la que tiene valor fiscal.
        $this->conElInterruptor(true);

        $interna = $this->emitir($this->createBillableContract());
        $electronica = $this->facturaEntregable();

        $this->assertCount(
            5,
            (new InvoiceGenerated($interna))->toWhatsApp($interna->contract->client)->templateParams,
        );

        $this->assertCount(
            5,
            (new ElectronicInvoiceDelivered($electronica))->toWhatsApp($electronica->contract->client)->templateParams,
        );
    }

    public function test_van_cinco_aunque_no_haya_enlace(): void
    {
        // Una ELECTRÓNICA recién generada no lleva enlace en el aviso de
        // generación —su PDF diría «pendiente de validación»—. Antes ese
        // caso mandaba cuatro parámetros con el interruptor encendido, y
        // Meta lo rechazaba.
        $this->conElInterruptor(true);

        $electronica = $this->facturaElectronica();

        $mensaje = (new InvoiceGenerated($electronica))->toWhatsApp($electronica->contract->client);

        $this->assertCount(5, $mensaje->templateParams);
    }

    public function test_ningun_parametro_va_vacio(): void
    {
        // Meta rechaza los parámetros vacíos. Por eso el quinto hueco es
        // una FRASE y no la URL pelada: sin enlace, sigue diciendo algo.
        $this->conElInterruptor(true);

        $electronica = $this->facturaElectronica();

        foreach ((new InvoiceGenerated($electronica))->toWhatsApp($electronica->contract->client)->templateParams as $i => $parametro) {
            $this->assertNotSame('', trim((string) $parametro), "El parámetro {{" . ($i + 1) . "}} va vacío.");
        }
    }

    public function test_el_cuerpo_libre_no_depende_del_interruptor(): void
    {
        // El interruptor es SOLO para la plantilla de Meta. El cuerpo en
        // texto lo usan el driver simulado y la ventana de 24 h abierta,
        // y ahí el enlace tiene que seguir viajando igual.
        $interna = $this->emitir($this->createBillableContract());

        $this->conElInterruptor(false);
        $apagado = (new InvoiceGenerated($interna))->toWhatsApp($interna->contract->client)->body;

        $this->conElInterruptor(true);
        $encendido = (new InvoiceGenerated($interna))->toWhatsApp($interna->contract->client)->body;

        $this->assertStringContainsString('Descárguela aquí', $apagado);
        $this->assertStringContainsString('Descárguela aquí', $encendido);
    }
}
