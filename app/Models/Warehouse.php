<?php

namespace App\Models;

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
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'user_id',
        'description',
    ];

    /** Usuario que creó el almacén */
    public function user()
    {
        return $this->belongsTo(User::class);
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
