<?php

namespace App\Notifications;

use App\Models\TechnicalOrder;
use App\Notifications\Concerns\ArmaCorreo;
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
    use ArmaCorreo;
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
        return $this->correo(
            'Su servicio quedó resuelto',
            [
                'titulo' => 'Su servicio quedó resuelto',
                'preheader' => 'Orden N.º ' . $this->order->id . ' finalizada.',
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'Le confirmamos que la orden de servicio fue atendida y cerrada por nuestro equipo técnico.',
                ],
                'datos' => array_filter([
                    'Número de orden' => $this->order->id,
                    'Tipo de servicio' => $this->order->detail ?: 'Atención técnica',
                    'Solución aplicada' => $this->order->solution,
                    'Fecha de cierre' => $this->order->updated_at?->format('d/m/Y H:i'),
                ]),
                'aviso' => [
                    'tipo' => 'exito',
                    'texto' => 'Si el inconveniente persiste, contáctenos y con gusto volvemos a revisarlo.',
                ],
                'cierre' => 'Gracias por su paciencia y por confiar en nosotros.',
            ],
            $this->order->branch,
            'exito',
        );
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $detalle = $this->order->detail ?: 'atención técnica';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, su orden de servicio ({$detalle}) quedó FINALIZADA. ✅ Si algo sigue fallando, escríbanos. ¡Gracias!"
        )->template('orden_finalizada_cliente', [$notifiable->name, $detalle]);
    }
}
