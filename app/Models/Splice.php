<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fusión: la unión de dos hilos dentro de una mufla.
 *
 * El par se guarda siempre con el id menor primero. No es capricho:
 * sin ese orden, «A con B» y «B con A» serían dos filas distintas para
 * la misma fusión física, y el índice único no podría impedirlo. Lo
 * ordena el servicio antes de guardar.
 */
class Splice extends Model
{
    public const FUSION = 'fusion';
    public const MECANICO = 'mecanico';

    protected $fillable = [
        'splice_closure_id',
        'strand_a_id',
        'strand_b_id',
        'tray',
        'position',
        'type',
        'loss_db',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'tray' => 'integer',
        'position' => 'integer',
        'loss_db' => 'decimal:2',
    ];

    public function closure()
    {
        return $this->belongsTo(SpliceClosure::class, 'splice_closure_id');
    }

    public function strandA()
    {
        return $this->belongsTo(CableStrand::class, 'strand_a_id');
    }

    public function strandB()
    {
        return $this->belongsTo(CableStrand::class, 'strand_b_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** El otro extremo de la fusión, visto desde un hilo. */
    public function otroExtremo(CableStrand $hilo): ?CableStrand
    {
        return (int) $this->strand_a_id === (int) $hilo->id
            ? $this->strandB
            : $this->strandA;
    }

    public function getTipoLegibleAttribute(): string
    {
        return $this->type === self::MECANICO ? 'Empalme mecánico' : 'Fusión';
    }

    /**
     * ¿La atenuación medida es aceptable?
     *
     * Una fusión bien hecha queda por debajo de 0,1 dB; por encima de
     * 0,3 conviene rehacerla. Marcarlo evita que una fusión mala se
     * quede ahí hasta que el cliente se queja.
     */
    public function getCalidadAttribute(): ?string
    {
        if ($this->loss_db === null) {
            return null;
        }

        return match (true) {
            (float) $this->loss_db <= 0.1 => 'buena',
            (float) $this->loss_db <= 0.3 => 'aceptable',
            default => 'revisar',
        };
    }

    public function getColorCalidadAttribute(): string
    {
        return match ($this->calidad) {
            'buena' => 'success',
            'aceptable' => 'info',
            'revisar' => 'danger',
            default => 'secondary',
        };
    }
}
