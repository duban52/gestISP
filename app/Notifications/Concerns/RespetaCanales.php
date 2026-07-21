<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\WhatsAppChannel;

/**
 * Arma la lista de canales de una notificación respetando los
 * interruptores de configuración y lo que el destinatario tiene
 * disponible.
 *
 * Evita repetir en cada notificación la misma lógica de "manda por
 * correo solo si el canal está activo y el cliente tiene email".
 */
trait RespetaCanales
{
    /**
     * Construye el via() a partir de los canales deseados.
     *
     * @param  object  $notifiable
     * @param  array<int, string>  $deseados  'mail', 'whatsapp', 'database'
     * @return array<int, string>
     */
    protected function canales(object $notifiable, array $deseados): array
    {
        $via = [];

        foreach ($deseados as $canal) {
            if (!config("notifications.channels.{$canal}", true)) {
                continue;
            }

            $via[] = match ($canal) {
                'mail' => filled($notifiable->routeNotificationFor('mail')) ? 'mail' : null,
                'whatsapp' => filled($notifiable->routeNotificationFor('whatsapp')) ? WhatsAppChannel::class : null,
                'database' => 'database',
                default => null,
            };
        }

        return array_values(array_filter($via));
    }
}
