<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Material
 *
 * Catálogo de materiales del ISP. El flag is_equipment distingue:
 * - Equipos (true): se rastrean por número de serie en el
 *   inventario (ONTs, routers, antenas) — una fila de inventario
 *   por cada serial.
 * - Consumibles (false): se rastrean solo por cantidad
 *   (cable, conectores, grapas, cinta).
 */
class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category_id',
        'is_equipment',
    ];

    protected $casts = [
        'is_equipment' => 'boolean',
    ];

    /** Categoría a la que pertenece el material */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Registros de existencias del material en los almacenes.
     * Para equipos, cada registro corresponde a un serial.
     */
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    /** Órdenes técnicas donde se usó este material */
    public function technicalOrders()
    {
        return $this->hasMany(TechnicalOrder::class);
    }
}
