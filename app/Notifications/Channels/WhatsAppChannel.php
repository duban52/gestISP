<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\WhatsAppMessage;
use App\Notifications\WhatsApp\WhatsAppGateway;
use App\Support\PhoneNumber;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Canal de notificaciones "whatsapp".
 *
 * Laravel llama a este canal cuando una notificación lo incluye en
 * su método via(). Aquí se obtiene el número del destinatario, se
 * normaliza y se le pide al gateway configurado que lo envíe.
 *
 * Se usa poniendo 'whatsapp' en el via() de la notificación y
 * definiendo en ella un método toWhatsApp($notifiable) que devuelva
 * un WhatsAppMessage.
 */
class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppGateway $gateway)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        // El canal puede estar apagado por configuración
        if (!config('notifications.channels.whatsapp', true)) {
            return;
        }

        if (!method_exists($notification, 'toWhatsApp')) {
            return;
        }

        /** @var WhatsAppMessage $message */
        $message = $notification->toWhatsApp($notifiable);

        // El destinatario expone su número con
        // routeNotificationFor('whatsapp') o, en su defecto, con su
        // atributo de teléfono habitual.
        $raw = $notifiable->routeNotificationFor('whatsapp', $notification)
            ?? $notifiable->number_phone
            ?? null;

        $to = PhoneNumber::forWhatsApp($raw);

        if (!$to) {
            Log::info('WhatsApp: destinatario sin número válido, se omite.', [
                'notificacion' => $notification::class,
                'destinatario' => $notifiable::class . ':' . ($notifiable->id ?? '?'),
            ]);

            return;
        }

        $this->gateway->send($to, $message);
    }
}
