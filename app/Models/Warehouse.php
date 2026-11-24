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

    /**
     * Roles que ven TODOS los almacenes de sus sucursales.
     *
     * Los demás —técnicos, auxiliares— solo ven los almacenes de los
     * que son dueños (`user_id`): ni los de sus compañeros ni el
     * general. Un técnico necesita saber qué lleva en su furgoneta, no
     * cuánto hay en la bodega ni en la furgoneta del otro.
     */
    public const ROLES_QUE_VEN_TODOS = ['superadministrador', 'administrador'];

    /**
     * ¿El rol con el que se está trabajando ahora ve todos los almacenes?
     *
     * Se mira el rol ACTIVO de la sesión, no todos los roles que tenga
     * el usuario: alguien que es administrador en una sede y técnico
     * en otra trabaja como técnico cuando entra a esta. Es el mismo
     * criterio que el menú (RoleBasedMenuFilter).
     *
     * Sin sesión —consola, colas— no hay rol activo y responde que no:
     * esta regla es de pantallas, y fallar cerrado es lo seguro.
     */
    public static function laSesionVeTodos(): bool
    {
        $rol = \Spatie\Permission\Models\Role::find(session('current_role_id'));

        return in_array($rol?->name, self::ROLES_QUE_VEN_TODOS, true);
    }

    /**
     * Los almacenes que el usuario de la sesión puede ver.
     *
     * LA ÚNICA FUENTE DE ESTA REGLA. El listado, la ficha, el PDF, el
     * desplegable de movimientos, los endpoints de existencias y el
     * historial pasan todos por aquí. Repetirla en cada sitio es como
     * uno se queda sin actualizar y por ahí se ve lo ajeno.
     *
     * Es un scope normal y no global a propósito: un scope global
     * escondería almacenes también a los procesos que SÍ deben verlos
     * todos —cerrar una orden técnica descuenta del almacén del
     * técnico ASIGNADO, aunque la cierre la oficina—.
     */
    public function scopeVisibles($query)
    {
        $query->whereIn('branch_id', app(\App\Tenancy\CurrentContext::class)->branchIds());

        if (!self::laSesionVeTodos()) {
            // Sin `Auth::id()` (no debería pasar en una pantalla con
            // login) esto no casa con nada: falla cerrado.
            $query->where('user_id', \Illuminate\Support\Facades\Auth::id());
        }

        return $query;
    }

    /** ¿El usuario de la sesión puede ver ESTE almacén? */
    public function esVisible(): bool
    {
        return static::visibles()->whereKey($this->getKey())->exists();
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
