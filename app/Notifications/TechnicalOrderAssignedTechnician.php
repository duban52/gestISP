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
 * Aviso al técnico de que se le asignó una orden.
 *
 * Va por tres canales:
 *  - mail y whatsapp: el aviso directo.
 *  - database: alimenta el contador rojo en "Mis Órdenes" y el aviso
 *    del navegador. Cada notificación no leída suma al contador; se
 *    marcan leídas cuando el técnico abre su bandeja de órdenes.
 */
class TechnicalOrderAssignedTechnician extends Notification implements ShouldQueue
{
    use Queueable;
    use RespetaCanales;

    public function __construct(private readonly TechnicalOrder $order)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Se le asignó una orden técnica')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('Se le asignó una nueva orden de servicio: ' . ($this->order->detail ?: 'atención técnica') . '.')
            ->when(
                filled($this->order->contract?->address),
                fn ($m) => $m->line('Dirección: ' . $this->order->contract->address)
            )
            ->action('Ver mis órdenes', route('technicals_orders.my_technical_orders'))
            ->line('Revise el detalle en el sistema y coordine la visita.');
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $detalle = $this->order->detail ?: 'atención técnica';
        $direccion = $this->order->contract?->address ?: 'consultar en el sistema';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, se le asignó una orden: {$detalle}. Dirección: {$direccion}. Revísela en GestISP. 🔧"
        )->template('orden_asignada_tecnico', [$notifiable->name, $detalle, $direccion]);
    }

    /**
     * Datos que quedan en la tabla notifications: alimentan el
     * contador y el aviso del navegador.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'orden_asignada',
            'order_id' => $this->order->id,
            'titulo' => 'Nueva orden asignada',
            'detalle' => $this->order->detail ?: 'Atención técnica',
            'direccion' => $this->order->contract?->address,
            'url' => route('technicals_orders.my_technical_orders'),
        ];
    }
}
