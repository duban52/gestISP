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
    use ArmaCorreo;
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
        $cliente = $this->order->contract?->client;

        return $this->correo(
            'Nueva orden asignada: N.º ' . $this->order->id,
            [
                'titulo' => 'Se le asignó una orden técnica',
                'preheader' => ($this->order->detail ?: 'Atención técnica') . ' — ' . ($this->order->contract?->address ?? ''),
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'Se le asignó una nueva orden de servicio. Revise el detalle en el sistema y coordine la visita.',
                ],
                'datos' => array_filter([
                    'Número de orden' => $this->order->id,
                    'Tipo de trabajo' => $this->order->detail ?: 'Atención técnica',
                    'Cliente' => $cliente ? trim($cliente->name . ' ' . $cliente->last_name) : null,
                    'Teléfono' => $cliente?->number_phone,
                    'Dirección' => $this->order->contract?->address,
                    'Barrio' => $this->order->contract?->neighborhood,
                    'Comentario inicial' => $this->order->initial_comment,
                ]),
                'accion' => [
                    'texto' => 'Ver mis órdenes',
                    'url' => route('technicals_orders.my_technical_orders'),
                ],
            ],
            $this->order->branch,
        );
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $detalle = $this->order->detail ?: 'atención técnica';
        $direccion = $this->order->contract?->address ?: 'consultar en el sistema';

        // De quién es la orden. El técnico sale a la calle con esto:
        // sin el nombre del cliente tiene que abrir el sistema para
        // saber por quién preguntar en la puerta.
        $cliente = $this->order->contract?->client;
        $nombreCliente = trim(($cliente?->name ?? '') . ' ' . ($cliente?->last_name ?? '')) ?: 'sin contrato asociado';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, se le asignó una orden: {$detalle} para el cliente: {$nombreCliente}, "
            . "en la dirección: {$direccion}. Revísela en GestISP en la sección «Mis órdenes». 🔧"
        )->template('orden_asignada_tecnico', [$notifiable->name, $detalle, $direccion, $nombreCliente]);
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
