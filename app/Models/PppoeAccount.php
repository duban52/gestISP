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
        return (bool) $this->latestMetric?->connected;
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

        return $this->latestMetric ? 'Desconectada' : 'Sin datos';
    }

    /**
     * Última vez que se la vio conectada.
     *
     * Llega como atributo de la consulta (withMax) cuando el listado la
     * pide; si no, se resuelve aquí. El accesor está para que la ficha
     * individual no tenga que armar la subconsulta.
     */
    public function ultimaConexion(): ?\Illuminate\Support\Carbon
    {
        $valor = $this->attributes['ultima_conexion']
            ?? $this->metrics()->where('connected', true)->max('measured_at');

        return $valor ? \Illuminate\Support\Carbon::parse($valor) : null;
    }
}
