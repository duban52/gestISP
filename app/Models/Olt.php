<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Olt extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'optical_network_id',
        'name',
        'ip_address',
        'ssh_port',
        'telnet_port',
        'snmp_port',
        'read_snmp_comunity',
        'write_snmp_comunity',
        'username',
        'password',
        'brand',
        'model',
        'active',
        'temperature',
        'status',
        'uptime',
        'status_checked_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'status' => 'boolean',
        'status_checked_at' => 'datetime',
        'ssh_port' => 'integer',
        'telnet_port' => 'integer',
        'snmp_port' => 'integer',
    ];

    /**
     * Los atributos que deben ocultarse para los arrays.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * ¿Los datos de estado que hay guardados son recientes?
     *
     * El listado los muestra de inmediato y consulta el equipo
     * después: hay que poder decirle al operador si lo que está
     * viendo es de hace un minuto o de ayer.
     */
    public function estadoEsReciente(int $minutos = 10): bool
    {
        return $this->status_checked_at !== null
            && $this->status_checked_at->gt(now()->subMinutes($minutos));
    }

    /** Texto del momento de la última comprobación, o null. */
    public function getEstadoConsultadoAttribute(): ?string
    {
        return $this->status_checked_at?->diffForHumans();
    }

    /** Red optica (ODN) a la que pertenece la OLT */
    public function opticalNetwork()
    {
        return $this->belongsTo(OpticalNetwork::class, 'optical_network_id');
    }

    /** Puertos PON documentados de esta OLT */
    public function ponPorts()
    {
        return $this->hasMany(PonPort::class);
    }

    /**
     * Relación con ONTs
     */
    public function onts()
    {
        return $this->hasMany(Ont::class);
    }

    /**
     * Relación con Branch
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    //Relación con lineProfile
    public function lineProfiles()
    {
        return $this->hasMany(LineProfile::class);
    }

    //Relación con srvProfile
    public function srvProfiles()
    {
        return $this->hasMany(SrvProfile::class);
    }

    //Relación con vlan olt
    public function vlans()
    {
        return $this->hasMany(VlanOlt::class);
    }

    /**
     * Obtiene la contraseña sin cifrar para conexiones SSH
     * NOTA: Solo usar si la contraseña se guarda en texto plano
     */
    public function getPlainPassword()
    {
        return $this->password;
    }

    /**
     * Verifica si la OLT está activa
     */
    public function isActive(): bool
    {
        return $this->active ?? false;
    }

    /**
     * Verifica si la OLT está conectada según el último estado
     */
    public function isConnected(): bool
    {
        return $this->status ?? false;
    }

    /**
     * Obtiene el texto del estado de conexión
     */
    public function getStatusTextAttribute(): string
    {
        return $this->isConnected() ? 'Conectado' : 'Desconectado';
    }

    /**
     * Scope para obtener solo OLTs activas
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope para obtener OLTs por branch
     */
    public function scopeByBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }
}
