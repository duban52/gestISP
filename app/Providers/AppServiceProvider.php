<?php

namespace App\Providers;

use App\Billing\Dian\Transport\DianTransport;
use App\Billing\Dian\Transport\FakeDianTransport;
use App\Billing\Dian\Transport\SoapDianTransport;
use App\Models\Branch;
use App\Notifications\WhatsApp\LogGateway;
use App\Notifications\WhatsApp\MetaCloudGateway;
use App\Notifications\WhatsApp\WhatsAppGateway;
use App\Services\OltSshService;
use App\Tenancy\CurrentContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El contexto de empresa: una instancia por peticion. De aqui
        // saca el global scope "quien esta mirando". Se registra como
        // singleton para poder sustituirlo en las pruebas sin tener
        // que montar una sesion.
        $this->app->singleton(CurrentContext::class);

        $this->app->bind(OltSshService::class, function ($app) {
            return new OltSshService();
        });

        // Como se le manda un documento a la DIAN.
        //
        // Por defecto el SIMULADO, y a proposito: mientras no haya una
        // URL configurada -la expone la propia DIAN en el catalogo del
        // facturador- no hay a donde mandar nada. Un transporte que
        // dijera «aceptado» sin haber hablado con nadie dejaria
        // documentos marcados como validados por la DIAN que la DIAN no
        // ha visto nunca.
        //
        // Con 'fake' se fuerza el simulado aunque haya endpoint, que es
        // lo que se quiere en un entorno de pruebas apuntando a una
        // copia de la base de produccion.
        $this->app->bind(DianTransport::class, function () {
            $forzado = config('dian.transport', 'auto');

            if ($forzado === 'fake' || blank(config('dian.endpoint'))) {
                return new FakeDianTransport();
            }

            return new SoapDianTransport();
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
    /**
     * Pone `$mostrarSucursal` al alcance de todas las vistas.
     *
     * Los listados tienen que decir de qué sede es cada fila cuando se
     * trabaja en panel consolidado — si no, se ven contratos, facturas
     * y órdenes de varias sedes mezclados y sin forma de distinguirlos,
     * que es justo donde una confusión sale cara.
     *
     * Va en un composer y no pasándolo desde cada controlador por dos
     * motivos: son más de veinte listados repartidos por otros tantos
     * controladores, y añadir uno nuevo y olvidarse de la variable
     * dejaría la vista rota (`Undefined variable`) en vez de
     * simplemente sin columna.
     *
     * El criterio lo decide CurrentContext::mostrarSucursal(), no este
     * método: aquí solo se reparte.
     */
    private function compartirSiSeMuestraLaSucursal(): void
    {
        View::composer('*', function ($view) {
            $view->with('mostrarSucursal', app(CurrentContext::class)->mostrarSucursal());
        });
    }

    public function boot(): void
    {
        // Laravel 10 pagina con marcado de Tailwind por defecto, y todo
        // el panel es AdminLTE sobre Bootstrap 4: sin esta línea los
        // enlaces de página salen sueltos y desalineados en las siete
        // pantallas paginadas del sistema (trazabilidad, clientes,
        // pagos, movimientos de caja, categorías y sesiones).
        Paginator::useBootstrapFour();

        $this->resolverPermisosPorRolDeLaSesion();
        $this->personalizarCorreoDeContrasena();
        $this->compartirSiSeMuestraLaSucursal();
    }

    /**
     * Hace que @can mire el rol de la SUCURSAL ACTIVA.
     *
     * EL PROBLEMA
     * -----------
     * Un usuario puede tener un rol distinto en cada sucursal: eso es
     * lo que guarda user_branch.role_id, y es el criterio que aplican
     * el middleware check.permission y el filtro del menú.
     *
     * Pero @can en las vistas no usaba ese rol: sin un Gate::before,
     * Laravel delega en spatie/laravel-permission, que evalúa los
     * roles GLOBALES del usuario. Los dos pueden divergir — alguien
     * con rol alto en una sucursal veía botones de acciones que en la
     * sucursal activa no le corresponden.
     *
     * Hasta ahora se compensaba exigiendo el permiso también en el
     * controlador, que es lo que de verdad protegía. Con varias
     * empresas eso ya no basta: el botón de más deja de ser un detalle
     * cosmético cuando lo que hay detrás son datos de otro
     * contribuyente.
     *
     * QUÉ HACE
     * --------
     * Resuelve el permiso contra el rol de la sesión. Devolver null
     * (y no false) cuando no hay rol es deliberado: deja que Laravel
     * siga con sus comprobaciones normales en vez de negar todo, que
     * es lo que romperia el login y el restablecimiento de contraseña.
     */
    private function resolverPermisosPorRolDeLaSesion(): void
    {
        Gate::before(function ($usuario, string $permiso) {
            $rolId = session('current_role_id');

            if (!$rolId) {
                return null;
            }

            $rol = Role::find($rolId);

            // checkPermissionTo y no hasPermissionTo: este NO lanza
            // excepción si el permiso no existe en la base. Un permiso
            // declarado en el código pero ausente en la BD debe negar
            // el acceso, nunca romper la aplicación con un 500. Es el
            // mismo criterio que ya usa CheckPermission.
            return $rol && $rol->checkPermissionTo($permiso) ? true : null;
        });
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
