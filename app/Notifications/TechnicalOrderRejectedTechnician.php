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
 * Aviso al técnico de que el supervisor le devolvió una orden.
 *
 * OJO CON EL NOMBRE: aquí "rechazo" es el del SUPERVISOR al verificar
 * el trabajo. No confundir con TechnicalOrderController::orderReject,
 * que es cuando el TÉCNICO rechaza una orden que no puede ejecutar.
 * Son dos movimientos opuestos del mismo flujo.
 *
 * POR QUÉ EXISTE
 * --------------
 * Antes, al rechazar una orden esta volvía a "Pendiente" y salía de la
 * bandeja del técnico: nadie le decía que su trabajo se había devuelto
 * ni por qué. La orden se quedaba en el limbo hasta que alguien de
 * oficina se acordaba de reasignarla.
 *
 * El motivo del rechazo viaja EN EL MENSAJE, no solo un "revise el
 * sistema": el técnico está en la calle y necesita saber si tiene que
 * volver al sitio o solo corregir un dato.
 *
 * Va por tres canales:
 *  - mail y whatsapp: el aviso directo.
 *  - database: alimenta el contador rojo de "Mis Órdenes", igual que
 *    la asignación.
 */
class TechnicalOrderRejectedTechnician extends Notification implements ShouldQueue
{
    use Queueable;
    use ArmaCorreo;
    use RespetaCanales;

    public function __construct(
        private readonly TechnicalOrder $order,
        private readonly string $reason,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp', 'database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cliente = $this->order->contract?->client;

        return $this->correo(
            'Orden devuelta para corregir: N.º ' . $this->order->id,
            [
                'titulo' => 'Se le devolvió una orden técnica',
                'preheader' => 'Motivo: ' . $this->reason,
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    'La orden que reportó fue revisada y devuelta para que la corrija. '
                    . 'Vuelve a estar en su bandeja de "Mis Órdenes".',
                ],
                'datos' => array_filter([
                    'Número de orden' => $this->order->id,
                    'Motivo del rechazo' => $this->reason,
                    'Tipo de trabajo' => $this->order->detail ?: 'Atención técnica',
                    'Contrato' => $this->order->contract?->numero_visible,
                    'Cliente' => $cliente ? trim($cliente->name . ' ' . $cliente->last_name) : null,
                    'Teléfono' => $cliente?->number_phone,
                    'Dirección' => $this->order->contract?->address,
                    'Barrio' => $this->order->contract?->neighborhood,
                ]),
                'accion' => [
                    'texto' => 'Corregir la orden',
                    'url' => route('technicals_orders.show', $this->order->id),
                ],
            ],
            $this->order->branch,
            // Tono de aviso: no es un error del sistema, pero tampoco
            // una buena noticia, y hay que hacer algo con ella.
            'aviso',
        );
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $detalle = $this->order->detail ?: 'atención técnica';

        return WhatsAppMessage::make(
            "Hola {$notifiable->name}, se devolvió la orden N.º {$this->order->id} ({$detalle}). "
            . "Motivo: {$this->reason}. Corríjala en GestISP. ⚠️"
        )->template('orden_rechazada_tecnico', [
            $notifiable->name,
            (string) $this->order->id,
            $detalle,
            $this->reason,
        ]);
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
            'tipo' => 'orden_rechazada',
            'order_id' => $this->order->id,
            'titulo' => 'Orden devuelta para corregir',
            'detalle' => $this->reason,
            'direccion' => $this->order->contract?->address,
            'url' => route('technicals_orders.show', $this->order->id),
        ];
    }
}
