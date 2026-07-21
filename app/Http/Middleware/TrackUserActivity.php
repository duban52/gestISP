<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use App\Services\SessionTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mantiene viva la trazabilidad de la sesión en curso y aplica los
 * cierres remotos.
 *
 * En cada petición autenticada hace dos cosas:
 *
 *  1. Si un administrador cerró esta sesión de forma remota, expulsa
 *     al usuario en el acto. No se borra la sesión del disco (eso
 *     dependería del driver): basta con que la fila esté marcada
 *     como cerrada para que aquí se cumpla, sirviendo con cualquier
 *     driver de sesión.
 *  2. Actualiza la última actividad, para saber que la sesión sigue
 *     abierta (el servicio limita la escritura a una vez por minuto).
 */
class TrackUserActivity
{
    public function __construct(private readonly SessionTracker $tracker)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && $request->hasSession()) {
            // Usuario inhabilitado mientras tenía la sesión abierta:
            // se le expulsa en el acto. El usuario ya está cargado,
            // así que comprobarlo no cuesta una consulta extra.
            if (!Auth::user()->is_active) {
                return $this->expulsar($request, 'Su usuario fue inhabilitado.');
            }

            $sesion = $this->tracker->sesionActual($request);

            // Cierre remoto: la fila existe y ya tiene salida
            // marcada por un administrador
            if ($sesion && $sesion->logout_at && $sesion->logout_reason === UserSession::REASON_FORCED) {
                return $this->expulsar($request, 'Su sesión fue cerrada por un administrador.');
            }

            $this->tracker->touch($request);
        }

        return $next($request);
    }

    /**
     * Cierra la sesión del usuario y lo manda al login con un
     * mensaje. Concentra los dos casos (inhabilitado / cierre
     * remoto) para no repetir el mismo bloque.
     */
    private function expulsar(Request $request, string $mensaje): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $mensaje]);
    }
}
