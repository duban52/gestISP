<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Almacén
 *
 * Representa un lugar físico de almacenamiento de material del ISP
 * dentro de una sucursal (bodega principal, vehículo de un técnico,
 * etc.). El stock por material se registra en la tabla inventories;
 * los materiales en sí se alcanzan a través de esa tabla intermedia.
 */
class Warehouse extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'created_by',
        'description',
    ];

    /**
     * DUEÑO del almacén. Null a propósito.
     *
     * Null significa que el almacén no es de nadie en particular: una
     * bodega, el de cabecera, el general. No es un dato que falte.
     *
     * Un usuario puede tener VARIOS almacenes; por eso las órdenes
     * técnicas preguntan de cuál sale el material cuando hay más de uno
     * (ver `TechnicalOrderController::almacenesDelTecnico`).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quién lo dio de alta. Solo informativo.
     *
     * Antes esto y el dueño eran la MISMA columna, y por eso un almacén
     * creado por la oficina para un técnico quedaba a nombre de la
     * oficina. Separarlos es lo que permite que lo cree userA y
     * pertenezca a userB.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ¿Es un almacén sin dueño (bodega, cabecera, general)? */
    public function esGeneral(): bool
    {
        return $this->user_id === null;
    }

    /** Cómo se nombra su pertenencia en pantalla. */
    public function duenoVisible(): string
    {
        if ($this->esGeneral()) {
            return 'General';
        }

        return trim(($this->user?->name ?? '') . ' ' . ($this->user?->last_name ?? '')) ?: 'General';
    }

    /** Sucursal a la que pertenece el almacén */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Registros de inventario del almacén.
     *
     * Cada registro de inventario asocia un material con su
     * cantidad en existencia dentro de este almacén.
     */
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * Materiales del almacén, alcanzados a través del inventario.
     *
     * hasManyThrough recorre: Warehouse -> Inventory -> Material.
     */
    public function materials()
    {
        return $this->hasManyThrough(Material::class, Inventory::class);
    }
}
