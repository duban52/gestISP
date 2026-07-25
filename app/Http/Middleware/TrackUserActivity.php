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
 *  2. Cierra la sesión que lleve demasiado tiempo sin actividad
 *     (config session.inactivity_timeout, 15 minutos por defecto).
 *  3. Actualiza la última actividad, para saber que la sesión sigue
 *     abierta (el servicio limita la escritura a una vez por minuto).
 */
class TrackUserActivity
{
    /**
     * Clave de sesión con el instante (timestamp) de la última
     * actividad real del usuario.
     */
    private const CLAVE_ACTIVIDAD = 'last_activity_at';

    /**
     * Rutas que el navegador pide solo por sondeo automático.
     *
     * No cuentan como actividad del usuario: si contaran, una pestaña
     * olvidada abierta mantendría viva la sesión indefinidamente y el
     * cierre por inactividad no serviría de nada.
     */
    private const RUTAS_DE_SONDEO = [
        'notifications.poll',
        'onts.import.status',
    ];

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
                return $this->expulsar($request, trans('auth.session_user_disabled'));
            }

            $sesion = $this->tracker->sesionActual($request);

            // Cierre remoto: la fila existe y ya tiene salida
            // marcada por un administrador
            if ($sesion && $sesion->logout_at && $sesion->logout_reason === UserSession::REASON_FORCED) {
                return $this->expulsar($request, trans('auth.session_closed_by_admin'));
            }

            if ($this->llevaDemasiadoTiempoInactiva($request)) {
                return $this->expulsar(
                    $request,
                    trans('auth.session_expired', [
                        'minutes' => (int) config('session.inactivity_timeout', 15),
                    ]),
                    UserSession::REASON_EXPIRED,
                );
            }

            $this->registrarActividad($request);
            $this->tracker->touch($request);
        }

        return $next($request);
    }

    /**
     * ¿Se superó el tiempo máximo sin actividad del usuario?
     */
    private function llevaDemasiadoTiempoInactiva(Request $request): bool
    {
        $minutos = (int) config('session.inactivity_timeout', 15);

        if ($minutos <= 0) {
            return false; // comprobación desactivada
        }

        $ultima = $request->session()->get(self::CLAVE_ACTIVIDAD);

        // Primera petición de la sesión: aún no hay nada que comparar
        if (!$ultima) {
            return false;
        }

        return (time() - (int) $ultima) >= $minutos * 60;
    }

    /**
     * Marca el instante de la última actividad real.
     *
     * Los sondeos automáticos se saltan este registro para no
     * mantener viva una sesión que el usuario ya abandonó.
     */
    private function registrarActividad(Request $request): void
    {
        if (in_array($request->route()?->getName(), self::RUTAS_DE_SONDEO, true)) {
            return;
        }

        $request->session()->put(self::CLAVE_ACTIVIDAD, time());
    }

    /**
     * Cierra la sesión del usuario y lo manda al login con un
     * mensaje. Concentra los casos de expulsión (inhabilitado,
     * cierre remoto e inactividad) para no repetir el mismo bloque.
     *
     * Cuando se indica un motivo, la salida queda registrada en la
     * trazabilidad antes de invalidar la sesión (después ya no se
     * podría localizar la fila).
     */
    private function expulsar(Request $request, string $mensaje, ?string $motivo = null): Response
    {
        if ($motivo) {
            $this->tracker->end($this->tracker->traceIdActual($request), $motivo);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Los sondeos automáticos esperan JSON: una redirección a
        // HTML no les diría nada.
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje], 401);
        }

        return redirect()->route('login')->withErrors(['email' => $mensaje]);
    }
}
