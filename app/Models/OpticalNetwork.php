<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Tenancy\CurrentContext;

/**
 * Red óptica (ODN) de una sucursal.
 *
 * Es el contenedor de toda la planta externa de una sede: sus OLTs,
 * sus puertos PON, sus zonas y sus cajas. Una sucursal puede tener
 * varias —por ejemplo una por municipio atendido—, pero una red
 * pertenece a una sola sucursal.
 */
class OpticalNetwork extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'branch_id',
        'name',
        'description',
        'nap_prefix',
        'nap_next_number',
        'active',
        'user_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'nap_next_number' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function olts()
    {
        return $this->hasMany(Olt::class);
    }

    public function zones()
    {
        return $this->hasMany(NetworkZone::class);
    }

    public function ponPorts()
    {
        return $this->hasMany(PonPort::class);
    }

    /** Muflas de la red. */
    public function spliceClosures()
    {
        return $this->hasMany(SpliceClosure::class)->orderBy('code');
    }

    /** Cables de la red. */
    public function fiberCables()
    {
        return $this->hasMany(FiberCable::class)->orderBy('code');
    }

    public function napBoxes()
    {
        return $this->hasMany(NapBox::class);
    }

    /** Limita a la sucursal activa. */
    public function scopeDeSucursal(Builder $query, ?int $branchId = null): Builder
    {
        // Sin sucursal explicita se usa TODO el alcance del usuario.
        // Antes caia a session('branch_id'), que en panel consolidado
        // es null: el filtro quedaba en `branch_id = null` y el modulo
        // aparecia vacio.
        return $branchId !== null
            ? $query->where('branch_id', $branchId)
            : app(CurrentContext::class)->limitarSucursales($query);
    }
}
