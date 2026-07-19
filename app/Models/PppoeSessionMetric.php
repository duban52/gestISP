<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Muestra histórica del tráfico de una cuenta PPPoE.
 *
 * La escribe el poller (pppoe:poll) y alimenta la gráfica de
 * ancho de banda de la vista de detalle de la cuenta.
 */
class PppoeSessionMetric extends Model
{
    protected $fillable = [
        'pppoe_account_id',
        'in_octets',
        'out_octets',
        'in_bps',
        'out_bps',
        'connected',
        'address',
        'uptime',
        'measured_at',
    ];

    protected $casts = [
        'measured_at' => 'datetime',
        'connected' => 'boolean',
        'in_octets' => 'integer',
        'out_octets' => 'integer',
        'in_bps' => 'integer',
        'out_bps' => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(PppoeAccount::class, 'pppoe_account_id');
    }
}
