<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Notifications\Concerns\ArmaCorreo;
use App\Notifications\Concerns\RespetaCanales;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente de que se generó una nueva factura.
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

        return $this->correo(
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
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $total = '$' . number_format((float) $this->invoice->total, 0, ',', '.');
        $vence = optional($this->invoice->due_date)->format('d/m/Y') ?? 'la fecha indicada';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, se generó su factura {$this->invoice->displayNumber()} por {$total}. Vence el {$vence}. ¡Gracias!"
        )->template('factura_generada', [
            $notifiable->name,
            $this->invoice->displayNumber(),
            $total,
            $vence,
        ]);
    }
}
