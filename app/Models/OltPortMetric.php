<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una muestra de tráfico de un puerto (PON o uplink).
 *
 * De aquí sale la gráfica de tráfico reciente. Se guardan los
 * contadores crudos ADEMÁS de los bits por segundo ya calculados:
 * los crudos hacen falta para sacar la diferencia con la muestra
 * siguiente, y los calculados para poder graficar sin recalcular
 * nada en cada visita.
 *
 * Es la misma tabla para puertos PON y uplinks —relación polimórfica—
 * porque son el mismo dato: contadores de la IF-MIB sobre un ifIndex.
 * Separarlas obligaría a duplicar el muestreador, la poda y la gráfica.
 */
class OltPortMetric extends Model
{
    protected $fillable = [
        'port_type',
        'port_id',
        'in_octets',
        'out_octets',
        'in_bps',
        'out_bps',
        'tx_power',
        'onts_total',
        'onts_online',
        'measured_at',
    ];

    protected $casts = [
        'in_octets' => 'integer',
        'out_octets' => 'integer',
        'in_bps' => 'integer',
        'out_bps' => 'integer',
        'tx_power' => 'decimal:2',
        'onts_total' => 'integer',
        'onts_online' => 'integer',
        'measured_at' => 'datetime',
    ];

    public function port()
    {
        return $this->morphTo();
    }

    /** Muestras desde hace N horas, de la más vieja a la más nueva. */
    public function scopeUltimasHoras(Builder $query, int $horas): Builder
    {
        return $query
            ->where('measured_at', '>=', now()->subHours($horas))
            ->orderBy('measured_at');
    }
}
