<?php

namespace Tests\Feature\Notifications;

use App\Models\ElectronicDocument;
use App\Notifications\ElectronicInvoiceDelivered;
use App\Notifications\InvoiceGenerated;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Feature\Billing\ConstruyeFacturasElectronicas;

/**
 * Los parámetros de la plantilla `factura_generada`.
 *
 * POR QUÉ ES UNA PRUEBA Y NO UN DETALLE
 * -------------------------------------
 * Meta exige que el número de parámetros coincida EXACTAMENTE con el de
 * la plantilla aprobada. Ni uno más ni uno menos: si no coincide,
 * rechaza el envío entero y el cliente no recibe nada. No hay aviso en
 * pantalla — solo una línea en el log. Ya costó una mañana entera.
 *
 * Y la plantilla la comparten DOS notificaciones: el aviso de que la
 * factura se generó y la entrega de la electrónica ya validada. Las dos
 * tienen que mandar lo mismo, o una seguiría funcionando y la otra no.
 * Un fallo a medias es peor que uno entero, porque nadie lo nota.
 *
 * LA PLANTILLA, TAL COMO ESTÁ APROBADA
 * ------------------------------------
 *   Hola {{1}}, {{6}} le informa que se generó su factura {{2}} del
 *   servicio de {{7}}, por {{3}}. Vence el {{4}}. […] Descarguela en el
 *   siguiente enlace: {{5}} ¡Gracias!
 *
 * El orden importa más que el número: la empresa y el servicio van
 * DETRÁS del enlace, así que saltarse el enlace no manda «uno menos»,
 * manda el nombre de la empresa en el hueco de la URL.
 */
class WhatsAppTemplateParamsTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    private const HUECOS = 7;

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

    public function test_las_dos_notificaciones_mandan_los_mismos_huecos(): void
    {
        $interna = $this->emitir($this->createBillableContract());
        $electronica = $this->facturaEntregable();

        $this->assertCount(
            self::HUECOS,
            (new InvoiceGenerated($interna))->toWhatsApp($interna->contract->client)->templateParams,
        );

        $this->assertCount(
            self::HUECOS,
            (new ElectronicInvoiceDelivered($electronica))->toWhatsApp($electronica->contract->client)->templateParams,
        );
    }

    public function test_van_los_siete_aunque_no_haya_enlace(): void
    {
        // Mandar seis a una plantilla de siete lo rechaza Meta igual
        // que mandar ocho.
        $electronica = $this->facturaElectronica();

        $mensaje = (new InvoiceGenerated($electronica))->toWhatsApp($electronica->contract->client);

        $this->assertCount(self::HUECOS, $mensaje->templateParams);
    }

    public function test_ningun_parametro_va_vacio(): void
    {
        // Meta rechaza los parámetros vacíos y el cliente se quedaría
        // sin aviso.
        $electronica = $this->facturaElectronica();
        $parametros = (new InvoiceGenerated($electronica))->toWhatsApp($electronica->contract->client)->templateParams;

        foreach ($parametros as $i => $parametro) {
            $this->assertNotSame('', trim((string) $parametro), 'El parámetro {{' . ($i + 1) . '}} va vacío.');
        }
    }

    /**
     * EL ORDEN, hueco por hueco.
     *
     * Contar siete no basta: con el enlace y la empresa cambiados de
     * sitio, el cliente leería «Descarguela en el siguiente enlace:
     * Grupo Nexar SAS» y el mensaje seguiría teniendo siete parámetros.
     */
    public function test_cada_hueco_lleva_lo_suyo(): void
    {
        $interna = $this->emitir($this->createBillableContract());
        $cliente = $interna->contract->client;

        $parametros = (new InvoiceGenerated($interna))->toWhatsApp($cliente)->templateParams;

        $this->assertSame($cliente->name, $parametros[0]);
        $this->assertSame($interna->displayNumber(), $parametros[1]);
        $this->assertStringStartsWith('$', $parametros[2]);
        // {{5}} es la URL pelada: la plantilla ya pone la invitación.
        $this->assertStringStartsWith('http', $parametros[4]);
        $this->assertSame(
            $interna->branch->company?->nombreVisible() ?: $interna->branch->name,
            $parametros[5],
        );
        $this->assertSame($interna->contract->plan->name, $parametros[6]);
    }

    public function test_el_cuerpo_libre_sigue_llevando_el_enlace(): void
    {
        // El cuerpo en texto lo usan el driver simulado y la ventana de
        // 24 h abierta: ahí no hay plantilla y el enlace tiene que ir
        // escrito dentro del mensaje.
        $interna = $this->emitir($this->createBillableContract());

        $this->assertStringContainsString(
            'Descárguela aquí',
            (new InvoiceGenerated($interna))->toWhatsApp($interna->contract->client)->body,
        );
    }
}
