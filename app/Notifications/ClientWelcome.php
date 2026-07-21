<?php

namespace App\Notifications;

use App\Models\Contract;
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
        $sucursal = $this->contract->branch?->name ?? config('app.name');

        return (new MailMessage)
            ->subject('¡Bienvenido a ' . $sucursal . '!')
            ->greeting('Hola ' . $notifiable->name . ',')
            ->line('¡Gracias por confiar en nosotros! Su contrato ya quedó registrado.')
            ->line('Plan contratado: ' . ($this->contract->plan?->name ?? 'servicio de Internet') . '.')
            ->line('Cualquier novedad la recibirá por este medio. Estamos para servirle.')
            ->salutation('Un saludo, ' . $sucursal);
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
