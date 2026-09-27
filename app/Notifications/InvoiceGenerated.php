<?php

namespace App\Notifications;

use App\Billing\Delivery\InvoiceDownloadLink;
use App\Billing\Delivery\InvoicePdf;
use App\Billing\Services\ElectronicInvoicingDecider;
use App\Models\Invoice;
use App\Notifications\Concerns\ArmaCorreo;
use App\Notifications\Concerns\HablaPorLaEmpresa;
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
    use HablaPorLaEmpresa;

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

        // LOS SIETE HUECOS DE `factura_generada`, EN ORDEN
        // -------------------------------------------------
        //   {{1}} cliente · {{2}} número · {{3}} valor · {{4}} vence
        //   {{5}} enlace  · {{6}} empresa · {{7}} servicio
        //
        // El orden es el de la plantilla aprobada en Meta y no se puede
        // tocar aquí solo: Meta exige que el número de parámetros
        // coincida EXACTAMENTE, y si no, rechaza el envío entero y el
        // cliente se queda sin aviso. Solo una línea en el log lo
        // cuenta. Antes esto costó una mañana.
        //
        // El enlace va SIEMPRE, y por eso ya no hay interruptor: el
        // hueco {{5}} existe en la plantilla, así que omitirlo
        // desplazaría la empresa y el servicio a los huecos de al lado.
        $parametros = [
            $notifiable->name,
            $this->invoice->displayNumber(),
            $total,
            $vence,
            $this->enlaceParaLaPlantilla($enlace),
            $this->nombreDeLaEmpresa($this->invoice->branch),
            $this->servicioFacturado(),
        ];

        $mensaje = WhatsAppMessage::make($cuerpo)->template('factura_generada', $parametros);

        // El PDF mismo: la pasarela decide si puede mandarlo —como
        // cabecera de la plantilla o como documento con pie— según lo
        // que la plantilla aprobada permita.
        return $enlace
            ? $mensaje->document($enlace, $this->nombreDelArchivo())
            : $mensaje;
    }

    /**
     * Qué servicio se le está cobrando.
     *
     * Sale del plan del contrato. Si la factura no cuelga de un
     * contrato con plan —un cargo suelto, una factura importada— no se
     * puede dejar vacío: Meta rechaza un parámetro en blanco y el
     * cliente se queda sin el aviso entero.
     */
    private function servicioFacturado(): string
    {
        return $this->invoice->contract?->plan?->name ?: 'servicio de Internet';
    }

    /** Con el que le llega el archivo al cliente. */
    private function nombreDelArchivo(): string
    {
        return 'factura-' . str_replace(['/', ' '], '-', (string) $this->invoice->displayNumber()) . '.pdf';
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
