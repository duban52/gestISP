<?php

namespace App\Providers;

use App\Models\Branch;
use App\Notifications\WhatsApp\LogGateway;
use App\Notifications\WhatsApp\MetaCloudGateway;
use App\Notifications\WhatsApp\WhatsAppGateway;
use App\Services\OltSshService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OltSshService::class, function ($app) {
            return new OltSshService();
        });

        // Proveedor de WhatsApp según la configuración: el conector
        // es intercambiable (log/simulado por defecto, meta en
        // producción) sin tocar el resto del código.
        $this->app->bind(WhatsAppGateway::class, function () {
            return match (config('notifications.whatsapp.driver', 'log')) {
                'meta' => new MetaCloudGateway(),
                default => new LogGateway(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->personalizarCorreoDeContrasena();
    }

    /**
     * Da al correo de restablecimiento de contraseña la misma imagen
     * que el resto de los mensajes del sistema.
     *
     * Laravel envía por defecto una plantilla genérica en inglés con
     * la marca "Laravel". Se reemplaza aquí, y no cambiando el modelo
     * User, para no interferir con el resto del flujo de acceso.
     */
    private function personalizarCorreoDeContrasena(): void
    {
        ResetPassword::toMailUsing(function ($usuario, string $token) {
            $minutos = config('auth.passwords.users.expire', 60);

            $enlace = url(route('password.reset', [
                'token' => $token,
                'email' => $usuario->getEmailForPasswordReset(),
            ], false));

            // Se usa la sucursal del usuario para que el correo llegue
            // con la marca de su operación y no con una genérica.
            $sucursal = $usuario->selected_branch_id
                ? Branch::find($usuario->selected_branch_id)
                : $usuario->branches()->first();

            return (new MailMessage)
                ->subject('Restablecer su contraseña de ' . config('app.name'))
                ->view('emails.layout', [
                    'sucursal' => $sucursal,
                    'color' => '#1F4E79',
                    'titulo' => 'Restablecer su contraseña',
                    'preheader' => 'Enlace válido por ' . $minutos . ' minutos.',
                    'saludo' => 'Hola ' . $usuario->name . ',',
                    'parrafos' => [
                        'Recibimos una solicitud para restablecer la contraseña de su cuenta en el sistema. '
                        . 'Pulse el botón para crear una contraseña nueva.',
                    ],
                    'accion' => [
                        'texto' => 'Crear contraseña nueva',
                        'url' => $enlace,
                    ],
                    'aviso' => [
                        'tipo' => 'info',
                        'texto' => 'Este enlace vence en ' . $minutos . ' minutos y solo puede usarse una vez.',
                    ],
                    'cierre' => 'Si usted no solicitó este cambio, ignore este mensaje: su contraseña seguirá siendo la misma.',
                ]);
        });
    }
}
