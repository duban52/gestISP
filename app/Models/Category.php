<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Tenancy\CurrentContext;

/**
 * Categoría de materiales del almacén.
 *
 * PERTENECE A UNA SUCURSAL. Cada sede maneja su propio catálogo:
 * antes las categorías eran globales y una creada en Gómez Plata
 * aparecía también en Yarumal, ensuciando los selectores con cosas
 * que en esa bodega no existen.
 */
class Category extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'description',
    ];

    /** Sucursal dueña de la categoría */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function materials()
    {
        return $this->hasMany(Material::class);
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
        // Sin sucursal explicita se usa TODO el alcance del usuario.
        // Antes caia a session('branch_id'), que en panel consolidado
        // es null: el filtro quedaba en `branch_id = null` y el modulo
        // aparecia vacio.
        return $branchId !== null
            ? $query->where('branch_id', $branchId)
            : app(CurrentContext::class)->limitarSucursales($query);
    }
}
