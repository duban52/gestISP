<?php

namespace App\Providers;

use App\Billing\Concerns\Auditable;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Enchufa la trazabilidad a TODO el sistema.
 *
 * En vez de ir modelo por modelo poniendo un trait —que tarde o
 * temprano alguien olvida al crear uno nuevo—, se escucha con comodín
 * a los eventos de Eloquent: cualquier registro que se cree, modifique
 * o borre queda auditado, exista hoy o se añada mañana.
 *
 * Lo que NO se audita está declarado en config/audit.php: la
 * telemetría de los sondeos automáticos (potencia de miles de ONTs
 * cada 5 minutos) enterraría las acciones reales de las personas.
 */
class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!config('audit.enabled', true)) {
            return;
        }

        $this->escucharModelos();
        $this->escucharAccesos();
    }

    /**
     * Cambios en cualquier modelo del sistema.
     */
    private function escucharModelos(): void
    {
        Event::listen('eloquent.created: *', function ($evento, $datos) {
            $this->registrarModelo($datos[0] ?? null, 'created');
        });

        Event::listen('eloquent.updated: *', function ($evento, $datos) {
            $this->registrarModelo($datos[0] ?? null, 'updated');
        });

        Event::listen('eloquent.deleted: *', function ($evento, $datos) {
            $this->registrarModelo($datos[0] ?? null, 'deleted');
        });
    }

    /**
     * Entradas y salidas del sistema.
     *
     * La trazabilidad de sesiones ya guarda las suyas; aquí quedan
     * además en la bitácora general para poder revisar en un solo
     * sitio todo lo que pasó.
     */
    private function escucharAccesos(): void
    {
        Event::listen(Login::class, function (Login $evento) {
            app(AuditLogger::class)->action(
                'auth.login',
                'Inició sesión en el sistema',
                [],
                $evento->user,
                'auth',
            );
        });

        Event::listen(Logout::class, function (Logout $evento) {
            if (!$evento->user) {
                return;
            }

            app(AuditLogger::class)->action(
                'auth.logout',
                'Cerró sesión',
                [],
                $evento->user,
                'auth',
            );
        });

        Event::listen(Failed::class, function (Failed $evento) {
            app(AuditLogger::class)->action(
                'auth.failed',
                'Intento de acceso fallido',
                ['correo' => $evento->credentials['email'] ?? null],
                null,
                'auth',
            );
        });
    }

    /**
     * Decide si el cambio merece registrarse y lo escribe.
     */
    private function registrarModelo(mixed $model, string $accion): void
    {
        if (!$model instanceof Model || !$this->auditable($model)) {
            return;
        }

        $logger = app(AuditLogger::class);

        if ($accion === 'created') {
            $logger->model($model, 'created', [], $this->sinIgnorados($model, $model->getAttributes()));

            return;
        }

        if ($accion === 'deleted') {
            $logger->model($model, 'deleted', $this->sinIgnorados($model, $model->getOriginal()), []);

            return;
        }

        // Modificación: solo interesan los campos que cambiaron, y solo
        // si alguno es relevante (no basta con que el sondeo haya
        // refrescado la potencia de la ONT).
        $cambios = $this->sinIgnorados($model, $model->getChanges());

        if (empty($cambios)) {
            return;
        }

        $logger->model(
            $model,
            'updated',
            array_intersect_key($model->getOriginal(), $cambios),
            $cambios,
        );
    }

    /**
     * ¿Este modelo se audita?
     */
    private function auditable(Model $model): bool
    {
        foreach (config('audit.excluded_models', []) as $excluido) {
            if ($model instanceof $excluido) {
                return false;
            }
        }

        // Los modelos con el trait Auditable ya escriben su propio
        // registro: auditarlos aquí los duplicaría.
        if (in_array(Auditable::class, class_uses_recursive($model), true)) {
            return false;
        }

        return true;
    }

    /**
     * Quita los campos que no cuentan como cambio real.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function sinIgnorados(Model $model, array $atributos): array
    {
        $config = config('audit.ignored_attributes', []);

        $ignorados = array_merge(
            $config['*'] ?? [],
            $config[$model::class] ?? [],
        );

        return array_diff_key($atributos, array_flip($ignorados));
    }
}
