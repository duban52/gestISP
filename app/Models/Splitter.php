<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Splitter alojado en una mufla.
 *
 * Un hilo entra y salen varios. Los splitters que van DENTRO de una
 * caja NAP no se modelan aquí: sus salidas son los puertos de la caja
 * y ya se documentan con el ratio de la propia caja. Tenerlos en dos
 * sitios abriría la puerta a que las dos versiones se contradigan.
 */
class Splitter extends Model
{
    /** Los repartos que se compran de verdad, con su pérdida típica. */
    public const RATIOS = [
        '1:2' => 3.6,
        '1:4' => 7.2,
        '1:8' => 10.5,
        '1:16' => 13.7,
        '1:32' => 17.0,
        '1:64' => 20.5,
    ];

    protected $fillable = [
        'splice_closure_id',
        'code',
        'ratio',
        'output_count',
        'input_strand_id',
        'insertion_loss_db',
        'tray',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'output_count' => 'integer',
        'insertion_loss_db' => 'decimal:2',
        'tray' => 'integer',
    ];

    public function closure()
    {
        return $this->belongsTo(SpliceClosure::class, 'splice_closure_id');
    }

    public function inputStrand()
    {
        return $this->belongsTo(CableStrand::class, 'input_strand_id');
    }

    public function outputs()
    {
        return $this->hasMany(SplitterOutput::class)->orderBy('number');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Cuántas salidas están conectadas a un hilo. */
    public function salidasUsadas(): int
    {
        return $this->outputs->whereNotNull('strand_id')->count();
    }

    public function salidasLibres(): int
    {
        return $this->outputs->count() - $this->salidasUsadas();
    }

    /**
     * Pérdida esperada del reparto.
     *
     * Si no se midió, se usa la típica del ratio: sirve para estimar el
     * presupuesto óptico antes de instalar.
     */
    public function getPerdidaAttribute(): float
    {
        return (float) ($this->insertion_loss_db ?? self::RATIOS[$this->ratio] ?? 0);
    }

    /** @return array<string, string> */
    public static function ratios(): array
    {
        $lista = [];

        foreach (self::RATIOS as $ratio => $perdida) {
            $lista[$ratio] = "{$ratio}  (≈ {$perdida} dB)";
        }

        return $lista;
    }

    /** Cuántas salidas tiene un ratio: "1:8" → 8 */
    public static function salidasDe(string $ratio): int
    {
        return (int) (explode(':', $ratio)[1] ?? 0);
    }
}
