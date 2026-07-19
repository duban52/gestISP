<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PppoeAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'router_id',
        'contract_id',
        'mikrotik_id',
        'username',
        'password',
        'profile',
        'service',
        'remote_address',
        'disabled',
        'comment',
    ];

    protected $casts = [
        'disabled' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** Historial de tráfico (muestras del poller) */
    public function metrics()
    {
        return $this->hasMany(PppoeSessionMetric::class, 'pppoe_account_id');
    }

    /** Última muestra registrada */
    public function latestMetric()
    {
        return $this->hasOne(PppoeSessionMetric::class, 'pppoe_account_id')
            ->latestOfMany('measured_at');
    }
}
