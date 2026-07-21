<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registro de una sesión de un usuario.
 *
 * Una sesión se considera ACTIVA si no tiene salida registrada y su
 * última actividad está dentro del tiempo de vida configurado
 * (config session.lifetime). Ese umbral es la misma regla con la
 * que Laravel caduca las sesiones, así que una sesión que la
 * pantalla muestra como activa es, de hecho, una sesión que todavía
 * funcionaría.
 *
 * El barrido (sessions:sweep) marca formalmente como "expired" las
 * que cruzaron ese umbral, pero los scopes no dependen de que haya
 * corrido: calculan la frescura al vuelo para que la información sea
 * correcta en todo momento.
 */
class UserSession extends Model
{
    public const REASON_MANUAL = 'manual';
    public const REASON_EXPIRED = 'expired';
    public const REASON_FORCED = 'forced';

    protected $fillable = [
        'user_id', 'branch_id', 'session_id', 'ip_address', 'user_agent',
        'browser', 'platform', 'device_type',
        'login_at', 'last_activity_at', 'logout_at', 'logout_reason',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'logout_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Momento a partir del cual una sesión sin actividad se
     * considera caducada.
     */
    public static function staleThreshold(): Carbon
    {
        return now()->subMinutes((int) config('session.lifetime', 120));
    }

    /**
     * Sesiones vivas: sin salida y con actividad reciente.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('logout_at')
            ->where('last_activity_at', '>=', self::staleThreshold());
    }

    /**
     * Sesiones terminadas: con salida registrada, o sin actividad
     * dentro del tiempo de vida (caducadas aunque el barrido aún no
     * las haya marcado).
     */
    public function scopeEnded(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNotNull('logout_at')
                ->orWhere('last_activity_at', '<', self::staleThreshold());
        });
    }

    /**
     * ¿La sesión sigue viva ahora mismo?
     */
    public function isActive(): bool
    {
        return $this->logout_at === null
            && $this->last_activity_at !== null
            && $this->last_activity_at->greaterThanOrEqualTo(self::staleThreshold());
    }

    /**
     * Motivo de cierre legible, resolviendo también la caducidad
     * que el barrido todavía no haya escrito.
     */
    public function estadoLegible(): string
    {
        if ($this->isActive()) {
            return 'Activa';
        }

        return match ($this->logout_reason) {
            self::REASON_MANUAL => 'Cerró sesión',
            self::REASON_FORCED => 'Cerrada por un administrador',
            self::REASON_EXPIRED => 'Expiró por inactividad',
            // Sin salida escrita pero fuera del tiempo de vida:
            // caducó y el barrido aún no la ha marcado
            default => 'Expiró por inactividad',
        };
    }

    /**
     * Duración de la sesión en minutos (hasta la salida o, si sigue
     * viva, hasta ahora).
     */
    public function duracionMinutos(): ?int
    {
        if (!$this->login_at) {
            return null;
        }

        $fin = $this->logout_at ?? ($this->isActive() ? now() : $this->last_activity_at);

        return $fin ? (int) $this->login_at->diffInMinutes($fin) : null;
    }
}
