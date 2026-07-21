<?php

namespace App\Console\Commands;

use App\Models\UserSession;
use Illuminate\Console\Command;

/**
 * Cierra formalmente las sesiones que caducaron por inactividad.
 *
 * Una sesión sin salida cuya última actividad superó el tiempo de
 * vida (config session.lifetime) ya no sirve: Laravel la habría
 * caducado. Este barrido le pone logout_at y motivo "expired" para
 * que el historial refleje cómo terminó, en vez de dejarla como si
 * siguiera abierta.
 *
 * No es imprescindible para que la información sea correcta —los
 * scopes del modelo ya calculan la caducidad al vuelo— pero deja el
 * historial explícito. Se agenda una vez por hora.
 */
class SweepUserSessions extends Command
{
    protected $signature = 'sessions:sweep';

    protected $description = 'Marca como expiradas las sesiones de usuario inactivas más allá del tiempo de vida';

    public function handle(): int
    {
        $marcadas = UserSession::whereNull('logout_at')
            ->where('last_activity_at', '<', UserSession::staleThreshold())
            ->update([
                'logout_at' => now(),
                'logout_reason' => UserSession::REASON_EXPIRED,
            ]);

        $this->info("Sesiones marcadas como expiradas: {$marcadas}.");

        return self::SUCCESS;
    }
}
