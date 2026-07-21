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
 * Recordatorio al cliente unos días antes de que venza su factura.
 */
class InvoiceDueSoon extends Notification implements ShouldQueue
{
    use Queueable;
    use RespetaCanales;

    public function __construct(
        private readonly Invoice $invoice,
        private readonly int $diasRestantes,
    ) {
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
            ->subject('Su factura vence pronto')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line("Le recordamos que su factura {$this->invoice->displayNumber()} vence en {$this->diasRestantes} día(s).")
            ->line('Saldo pendiente: ' . $total)
            ->line('Vencimiento: ' . optional($this->invoice->due_date)->format('d/m/Y'))
            ->line('Pague a tiempo para evitar la suspensión del servicio.')
            ->salutation('Gracias, ' . $sucursal);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $total = '$' . number_format((float) $this->invoice->pending_invoice_amount, 0, ',', '.');
        $vence = optional($this->invoice->due_date)->format('d/m/Y') ?? 'pronto';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, su factura {$this->invoice->displayNumber()} vence en {$this->diasRestantes} día(s) ({$vence}). Saldo: {$total}. Pague a tiempo para no interrumpir su servicio. 🙌"
        )->template('factura_por_vencer', [
            $notifiable->name,
            $this->invoice->displayNumber(),
            (string) $this->diasRestantes,
            $total,
        ]);
    }
}
