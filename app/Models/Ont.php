<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ONT: el equipo de fibra instalado en casa del cliente.
 *
 * Índices SNMP (imprescindibles para consultar sus métricas):
 *  - if_index: ifIndex del PUERTO PON de la OLT. Junto con onu_id
 *    forma el índice de las tablas ópticas ({if_index}.{onu_id}).
 *  - traffic_if_index: ifIndex propio de la interfaz de la ONT,
 *    usado para los contadores de tráfico. Puede ser null si el
 *    modelo de OLT no expone interfaces por ONT.
 */
class Ont extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'olt_id',
        'contract_id',
        'slot',
        'port',
        'onu_id',
        // if_index y traffic_if_index FALTABAN en esta lista: el
        // código los asignaba masivamente al activar o mover la ONT
        // y Laravel los descartaba en silencio, dejando la ONT sin
        // índice SNMP (y por tanto sin métricas).
        'if_index',
        'traffic_if_index',
        'service_port',
        'sn',
        'description',
        'status',
        'rx_power',
        'model',
        'vlan',
        // Últimos estados conocidos (se leen de la OLT por CLI,
        // que es lento; se guardan para mostrarlos al instante)
        'catv_enabled',
        'catv_checked_at',
        'admin_enabled',
    ];

    protected $casts = [
        'if_index' => 'integer',
        'traffic_if_index' => 'integer',
        'rx_power' => 'decimal:2',
        'catv_enabled' => 'boolean',
        'catv_checked_at' => 'datetime',
        'admin_enabled' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** Historial de métricas (muestras del poller) */
    public function metrics()
    {
        return $this->hasMany(OntMetric::class);
    }

    /** Última muestra registrada */
    public function latestMetric()
    {
        return $this->hasOne(OntMetric::class)->latestOfMany('measured_at');
    }
}
