<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra cada acción que un usuario ejecuta en el sistema.
 *
 * Los eventos de Eloquent cubren los CAMBIOS de datos, pero hay
 * acciones importantes que no modifican ninguna tabla y que una
 * auditoría debe recoger igual: reiniciar la ONT de un cliente,
 * exportar el listado de clientes, descargar un PDF de facturación,
 * enviar una notificación...
 *
 * Criterio (configurable en config/audit.php):
 *  - Se registra toda petición que modifique algo (POST/PUT/PATCH/DELETE).
 *  - De las consultas (GET) solo las que EXTRAEN información
 *    (exportaciones, PDF, descargas): registrar cada pantalla que se
 *    abre serían millones de filas inútiles.
 *  - No se registran los sondeos automáticos del navegador.
 *
 * Cada fila comparte request_id con los cambios de modelo que haya
 * provocado, para poder verlos juntos.
 */
class AuditRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = null;

        try {
            $response = $next($request);

            return $response;
        } finally {
            // En 'finally' a propósito: si la acción reventó con una
            // excepción, es justo la que MÁS interesa auditar. Sin
            // esto, los errores desaparecían de la bitácora.
            if ($this->debeRegistrarse($request)) {
                $this->registrar($request, $response);
            }
        }
    }

    /**
     * ¿Esta petición forma parte de la auditoría?
     */
    private function debeRegistrarse(Request $request): bool
    {
        if (!config('audit.enabled', true) || !Auth::check()) {
            return false;
        }

        $ruta = $request->route()?->getName();

        if ($ruta && in_array($ruta, config('audit.excluded_routes', []), true)) {
            return false;
        }

        // Las peticiones que cambian algo siempre se registran
        if (in_array($request->method(), config('audit.methods', []), true)) {
            return true;
        }

        // De las consultas, solo las que extraen información
        if ($request->isMethod('GET') && $ruta) {
            foreach (config('audit.audited_read_patterns', []) as $patron) {
                if (Str::is($patron, $ruta)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Escribe la fila de la acción.
     */
    private function registrar(Request $request, ?Response $response): void
    {
        $ruta = $request->route()?->getName() ?? $request->path();

        // Sin respuesta = la petición terminó en excepción
        $estado = $response?->getStatusCode();
        $exitosa = $estado !== null && $estado < 400;

        app(AuditLogger::class)->action(
            $ruta,
            $this->describir($request, $exitosa),
            [
                'parametros' => $this->parametros($request),
                'estado_http' => $estado,
                'resultado' => $exitosa ? 'ok' : 'error',
            ],
            null,
            $this->categoria($ruta),
        );
    }

    /**
     * Frase legible de la acción.
     */
    private function describir(Request $request, bool $exitosa): string
    {
        $ruta = $request->route()?->getName() ?? $request->path();

        $texto = match (true) {
            // Las copias de seguridad se describen aparte porque el
            // nombre de la ruta no dice lo importante: cuál de las
            // copias se llevó la persona. El archivo va en la URL, no
            // en los parámetros, así que no lo recoge parametros().
            $ruta === 'backups.download' => 'Descargó la copia de seguridad de la base de datos ' . $request->route('archivo'),
            $ruta === 'backups.store' => 'Generó una copia de seguridad de la base de datos desde el panel',
            $ruta === 'backups.destroy' => 'Eliminó del servidor la copia de seguridad ' . $request->route('archivo'),
            Str::contains($ruta, 'export') => 'Exportó información: ' . $ruta,
            Str::contains($ruta, 'pdf') => 'Generó o descargó un PDF: ' . $ruta,
            $request->isMethod('DELETE') => 'Ejecutó una eliminación: ' . $ruta,
            default => 'Ejecutó la acción: ' . $ruta,
        };

        return $exitosa ? $texto : $texto . ' (falló)';
    }

    /**
     * Módulo de la acción, tomado del nombre de la ruta
     * ("onts.reboot" → "red").
     */
    private function categoria(string $ruta): string
    {
        $prefijo = Str::before($ruta, '.');

        return match ($prefijo) {
            'onts', 'olts', 'pppoe', 'routers' => 'red',
            'clients' => 'clientes',
            'contracts', 'contractComments' => 'contratos',
            'invoices', 'additionalCharges' => 'facturacion',
            'payments' => 'pagos',
            'cashRegisters', 'cash_register', 'transactions' => 'cajas',
            'technicals_orders', 'technical_order', 'technical_orders', 'orders' => 'ordenes',
            'materials', 'movements', 'warehouses', 'categories' => 'inventario',
            'users', 'profile' => 'usuarios',
            'roles' => 'roles',
            'branches' => 'sucursales',
            default => 'sistema',
        };
    }

    /**
     * Datos enviados, sin archivos ni campos sensibles.
     *
     * @return array<string, mixed>
     */
    private function parametros(Request $request): array
    {
        $datos = $request->except(config('audit.redacted_attributes', []));

        // Los archivos no se guardan: basta con dejar constancia
        foreach ($request->allFiles() as $campo => $archivo) {
            unset($datos[$campo]);
            $datos[$campo] = '(archivo adjunto)';
        }

        return $datos;
    }
}
