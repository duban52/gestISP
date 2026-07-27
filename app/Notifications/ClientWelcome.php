<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Notifications\Concerns\ArmaCorreo;
use App\Notifications\Concerns\RespetaCanales;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bienvenida al cliente cuando se le crea/asigna un contrato.
 *
 * Se envía por correo y WhatsApp. Va en cola (ShouldQueue) para no
 * demorar la creación del contrato mientras se contacta al proveedor
 * de correo o de WhatsApp.
 */
class ClientWelcome extends Notification implements ShouldQueue
{
    use Queueable;
    use ArmaCorreo;
    use RespetaCanales;

    public function __construct(private readonly Contract $contract)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->canales($notifiable, ['mail', 'whatsapp']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sucursal = $this->contract->branch;
        $nombreSucursal = $sucursal?->name ?? config('app.name');

        return $this->correo(
            '¡Bienvenido a ' . $nombreSucursal . '!',
            [
                'titulo' => '¡Bienvenido! Su servicio ya está registrado',
                'preheader' => 'Gracias por confiar en ' . $nombreSucursal . '.',
                'saludo' => 'Hola ' . $notifiable->name . ',',
                'parrafos' => [
                    '¡Gracias por confiar en nosotros! Su contrato quedó registrado y en breve coordinaremos la instalación de su servicio.',
                ],
                'datos' => array_filter([
                    'Número de contrato' => $this->contract->numero_visible,
                    'Plan contratado' => $this->contract->plan?->name,
                    'Dirección del servicio' => $this->contract->address,
                ]),
                'aviso' => [
                    'tipo' => 'exito',
                    'texto' => 'Guarde su número de contrato: se lo pediremos cuando necesite soporte o quiera consultar el estado de su cuenta.',
                ],
                'cierre' => 'Cualquier novedad sobre su servicio y sus facturas le llegará por este medio. Estamos para servirle.',
            ],
            $sucursal,
            'exito',
        );
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $sucursal = $this->contract->branch?->name ?? config('app.name');
        $plan = $this->contract->plan?->name ?? 'servicio de Internet';

        return WhatsAppMessage::make(
            "¡Hola {$notifiable->name}! 🎉 Bienvenido a {$sucursal}. Su contrato del plan {$plan} ya quedó activo. ¡Gracias por confiar en nosotros!"
        )->template('bienvenida_cliente', [$notifiable->name, $sucursal, $plan]);
    }
}
