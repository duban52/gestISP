<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 *
 * PERTENECE A UNA SUCURSAL. Antes el catálogo era global y un
 * material creado en una sede aparecía en todas, aunque su
 * inventario estuviera solo en la primera.
 */
class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'category_id',
        'is_equipment',
    ];

    protected $casts = [
        'is_equipment' => 'boolean',
    ];

    /** Sucursal dueña del material */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Limita la consulta a una sucursal (por defecto, la activa).
     *
     * Se usa en TODAS las consultas del módulo. Es un scope normal y
     * no un global scope a propósito: un global scope escondería el
     * filtro y costaría entender por qué un informe no encuentra algo.
     */
    public function scopeDeSucursal(Builder $query, ?int $branchId = null): Builder
    {
        return $query->where('branch_id', $branchId ?? session('branch_id'));
    }

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
