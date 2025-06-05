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
        'uptime'
    ];

    protected $casts = [
        'active' => 'boolean',
        'status' => 'boolean',
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
