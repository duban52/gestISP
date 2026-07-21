<?php

namespace App\Services;

use App\Models\FailedLogin;
use App\Models\User;
use App\Models\UserSession;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;

/**
 * Registro de la trazabilidad de sesiones.
 *
 * Concentra en un solo sitio cuándo y qué se anota: al iniciar
 * sesión, en cada petición (para saber que sigue viva), al cerrar, y
 * cuando un intento falla. Los enganches (LoginController,
 * middleware, listener) solo llaman aquí.
 */
class SessionTracker
{
    /**
     * Clave con la que se guarda, dentro de la propia sesión, el id
     * de la fila de trazabilidad que le corresponde.
     *
     * Correlacionar por aquí y no por el session_id como cadena es
     * más robusto: no depende de cuándo Laravel regenera el id ni
     * del driver de sesión, y sobrevive a esa regeneración.
     */
    private const SESSION_KEY = '_trace_session_id';

    /**
     * Registra un inicio de sesión.
     *
     * Se llama desde LoginController::authenticated(). Guarda el id
     * de la fila creada dentro de la sesión, para poder reconocerla
     * en las peticiones siguientes.
     */
    public function start(User $user, Request $request, ?int $branchId = null): UserSession
    {
        $ua = $request->userAgent();
        $desglose = UserAgentParser::parse($ua);

        $sesion = UserSession::create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'ip_address' => $request->ip(),
            'user_agent' => $ua,
            'browser' => $desglose['browser'],
            'platform' => $desglose['platform'],
            'device_type' => $desglose['device_type'],
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $sesion->id);
        }

        return $sesion;
    }

    /**
     * Actualiza la última actividad de la sesión en curso.
     *
     * Se llama en cada petición autenticada. Para no escribir en la
     * base en cada clic, solo actualiza si pasó al menos un minuto
     * desde la última marca.
     */
    public function touch(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $sesion = $this->sesionActual($request);

        if (!$sesion) {
            return;
        }

        if ($sesion->last_activity_at && $sesion->last_activity_at->diffInSeconds(now()) < 60) {
            return;
        }

        $sesion->update(['last_activity_at' => now()]);
    }

    /**
     * Marca el cierre de la sesión en curso.
     *
     * Se llama desde LoginController::loggedOut(). Como ese enganche
     * corre cuando la sesión ya se invalidó, el id de la fila se
     * captura antes (desde la sesión) y se pasa aquí.
     */
    public function end(?int $traceId, string $reason = UserSession::REASON_MANUAL): void
    {
        if (!$traceId) {
            return;
        }

        UserSession::where('id', $traceId)
            ->whereNull('logout_at')
            ->update([
                'logout_at' => now(),
                'logout_reason' => $reason,
            ]);
    }

    /**
     * Id de la fila de trazabilidad guardado en la sesión actual.
     */
    public function traceIdActual(Request $request): ?int
    {
        return $request->hasSession()
            ? $request->session()->get(self::SESSION_KEY)
            : null;
    }

    /**
     * Cierra de forma remota una sesión concreta.
     *
     * No borra la sesión del disco (eso dependería del driver): la
     * marca como cerrada y el middleware expulsa al usuario en su
     * siguiente petición. Funciona con cualquier driver de sesión.
     */
    public function forceClose(UserSession $sesion): void
    {
        if ($sesion->logout_at) {
            return;
        }

        $sesion->update([
            'logout_at' => now(),
            'logout_reason' => UserSession::REASON_FORCED,
        ]);
    }

    /**
     * Cierra de forma remota todas las sesiones activas de un
     * usuario.
     *
     * Sirve para "cerrar la sesión" del usuario desde su ficha y
     * para expulsarlo al inhabilitarlo. Se puede excluir una fila
     * (por ejemplo, la sesión del propio administrador si está
     * cerrando las suyas) para no autoexpulsarse sin querer.
     *
     * @return int Número de sesiones cerradas
     */
    public function forceCloseAllFor(User $user, ?int $exceptTraceId = null): int
    {
        return UserSession::where('user_id', $user->id)
            ->whereNull('logout_at')
            ->when($exceptTraceId, fn ($q) => $q->where('id', '!=', $exceptTraceId))
            ->update([
                'logout_at' => now(),
                'logout_reason' => UserSession::REASON_FORCED,
            ]);
    }

    /**
     * Registra un intento de inicio de sesión fallido.
     *
     * El usuario puede no existir (correo equivocado o inexistente):
     * en ese caso user_id queda nulo pero el correo sí se guarda.
     */
    public function recordFailure(?string $email, Request $request): void
    {
        $user = $email ? User::where('email', $email)->first() : null;

        FailedLogin::create([
            'user_id' => $user?->id,
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'attempted_at' => now(),
        ]);
    }

    /**
     * La fila de sesión correspondiente a la sesión viva actual.
     *
     * Se localiza por el id guardado en la propia sesión. Si por
     * algún motivo no estuviera (sesión anterior a esta función),
     * se cae al emparejamiento por session_id.
     */
    public function sesionActual(Request $request): ?UserSession
    {
        if (!$request->hasSession()) {
            return null;
        }

        $traceId = $this->traceIdActual($request);

        if ($traceId) {
            return UserSession::find($traceId);
        }

        return UserSession::where('session_id', $request->session()->getId())
            ->latest('login_at')
            ->first();
    }
}
