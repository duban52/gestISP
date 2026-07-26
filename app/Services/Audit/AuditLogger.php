<?php

namespace App\Services\Audit;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Punto único por donde pasa TODO lo que se registra en la auditoría.
 *
 * Se encarga de tres cosas que no deben repetirse por ahí:
 *
 *  1. Añadir el contexto de la acción (usuario, rol y sucursal activos,
 *     ruta, navegador, sesión) sin que quien la registra tenga que
 *     acordarse de pasarlo.
 *  2. Tapar los datos sensibles: contraseñas, tokens y comunidades
 *     SNMP nunca llegan a la tabla.
 *  3. No tumbar nunca la aplicación: si la auditoría falla, se deja
 *     constancia en el log y la operación del usuario continúa. Una
 *     bitácora rota no puede impedir cobrar una factura.
 */
class AuditLogger
{
    /**
     * Identificador de la petición en curso.
     *
     * Todas las filas que genere una misma petición lo comparten, de
     * modo que en la auditoría se pueden ver juntas: la acción del
     * usuario y los cambios concretos que produjo.
     */
    private static ?string $requestId = null;

    /**
     * Registra el cambio de un modelo.
     *
     * @param  array<string, mixed>  $old  Valores anteriores
     * @param  array<string, mixed>  $new  Valores nuevos
     */
    public function model(Model $model, string $action, array $old = [], array $new = []): void
    {
        $this->write([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'category' => $this->categoriaDe($model::class),
            'description' => $this->describirModelo($model, $action),
            'old_values' => $this->limpiar($old) ?: null,
            'new_values' => $this->limpiar($new) ?: null,
        ]);
    }

    /**
     * Registra una acción que no es un cambio de modelo: reiniciar una
     * ONT, exportar un listado, iniciar sesión, generar un PDF...
     *
     * @param  array<string, mixed>  $context  Datos propios de la acción
     */
    public function action(
        string $action,
        string $description,
        array $context = [],
        ?Model $subject = null,
        ?string $category = null,
    ): void {
        $this->write([
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'action' => $action,
            'category' => $category ?? $this->categoriaDeAccion($action),
            'description' => $description,
            'context' => $this->limpiar($context) ?: null,
        ]);
    }

    /**
     * Identificador de la petición en curso (se crea la primera vez).
     */
    public static function requestId(): string
    {
        return self::$requestId ??= (string) Str::uuid();
    }

    /** Reinicia el identificador (lo usan las pruebas). */
    public static function resetRequestId(): void
    {
        self::$requestId = null;
    }

    /**
     * Escribe la fila añadiendo todo el contexto disponible.
     *
     * @param  array<string, mixed>  $datos
     */
    private function write(array $datos): void
    {
        if (!config('audit.enabled', true)) {
            return;
        }

        try {
            $peticion = request();
            $usuario = Auth::user();

            Audit::create(array_merge([
                'user_id' => $usuario?->id,
                'user_name' => $usuario ? trim($usuario->name . ' ' . $usuario->last_name) : null,
                'branch_id' => $this->sucursalActiva(),
                'role_name' => $this->rolActivo(),
                'ip' => $peticion?->ip(),
                'route_name' => $peticion?->route()?->getName(),
                'method' => $peticion?->method(),
                'url' => $peticion ? Str::limit($peticion->fullUrl(), 2000, '') : null,
                'user_agent' => Str::limit((string) $peticion?->userAgent(), 250, ''),
                'request_id' => self::requestId(),
                'trace_session_id' => session('_trace_session_id'),
            ], $datos));

        } catch (\Throwable $e) {
            // La auditoría nunca puede romper la operación del usuario
            Log::error('No se pudo escribir el registro de auditoría', [
                'error' => $e->getMessage(),
                'accion' => $datos['action'] ?? null,
            ]);
        }
    }

    /**
     * Sucursal activa en la sesión (null en consola).
     */
    private function sucursalActiva(): ?int
    {
        $id = session('branch_id');

        return $id ? (int) $id : null;
    }

    /**
     * Nombre del rol con el que actúa el usuario en esta sesión.
     */
    private function rolActivo(): ?string
    {
        $roleId = session('current_role_id');

        if (!$roleId) {
            return app()->runningInConsole() ? 'sistema' : null;
        }

        return Role::find($roleId)?->name;
    }

    /**
     * Quita los datos sensibles y recorta los valores enormes.
     *
     * @param  array<string, mixed>  $valores
     * @return array<string, mixed>
     */
    private function limpiar(array $valores): array
    {
        $sensibles = config('audit.redacted_attributes', []);
        $limpio = [];

        foreach ($valores as $clave => $valor) {
            if (in_array($clave, $sensibles, true)) {
                $limpio[$clave] = '********';

                continue;
            }

            if (is_array($valor)) {
                $limpio[$clave] = $this->limpiar($valor);

                continue;
            }

            if (is_object($valor)) {
                $valor = method_exists($valor, '__toString') ? (string) $valor : json_encode($valor);
            }

            // Campos muy largos (imágenes en base64, firmas, respuestas
            // de equipos) se recortan: la auditoría documenta el hecho,
            // no almacena el contenido íntegro.
            $limpio[$clave] = is_string($valor) && mb_strlen($valor) > 500
                ? Str::limit($valor, 500)
                : $valor;
        }

        return $limpio;
    }

    /**
     * Frase legible del cambio de un modelo.
     */
    private function describirModelo(Model $model, string $action): string
    {
        $verbos = [
            'created' => 'Creó',
            'updated' => 'Modificó',
            'deleted' => 'Eliminó',
            'restored' => 'Restauró',
        ];

        $verbo = $verbos[$action] ?? ucfirst($action);
        $nombre = AuditLabels::modelo($model::class);
        $identificador = AuditLabels::identificar($model);

        return trim("{$verbo} {$nombre} {$identificador}");
    }

    /**
     * Módulo al que pertenece un modelo, para poder filtrar.
     */
    private function categoriaDe(string $clase): string
    {
        return AuditLabels::categoriaDeModelo($clase);
    }

    /**
     * Módulo al que pertenece una acción con nombre
     * ("onts.reboot" → "onts").
     */
    private function categoriaDeAccion(string $action): string
    {
        return str_contains($action, '.')
            ? Str::before($action, '.')
            : $action;
    }
}
