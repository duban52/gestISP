<?php

namespace App\Notifications;

use App\Billing\Delivery\InvoiceDownloadLink;
use App\Billing\Delivery\InvoicePackage;
use App\Models\Invoice;
use App\Notifications\Concerns\ArmaCorreo;
use App\Notifications\Concerns\RespetaCanales;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La entrega de la factura electrónica al adquiriente.
 *
 * ESTO NO ES UN AVISO: ES LA ENTREGA
 * ----------------------------------
 * Emitir ante la DIAN y entregarle el documento al cliente son dos
 * obligaciones distintas. `InvoiceGenerated` avisa de que la factura
 * existe; esta le entrega el documento fiscal.
 *
 * Sale cuando la DIAN acepta —evento `ElectronicDocumentAccepted`—,
 * porque hasta ese momento no hay acuse que entregar y el PDF diría
 * «pendiente de validación».
 *
 * QUÉ LLEVA
 * ---------
 * Un ZIP con la factura firmada (XML), el acuse de la DIAN (XML) y el
 * PDF. Véase `InvoicePackage` para por qué los tres.
 *
 * SI EL PAQUETE FALLA, NO SE MANDA NADA
 * -------------------------------------
 * Al revés que en `InvoiceGenerated`, donde el aviso vale por sí solo
 * aunque falte el adjunto. Aquí el adjunto ES el mensaje: un correo que
 * dice «le entregamos su factura validada» sin factura dentro es peor
 * que un correo que no llega, porque el cliente cree que ya la tiene.
 */
class ElectronicInvoiceDelivered extends Notification implements ShouldQueue
{
    use Queueable;
    use RespetaCanales;
    use ArmaCorreo;

    public function __construct(private readonly Invoice $invoice)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $correo = $this->correo(
            'Factura electrónica ' . $this->invoice->displayNumber() . ' validada por la DIAN',
            [
                'titulo' => 'Su factura electrónica fue validada',
                'preheader' => 'Factura ' . $this->invoice->displayNumber() . ', validada por la DIAN',
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'La DIAN validó su factura electrónica. Adjuntamos el archivo comprimido con el documento en formato XML, la respuesta de la DIAN y la representación gráfica en PDF.',
                    'Conserve estos archivos: el XML es el documento con validez fiscal, y la respuesta de la DIAN es lo que le permite comprobar su validación.',
                ],
                'datos' => array_filter([
                    'Número de factura' => $this->invoice->displayNumber(),
                    'Valor' => $this->pesos($this->invoice->total),
                    'Período facturado' => $this->invoice->billed_month_name,
                ]),
                'cierre' => 'Si tiene alguna inquietud sobre esta factura, comuníquese con nosotros.',
            ],
            $this->invoice->branch,
        );

        return $correo->attachData(
            app(InvoicePackage::class)->bytes($this->invoice),
            app(InvoicePackage::class)->nombre($this->invoice),
            ['mime' => 'application/zip'],
        );
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $total = '$' . number_format((float) $this->invoice->total, 0, ',', '.');
        $enlace = $this->enlaceDeDescarga();

        $cuerpo = "Hola {$notifiable->name}, su factura {$this->invoice->displayNumber()} por {$total} fue validada por la DIAN.";
        $cuerpo .= $enlace ? " Descárguela aquí: {$enlace}" : ' Le enviamos los archivos por correo.';

        // Se reutiliza la MISMA plantilla que el aviso de factura, con
        // los mismos cuatro parámetros. Registrar una plantilla nueva en
        // Meta es un trámite de días, y este mensaje dice casi lo mismo:
        // no vale la pena bloquear la entrega por eso.
        return WhatsAppMessage::make($cuerpo)->template('factura_generada', [
            $notifiable->name,
            $this->invoice->displayNumber(),
            $total,
            'validada por la DIAN',
        ]);
    }

    /** El enlace firmado al paquete. Sin él, el mensaje sale igual. */
    private function enlaceDeDescarga(): ?string
    {
        try {
            return app(InvoiceDownloadLink::class)->paraElPaquete($this->invoice);
        } catch (Throwable $e) {
            Log::error('No se pudo armar el enlace del paquete de la factura.', [
                'factura' => $this->invoice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
