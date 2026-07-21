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
 * Aviso al cliente de que su orden técnica quedó finalizada.
 */
class TechnicalOrderFinishedClient extends Notification implements ShouldQueue
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
            ->subject('Su servicio quedó resuelto')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Su orden de servicio (' . ($this->order->detail ?: 'atención técnica') . ') fue finalizada.')
            ->when(filled($this->order->solution), fn ($m) => $m->line('Solución: ' . $this->order->solution))
            ->line('Si el inconveniente persiste, contáctenos y con gusto le ayudamos.')
            ->salutation('Gracias, ' . $sucursal);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $detalle = $this->order->detail ?: 'atención técnica';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, su orden de servicio ({$detalle}) quedó FINALIZADA. ✅ Si algo sigue fallando, escríbanos. ¡Gracias!"
        )->template('orden_finalizada_cliente', [$notifiable->name, $detalle]);
    }
}
