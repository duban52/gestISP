<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Puerto de subida de la OLT.
 *
 * Es por donde sale TODO el tráfico del equipo. Cuando "está lento el
 * internet" y las potencias ópticas están bien, lo siguiente que hay
 * que mirar es si el uplink está saturado: un puerto de 1 G moviendo
 * 950 Mbps explica la queja de todos los clientes de esa OLT a la vez.
 *
 * A diferencia del puerto PON, aquí no se documenta nada: es un espejo
 * del equipo y se identifica por su ifIndex.
 */
class OltUplink extends Model
{
    protected $fillable = [
        'olt_id',
        'if_index',
        'name',
        'description',
        'frame',
        'slot',
        'port',
        'speed_mbps',
        'admin_status',
        'oper_status',
        'in_bps',
        'out_bps',
        'measured_at',
        'discovered_at',
    ];

    protected $casts = [
        'if_index' => 'integer',
        'frame' => 'integer',
        'slot' => 'integer',
        'port' => 'integer',
        'speed_mbps' => 'integer',
        'in_bps' => 'integer',
        'out_bps' => 'integer',
        'measured_at' => 'datetime',
        'discovered_at' => 'datetime',
    ];

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    public function metrics(): MorphMany
    {
        return $this->morphMany(OltPortMetric::class, 'port');
    }

    public function estaArriba(): bool
    {
        return $this->oper_status === 'up';
    }

    /**
     * Porcentaje de uso del enlace.
     *
     * Se toma la dirección MÁS cargada, no la suma ni el promedio: un
     * enlace full-duplex de 1 G da 1 G en cada sentido, así que lo que
     * satura es la peor de las dos.
     */
    public function getUsoAttribute(): ?float
    {
        if (!$this->speed_mbps || $this->in_bps === null) {
            return null;
        }

        $capacidad = $this->speed_mbps * 1_000_000;

        return round(max($this->in_bps, (int) $this->out_bps) / $capacidad * 100, 1);
    }

    /**
     * Color del uso.
     *
     * A partir del 70% ya hay que estar gestionando la ampliación: un
     * enlace no se amplía en una tarde, y los picos de la noche pueden
     * estar veinte puntos por encima de lo que se ve de día.
     */
    public function getColorUsoAttribute(): string
    {
        return match (true) {
            $this->uso === null => 'secondary',
            $this->uso >= 90 => 'danger',
            $this->uso >= 70 => 'warning',
            $this->uso >= 40 => 'info',
            default => 'success',
        };
    }
}
