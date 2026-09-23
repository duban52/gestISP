<?php

namespace App\Notifications;

use App\Billing\Delivery\InvoiceDownloadLink;
use App\Billing\Delivery\InvoicePdf;
use App\Billing\Services\ElectronicInvoicingDecider;
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
 * Aviso al cliente de que se generó una nueva factura.
 *
 * LLEVA EL PDF ADJUNTO SOLO SI LA FACTURA ES INTERNA
 * --------------------------------------------------
 * Esta notificación sale del evento `InvoiceIssued`, que dispara al
 * NUMERAR la factura — antes de transmitirla. Para una factura interna
 * eso ya es el documento definitivo, y se adjunta sin más.
 *
 * Para una electrónica, en ese instante todavía no está validada: su PDF
 * diría «Pendiente de validación por la DIAN», y mandarle al cliente un
 * documento que dice eso es peor que no mandarle ninguno. La electrónica
 * recibe su paquete completo —factura firmada, acuse de la DIAN y PDF—
 * cuando la DIAN la acepta, que es otra notificación.
 */
class InvoiceGenerated extends Notification implements ShouldQueue
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
        $vence = optional($this->invoice->due_date)->format('d/m/Y');

        $correo = $this->correo(
            'Nueva factura ' . $this->invoice->displayNumber(),
            [
                'titulo' => 'Su factura del mes ya está disponible',
                'preheader' => 'Factura ' . $this->invoice->displayNumber() . ' por ' . $this->pesos($this->invoice->total),
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'Le informamos que se generó su factura correspondiente al servicio de Internet. A continuación encontrará el detalle.',
                ],
                'destacado' => [
                    'etiqueta' => 'Valor a pagar',
                    'valor' => $this->pesos($this->invoice->total),
                    'nota' => $vence ? 'Fecha límite de pago: ' . $vence : null,
                ],
                'datos' => array_filter([
                    'Número de factura' => $this->invoice->displayNumber(),
                    'Período facturado' => $this->invoice->billed_month_name,
                    'Fecha de vencimiento' => $vence,
                ]),
                'cierre' => 'Puede pagar en nuestros puntos de atención o comunicarse con nosotros para conocer los demás medios de pago disponibles.',
            ],
            $this->invoice->branch,
        );

        return $this->esElectronica() ? $correo : $this->conFactura($correo);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $total = '$' . number_format((float) $this->invoice->total, 0, ',', '.');
        $vence = optional($this->invoice->due_date)->format('d/m/Y') ?? 'la fecha indicada';

        // EL ENLACE VA SIEMPRE, sea electrónica o interna (pedido del
        // usuario, 2026-09-23). Antes se omitía en la electrónica
        // porque en ese momento la DIAN todavía no la ha validado; el
        // cliente prefiere tener su factura de una vez, y cuando la DIAN
        // la acepta le llega además el paquete completo.
        $enlace = $this->enlaceDeDescarga();

        $cuerpo = "Hola {$notifiable->name}, se generó su factura {$this->invoice->displayNumber()} por {$total}. Vence el {$vence}.";
        $cuerpo .= $enlace ? " Descárguela aquí: {$enlace}" : ' ¡Gracias!';

        $parametros = [
            $notifiable->name,
            $this->invoice->displayNumber(),
            $total,
            $vence,
        ];

        // EL QUINTO PARÁMETRO SOLO SI LA PLANTILLA YA LO TIENE.
        //
        // La plantilla aprobada en Meta tiene cuatro huecos; mandarle un
        // quinto hace que Meta rechace el envío entero y el cliente se
        // quede sin aviso. Por eso el interruptor arranca apagado: hay
        // que actualizar y aprobar la plantilla ANTES de encenderlo.
        //
        // Y al revés también: con el interruptor encendido van SIEMPRE
        // cinco, aunque no haya enlace. Antes esto decía `if ($enlace
        // && ...)`, así que una factura electrónica —que a propósito no
        // lleva enlace aquí— o un fallo al armarlo mandaban cuatro
        // parámetros a una plantilla de cinco: el mismo rechazo, por el
        // otro lado.
        if (config('notifications.whatsapp.meta.invoice_link_in_template', false)) {
            $parametros[] = $this->enlaceParaLaPlantilla($enlace);
        }

        $mensaje = WhatsAppMessage::make($cuerpo)->template('factura_generada', $parametros);

        // El PDF mismo: la pasarela decide si puede mandarlo —como
        // cabecera de la plantilla o como documento con pie— según lo
        // que la plantilla aprobada permita.
        return $enlace
            ? $mensaje->document($enlace, $this->nombreDelArchivo())
            : $mensaje;
    }

    /** Con el que le llega el archivo al cliente. */
    private function nombreDelArchivo(): string
    {
        return 'factura-' . str_replace(['/', ' '], '-', (string) $this->invoice->displayNumber()) . '.pdf';
    }

    /**
     * El quinto hueco de la plantilla: LA URL PELADA.
     *
     * La plantilla aprobada ya dice «Descárgala aquí: {{5}} ¡Gracias!»,
     * así que el parámetro es solo el enlace; mandar la frase entera
     * haría que el cliente leyera «Descárgala aquí: Descárguela aquí:
     * https://…».
     *
     * NUNCA VACÍO: Meta rechaza los parámetros en blanco y el cliente
     * se quedaría sin aviso. Si el enlace no se pudo armar —`APP_URL`
     * mal puesta, por ejemplo— va la dirección del sitio, que al menos
     * lleva a alguna parte.
     */
    private function enlaceParaLaPlantilla(?string $enlace): string
    {
        return $enlace ?: rtrim((string) config('app.url'), '/');
    }

    /**
     * El enlace firmado para descargar el PDF.
     *
     * Si falla —por ejemplo, porque `APP_URL` está mal— el mensaje sale
     * sin enlace en vez de no salir. Mismo criterio que el adjunto del
     * correo: el aviso vale por sí solo.
     */
    private function enlaceDeDescarga(): ?string
    {
        try {
            return app(InvoiceDownloadLink::class)->para($this->invoice);
        } catch (Throwable $e) {
            Log::error('No se pudo armar el enlace de descarga de la factura.', [
                'factura' => $this->invoice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** ¿Esta factura va a la DIAN? */
    private function esElectronica(): bool
    {
        return $this->invoice->document_kind === ElectronicInvoicingDecider::ELECTRONICO;
    }

    /**
     * Adjunta la representación gráfica al correo.
     *
     * Se genera al vuelo y no se guarda en disco: el PDF se puede
     * rehacer siempre a partir de la factura, y almacenarlo sería
     * acumular un archivo por factura para siempre sin ganar nada.
     *
     * SI FALLA, EL AVISO SALE IGUAL, SIN ADJUNTO. Es deliberado: que el
     * cliente reciba «su factura es de $80.000 y vence el 28» vale mucho
     * más que no recibir nada porque el PDF no se pudo armar.
     */
    private function conFactura(MailMessage $correo): MailMessage
    {
        try {
            $pdf = app(InvoicePdf::class);

            return $correo->attachData(
                $pdf->bytes($this->invoice),
                $pdf->nombre($this->invoice),
                ['mime' => 'application/pdf'],
            );
        } catch (Throwable $e) {
            Log::error('No se pudo adjuntar el PDF de la factura al correo.', [
                'factura' => $this->invoice->id,
                'error' => $e->getMessage(),
            ]);

            return $correo;
        }
    }
}
