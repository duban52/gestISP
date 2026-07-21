<?php

namespace App\Notifications;

use App\Models\Invoice;
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

    public function __construct(private readonly Invoice $invoice)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sucursal = $this->invoice->branch?->name ?? config('app.name');

        return (new MailMessage)
            ->subject('Nueva factura ' . $this->invoice->displayNumber())
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Se generó su factura ' . $this->invoice->displayNumber() . '.')
            ->line('Valor: $' . number_format((float) $this->invoice->total, 0, ',', '.'))
            ->line('Fecha de vencimiento: ' . optional($this->invoice->due_date)->format('d/m/Y'))
            ->line('Puede acercarse a nuestros puntos de pago o comunicarse con nosotros para cancelarla.')
            ->salutation('Gracias, ' . $sucursal);
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
