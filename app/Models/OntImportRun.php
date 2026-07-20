<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Corrida de importación de ONTs desde una OLT.
 *
 * Registra el avance del proceso (que corre en segundo plano) para
 * que la pantalla pueda mostrar la barra de progreso, y deja
 * trazabilidad de quién importó y con qué resultado.
 */
class OntImportRun extends Model
{
    public const ESTADO_PENDIENTE = 'pending';
    public const ESTADO_EJECUTANDO = 'running';
    public const ESTADO_COMPLETADO = 'completed';
    public const ESTADO_FALLIDO = 'failed';

    protected $fillable = [
        'olt_id',
        'branch_id',
        'user_id',
        'status',
        'total_found',
        'processed',
        'imported',
        'skipped_existing',
        'skipped_invalid',
        'matched_contracts',
        'message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total_found' => 'integer',
        'processed' => 'integer',
        'imported' => 'integer',
        'skipped_existing' => 'integer',
        'skipped_invalid' => 'integer',
        'matched_contracts' => 'integer',
    ];

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** ¿La corrida sigue en curso? */
    public function enCurso(): bool
    {
        return in_array($this->status, [self::ESTADO_PENDIENTE, self::ESTADO_EJECUTANDO], true);
    }

    /** Porcentaje de avance para la barra de progreso */
    public function porcentaje(): int
    {
        if ($this->status === self::ESTADO_COMPLETADO) {
            return 100;
        }

        if ($this->total_found === 0) {
            return 0;
        }

        return min(99, (int) round($this->processed / $this->total_found * 100));
    }

    /** Texto del estado para mostrar al usuario */
    public function estadoLegible(): string
    {
        return match ($this->status) {
            self::ESTADO_PENDIENTE => 'En espera',
            self::ESTADO_EJECUTANDO => 'Importando',
            self::ESTADO_COMPLETADO => 'Completada',
            self::ESTADO_FALLIDO => 'Fallida',
            default => $this->status,
        };
    }
}
