<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un tramo de cable de fibra.
 *
 * Los extremos son polimórficos —de una OLT a una mufla, de mufla a
 * mufla, de mufla a caja NAP— y eso es lo que convierte el inventario
 * en un grafo que se puede recorrer. Sin extremos, un cable es una
 * ficha muerta; con ellos se puede responder por dónde va un cliente y
 * a quién deja sin servicio un corte.
 */
class FiberCable extends Model
{
    public const TIPOS = [
        'troncal' => 'Troncal',
        'distribucion' => 'Distribución',
        'acometida' => 'Acometida',
    ];

    public const INSTALACIONES = [
        'aereo' => 'Aéreo',
        'canalizado' => 'Canalizado',
        'subterraneo' => 'Subterráneo directo',
        'fachada' => 'Por fachada',
    ];

    protected $fillable = [
        'optical_network_id',
        'network_zone_id',
        'code',
        'name',
        'type',
        'fiber_count',
        'buffer_count',
        'fibers_per_buffer',
        'from_type',
        'from_id',
        'to_type',
        'to_id',
        'length_m',
        'installation',
        'owner',
        'status',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'fiber_count' => 'integer',
        'buffer_count' => 'integer',
        'fibers_per_buffer' => 'integer',
        'length_m' => 'integer',
    ];

    public function network()
    {
        return $this->belongsTo(OpticalNetwork::class, 'optical_network_id');
    }

    public function zone()
    {
        return $this->belongsTo(NetworkZone::class, 'network_zone_id');
    }

    public function strands()
    {
        return $this->hasMany(CableStrand::class)->orderBy('number');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Extremo de origen: una OLT o una mufla. */
    public function from()
    {
        return $this->morphTo('from');
    }

    /** Extremo de destino: una mufla o una caja NAP. */
    public function to()
    {
        return $this->morphTo('to');
    }

    // ==================== Ocupación ====================

    /**
     * Hilos en uso: los que están fusionados, los que alimentan un
     * splitter o una caja NAP.
     *
     * Igual que en las cajas NAP, esto NO se guarda: se deduce. Un
     * campo "ocupado" empezaría a mentir en cuanto se deshiciera una
     * fusión por otro camino.
     */
    public function hilosEnUso(): int
    {
        return $this->strands->filter(fn (CableStrand $h) => $h->estaEnUso())->count();
    }

    /**
     * Hilos sin tocar por ninguno de los dos extremos.
     *
     * Es lo que se pregunta al planear una derivación: «¿cuántos hilos
     * vírgenes me quedan en este troncal?». Un hilo conectado por un
     * extremo ya está comprometido con una ruta y no cuenta.
     */
    public function hilosLibres(): int
    {
        return $this->strands->filter(fn (CableStrand $h) => $h->estaLibre())->count();
    }

    /**
     * @return array{capacidad: int, en_uso: int, libres: int, inutilizables: int, porcentaje: float}
     */
    public function ocupacion(): array
    {
        $enUso = $this->hilosEnUso();
        $libres = $this->hilosLibres();
        $capacidad = max($this->fiber_count, 1);

        return [
            'capacidad' => $this->fiber_count,
            'en_uso' => $enUso,
            'libres' => $libres,
            // Dañados o reservados: ni en uso ni ofrecibles
            'inutilizables' => max($this->fiber_count - $enUso - $libres, 0),
            'porcentaje' => round($enUso / $capacidad * 100, 1),
        ];
    }

    public function getColorOcupacionAttribute(): string
    {
        return PonPort::colorDeOcupacion($this->ocupacion()['porcentaje']);
    }

    // ==================== Presentación ====================

    public function getTipoLegibleAttribute(): string
    {
        return self::TIPOS[$this->type] ?? 'Sin clasificar';
    }

    /** "48 hilos (4 buffers × 12)" */
    public function getCapacidadLegibleAttribute(): string
    {
        return sprintf(
            '%d hilos (%d buffer%s × %d)',
            $this->fiber_count,
            $this->buffer_count,
            $this->buffer_count === 1 ? '' : 's',
            $this->fibers_per_buffer,
        );
    }

    /** Nombre del extremo, sea del tipo que sea. */
    public static function nombreDeExtremo(?Model $extremo): string
    {
        return match (true) {
            $extremo instanceof Olt => 'OLT ' . $extremo->name,
            $extremo instanceof SpliceClosure => 'Mufla ' . $extremo->code,
            $extremo instanceof NapBox => 'Caja ' . $extremo->code,
            default => 'Sin definir',
        };
    }

    public function getDesdeLegibleAttribute(): string
    {
        return self::nombreDeExtremo($this->from);
    }

    public function getHastaLegibleAttribute(): string
    {
        return self::nombreDeExtremo($this->to);
    }

    /** Cables de la sucursal activa. */
    public function scopeDeSucursal(Builder $query, ?int $branchId = null): Builder
    {
        return $query->whereHas(
            'network',
            fn ($q) => $q->where('branch_id', $branchId ?? session('branch_id')),
        );
    }
}
