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
        // Estado de la ultima pasada del muestreador. Se guarda aqui
        // —y no se deduce del historial— porque deducirlo obligaba a
        // una subconsulta por cuenta sobre millones de filas.
        'connected',
        'last_address',
        'last_seen_at',
        'last_polled_at',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'connected' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_polled_at' => 'datetime',
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

    // ==================== Estado, para listados y export ====================

    /**
     * ¿Está conectada AHORA?
     *
     * Es distinto de estar habilitada: una cuenta activa cuyo cliente
     * tiene el equipo apagado sale habilitada y desconectada. Confundir
     * las dos cosas hace que un listado de "caídos" incluya a los que
     * están cortados a propósito.
     */
    public function estaConectada(): bool
    {
        return (bool) $this->connected;
    }

    public function getEstadoAdministrativoAttribute(): string
    {
        return $this->disabled ? 'Suspendida' : 'Habilitada';
    }

    public function getEstadoConexionAttribute(): string
    {
        if ($this->estaConectada()) {
            return 'Conectada';
        }

        // Sin una pasada del muestreador no se puede decir que este
        // desconectada: es que nadie ha mirado todavia. Confundir las
        // dos cosas llenaria el listado de falsos caidos el dia que el
        // muestreador se pare.
        return $this->last_polled_at ? 'Desconectada' : 'Sin datos';
    }

    /**
     * Última vez que se la vio conectada.
     *
     * Sale de la columna, que mantiene el muestreador. Puede ser muy
     * anterior a la última pasada: es justo el dato que se busca cuando
     * alguien pregunta desde cuándo lleva caído un cliente.
     */
    public function ultimaConexion(): ?\Illuminate\Support\Carbon
    {
        return $this->last_seen_at;
    }
}
