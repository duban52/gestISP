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
 * Aviso al cliente de que su factura se venció.
 */
class InvoiceOverdue extends Notification implements ShouldQueue
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
        $total = '$' . number_format((float) $this->invoice->pending_invoice_amount, 0, ',', '.');

        return (new MailMessage)
            ->subject('Su factura está vencida')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line("Su factura {$this->invoice->displayNumber()} se encuentra VENCIDA.")
            ->line('Saldo pendiente: ' . $total)
            ->line('Le pedimos ponerse al día para restablecer o mantener su servicio.')
            ->salutation('Gracias, ' . $sucursal);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $total = '$' . number_format((float) $this->invoice->pending_invoice_amount, 0, ',', '.');

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, su factura {$this->invoice->displayNumber()} está VENCIDA. Saldo: {$total}. Póngase al día para no interrumpir su servicio. Estamos para ayudarle."
        )->template('factura_vencida', [
            $notifiable->name,
            $this->invoice->displayNumber(),
            $total,
        ]);
    }
}
