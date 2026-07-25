<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica a la sesión el tema (claro/oscuro) guardado en el usuario.
 *
 * AdminLTE decide el tema leyendo la clave 'adminlte_dark_mode' de la
 * sesión. Como la sesión se pierde al cerrar —y con el cierre por
 * inactividad eso pasa a diario—, aquí se repone desde la preferencia
 * que el usuario tiene guardada, para que el panel abra siempre con
 * el tema que eligió.
 */
class ApplyUserTheme
{
    /** Clave que usa AdminLTE para el tema en la sesión. */
    private const CLAVE_TEMA = 'adminlte_dark_mode';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()
            && $request->hasSession()
            && !$request->session()->has(self::CLAVE_TEMA)
        ) {
            $request->session()->put(self::CLAVE_TEMA, (bool) Auth::user()->dark_mode);
        }

        return $next($request);
    }
}
