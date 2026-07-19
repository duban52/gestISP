<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Muestra histórica de las métricas de una ONT.
 *
 * La escribe el poller (onts:poll) en cada ejecución y alimenta
 * las gráficas de potencia óptica y ancho de banda de la vista de
 * detalle de la ONT.
 */
class OntMetric extends Model
{
    protected $fillable = [
        'ont_id',
        'rx_power',
        'tx_power',
        'olt_rx_power',
        'temperature',
        'voltage',
        'bias_current',
        'distance',
        'run_status',
        'in_octets',
        'out_octets',
        'in_bps',
        'out_bps',
        'measured_at',
    ];

    protected $casts = [
        'measured_at' => 'datetime',
        'rx_power' => 'decimal:2',
        'tx_power' => 'decimal:2',
        'olt_rx_power' => 'decimal:2',
        'temperature' => 'decimal:2',
        'voltage' => 'decimal:2',
        'bias_current' => 'decimal:3',
        'distance' => 'integer',
        'in_octets' => 'integer',
        'out_octets' => 'integer',
        'in_bps' => 'integer',
        'out_bps' => 'integer',
    ];

    public function ont()
    {
        return $this->belongsTo(Ont::class);
    }
}
