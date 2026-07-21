<?php

namespace App\Notifications;

use App\Models\TechnicalOrder;
use App\Notifications\Concerns\RespetaCanales;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente de que se creó una orden técnica para su servicio.
 */
class TechnicalOrderCreatedClient extends Notification implements ShouldQueue
{
    use Queueable;
    use RespetaCanales;

    public function __construct(private readonly TechnicalOrder $order)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sucursal = $this->order->branch?->name ?? config('app.name');

        return (new MailMessage)
            ->subject('Recibimos su solicitud de servicio')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Registramos una orden de servicio: ' . ($this->order->detail ?: 'atención técnica') . '.')
            ->line('Pronto un técnico se pondrá en contacto o lo visitará. Le avisaremos cuando quede resuelta.')
            ->salutation('Gracias, ' . $sucursal);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $detalle = $this->order->detail ?: 'atención técnica';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, recibimos su solicitud de servicio ({$detalle}). Pronto lo atenderemos y le avisaremos cuando quede resuelta. 🛠️"
        )->template('orden_creada_cliente', [$notifiable->name, $detalle]);
    }
}
