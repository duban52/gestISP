<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reserva una pantalla al superadministrador.
 *
 * Se usa en la auditoría: es información sensible (muestra qué hace
 * cada persona en el sistema) y no debe poder concederse por error
 * marcando una casilla en el módulo de roles. Por eso NO es un
 * permiso: se exige el rol activo de la sesión, y punto.
 */
class EnsureSuperadmin
{
    public const ROL = 'superadministrador';

    public function handle(Request $request, Closure $next): Response
    {
        $rol = Role::find(session('current_role_id'));

        if (!$rol || $rol->name !== self::ROL) {
            abort(403, 'Esta sección está reservada al superadministrador del sistema.');
        }

        return $next($request);
    }
}
