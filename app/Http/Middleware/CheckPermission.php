<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * Verifica que el ROL ACTIVO de la sesión (el elegido al entrar a
 * una sucursal) tenga el permiso exigido por la ruta.
 *
 * Es el mismo criterio que aplica RoleBasedMenuFilter al menú, de
 * modo que el usuario solo ve lo que realmente puede abrir.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, $permission)
    {
        $currentRoleId = session('current_role_id');

        // Sesión sin rol activo: no es una falta de permisos, es una
        // sesión incompleta (EnsureBranchSession no pudo recomponerla
        // porque el usuario no pertenece a ninguna sucursal, o la
        // sesión caducó). Antes esto devolvía un 403 sin salida en el
        // propio dashboard; ahora se pide iniciar sesión de nuevo.
        if (!$currentRoleId) {
            return $this->pedirNuevoInicioDeSesion($request);
        }

        if ($currentRoleId) {
            $role = Role::find($currentRoleId);

            // checkPermissionTo (y no hasPermissionTo) porque este
            // NO lanza excepción cuando el permiso no existe en la
            // base de datos: un permiso declarado en el código pero
            // ausente en la BD debe negar el acceso, nunca romper
            // la aplicación con un error 500.
            if ($role && $role->checkPermissionTo($permission)) {
                return $next($request);
            }

            // Permiso declarado en el código que no existe en la
            // base de datos: se registra para poder corregirlo con
            // "php artisan permissions:sync"
            if ($role && !$this->permissionExists($permission)) {
                Log::warning(
                    "Permiso '{$permission}' no existe en la base de datos. " .
                    'Ejecute "php artisan permissions:sync" para crearlo.'
                );

                abort(403, "La acción requiere el permiso '{$permission}', que no está configurado en el sistema. Contacte al administrador.");
            }
        }

        abort(403, 'No tienes permiso para realizar esta acción.');
    }

    /**
     * Cierra la sesión incompleta y manda al login con un aviso.
     *
     * A las peticiones de fondo (sondeos por AJAX) se les responde
     * 401 en JSON: seguir una redirección a HTML no les sirve de nada.
     */
    private function pedirNuevoInicioDeSesion(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'La sesión no está activa.'], 401);
        }

        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')->withErrors([
            'email' => 'Su sesión no tiene una sucursal activa. Inicie sesión de nuevo.',
        ]);
    }

    /**
     * ¿El permiso existe en la tabla de permisos?
     */
    private function permissionExists(string $permission): bool
    {
        return \Spatie\Permission\Models\Permission::where('name', $permission)
            ->where('guard_name', 'web')
            ->exists();
    }
}
