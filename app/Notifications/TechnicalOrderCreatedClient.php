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
 * Aviso al cliente de que se creó una orden técnica para su servicio.
 */
class TechnicalOrderCreatedClient extends Notification implements ShouldQueue
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
            'Recibimos su solicitud de servicio',
            [
                'titulo' => 'Recibimos su solicitud',
                'preheader' => 'Orden N.º ' . $this->order->id . ' registrada.',
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'Registramos su solicitud y ya está en cola de atención. Un técnico se pondrá en contacto o lo visitará próximamente.',
                ],
                'datos' => array_filter([
                    'Número de orden' => $this->order->id,
                    'Tipo de solicitud' => $this->order->detail ?: 'Atención técnica',
                    'Dirección' => $this->order->contract?->address,
                    'Fecha de registro' => $this->order->created_at?->format('d/m/Y H:i'),
                ]),
                'cierre' => 'Le avisaremos por este mismo medio cuando el servicio quede resuelto.',
            ],
            $this->order->branch,
        );
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $detalle = $this->order->detail ?: 'atención técnica';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, recibimos su solicitud de servicio ({$detalle}). Pronto lo atenderemos y le avisaremos cuando quede resuelta. 🛠️"
        )->template('orden_creada_cliente', [$notifiable->name, $detalle]);
    }
}
