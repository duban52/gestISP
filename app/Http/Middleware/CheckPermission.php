<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
     * ¿El permiso existe en la tabla de permisos?
     */
    private function permissionExists(string $permission): bool
    {
        return \Spatie\Permission\Models\Permission::where('name', $permission)
            ->where('guard_name', 'web')
            ->exists();
    }
}
