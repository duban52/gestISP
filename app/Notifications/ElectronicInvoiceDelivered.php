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
        // QUIEN FACTURA ES LA EMPRESA, NO LA DIAN.
        //
        // Antes este correo hablaba de la DIAN en el asunto, en el
        // titulo y en dos parrafos. Al cliente le importa quien le
        // cobra, cuanto y hasta cuando; que el documento este validado
        // se da por supuesto y basta con decirlo una vez.
        $emisor = $this->emisor();

        $correo = $this->correo(
            $emisor . ' — Factura electrónica ' . $this->invoice->displayNumber(),
            [
                'titulo' => $emisor . ' ha emitido su factura electrónica',
                'preheader' => 'Factura ' . $this->invoice->displayNumber() . ' por ' . $this->pesos($this->invoice->total),
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'Adjuntamos su factura electrónica. El archivo comprimido contiene el documento en formato XML y la representación gráfica en PDF.',
                    'Conserve estos archivos: el XML es el documento con validez fiscal, y el PDF es su representación impresa.',
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

        $cuerpo = "Hola {$notifiable->name}, {$this->emisor()} emitió su factura electrónica {$this->invoice->displayNumber()} por {$total}.";
        $cuerpo .= $enlace ? " Descárguela aquí: {$enlace}" : ' Le enviamos los archivos por correo.';

        // Se reutiliza la MISMA plantilla que el aviso de factura.
        // Registrar una plantilla nueva en Meta es un trámite de días, y
        // este mensaje dice casi lo mismo: no vale la pena bloquear la
        // entrega por eso.
        // El cuarto hueco de la plantilla es el VENCIMIENTO. Aqui iba
        // «validada por la DIAN», que en el mensaje real habria salido
        // como «Vence el validada por la DIAN».
        $parametros = [
            $notifiable->name,
            $this->invoice->displayNumber(),
            $total,
            optional($this->invoice->due_date)->format('d/m/Y') ?? 'la fecha indicada',
        ];

        // COMPARTIR PLANTILLA OBLIGA A COMPARTIR EL NÚMERO DE HUECOS.
        //
        // Esto mandaba cuatro parámetros siempre. En cuanto la plantilla
        // pase a tener cinco, Meta rechazaría ESTE envío —el de la
        // factura ya validada, justo el que más importa— mientras el
        // aviso de generación seguiría funcionando. Un fallo a medias es
        // peor que uno entero: nadie lo nota.
        if (config('notifications.whatsapp.meta.invoice_link_in_template', false)) {
            $parametros[] = $this->fraseDeDescarga($enlace);
        }

        return WhatsAppMessage::make($cuerpo)->template('factura_generada', $parametros);
    }

    /**
     * El nombre con el que la empresa se presenta al cliente.
     *
     * El comercial si lo tiene; si no, la razon social. Ultimo recurso,
     * el nombre de la sucursal — un correo sin remitente reconocible
     * parece basura.
     */
    private function emisor(): string
    {
        $sucursal = $this->invoice->branch;

        return $sucursal?->company?->nombreVisible()
            ?: ($sucursal?->name ?: config('app.name'));
    }

    /**
     * La frase del quinto hueco de la plantilla.
     *
     * CUANDO LA PLANTILLA TIENE CINCO, SIEMPRE VAN CINCO.
     * ---------------------------------------------------
     * Meta exige que el número de parámetros coincida EXACTAMENTE con
     * el de la plantilla aprobada. Mandar cuatro a una de cinco falla
     * igual que mandar cinco a una de cuatro, y el cliente se queda sin
     * aviso. Por eso, con el interruptor encendido, este método siempre
     * devuelve algo.
     *
     * Es una frase entera y no la URL pelada porque el enlace puede no
     * poder armarse —`APP_URL` mal puesta, por ejemplo—. Con la URL
     * suelta, el hueco quedaría vacío y Meta rechaza los parámetros
     * vacíos; con una frase, el mensaje sigue teniendo sentido.
     */
    private function fraseDeDescarga(?string $enlace): string
    {
        return $enlace
            ? 'Descárguela aquí: ' . $enlace
            : 'Le enviamos los archivos a su correo.';
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
