<?php

namespace App\Listeners;

use App\Billing\Delivery\InvoicePackage;
use App\Billing\Events\ElectronicDocumentAccepted;
use App\Models\ElectronicDocument;
use App\Notifications\ElectronicInvoiceDelivered;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le entrega al cliente su factura electrónica cuando la DIAN la valida.
 *
 * LA GUARDA CONTRA ENVIAR DOS VECES ESTÁ AQUÍ
 * -------------------------------------------
 * Y hace falta de verdad. La transmisión se reintenta, los trabajos de
 * la cola se reencolan solos si fallan, y `dian:transmitir` se puede
 * correr a mano tantas veces como uno quiera. Sin esta guarda, cualquiera
 * de esas tres cosas le manda al cliente la misma factura otra vez.
 *
 * Se marca `delivered_at` ANTES de notificar, no después. Si se marcara
 * después y el proceso muriera entre medias, el reintento volvería a
 * enviarla. Prefiere fallar por defecto —no entregar— antes que
 * duplicar: una factura que no llegó se detecta y se reenvía; una que
 * llegó dos veces ya está en el buzón del cliente.
 *
 * SOLO FACTURAS
 * -------------
 * Las notas crédito y débito también se transmiten y también se aceptan,
 * pero su entrega al adquiriente no está construida todavía. Aquí se
 * ignoran en silencio en vez de reventar.
 */
class DeliverElectronicInvoice
{
    public function __construct(
        private readonly InvoicePackage $paquete,
    ) {
    }

    public function handle(ElectronicDocumentAccepted $evento): void
    {
        $documento = $evento->document;

        if ($documento->delivered_at) {
            return;
        }

        $factura = $documento->invoice;

        if (!$factura) {
            return;
        }

        $cliente = $factura->contract?->client;

        if (!$cliente) {
            Log::warning('Factura validada sin cliente al que entregársela.', [
                'documento' => $documento->id,
                'factura' => $factura->id,
            ]);

            return;
        }

        if (!$this->paquete->disponiblePara($factura)) {
            Log::warning('Factura validada pero sin paquete que entregar.', [
                'documento' => $documento->id,
                'factura' => $factura->id,
            ]);

            return;
        }

        // Antes de notificar: véase el comentario de la clase.
        $documento->forceFill(['delivered_at' => now()])->save();

        try {
            $cliente->notify(new ElectronicInvoiceDelivered(
                $factura->load(['contract.client', 'contract.branch', 'invoice_items']),
            ));
        } catch (Throwable $e) {
            // Se devuelve la marca: si no se pudo ni encolar, esto no se
            // entregó, y hay que poder reintentarlo.
            $documento->forceFill(['delivered_at' => null])->save();

            Log::error('No se pudo entregar la factura electrónica al cliente.', [
                'documento' => $documento->id,
                'factura' => $factura->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
