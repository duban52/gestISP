<?php

namespace App\Filters;

use JeroenNoten\LaravelAdminLte\Menu\Filters\FilterInterface;
use Spatie\Permission\Models\Role;

/**
 * Filtro de menú por rol activo de la sesión.
 *
 * Es el ÚNICO mecanismo de filtrado por permisos del menú lateral:
 * evalúa la clave 'can' de cada ítem contra el rol activo de la
 * sesión (current_role_id, elegido al seleccionar sucursal en el
 * login), exactamente el mismo criterio que aplica el middleware
 * check.permission sobre las rutas. Así el menú muestra solo lo
 * que el usuario realmente puede abrir.
 *
 * No usar el GateFilter de AdminLTE junto a este filtro: aquel
 * evalúa 'can' vía Gate/Spatie contra TODOS los roles del usuario
 * (model_has_roles), y la combinación de ambos criterios recorta
 * el menú por partida doble.
 */
class RoleBasedMenuFilter implements FilterInterface
{
    /**
     * Rol activo de la sesión, cacheado por petición para no
     * consultarlo una vez por cada ítem del menú.
     */
    private ?Role $role = null;
    private bool $roleResolved = false;

    /**
     * Marca con 'restricted' los ítems cuyo permiso no tiene el
     * rol activo; AdminLTE los excluye del menú compilado (y poda
     * automáticamente los padres cuyo submenú queda vacío).
     */
    public function transform($item)
    {
        if (empty($item['can'])) {
            return $item;
        }

        if (! $this->isAllowed($item['can'])) {
            $item['restricted'] = true;
        }

        return $item;
    }

    /**
     * Verifica si el rol activo tiene alguno de los permisos
     * indicados. Acepta un permiso o una lista (basta con uno,
     * igual que Gate::any).
     *
     * @param  string|array  $permissions
     */
    private function isAllowed($permissions): bool
    {
        $role = $this->currentRole();

        if (! $role) {
            return false;
        }

        foreach ((array) $permissions as $permission) {
            // checkPermissionTo no lanza excepción si el permiso
            // no existe en la tabla (a diferencia de hasPermissionTo)
            if ($role->checkPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resuelve (una sola vez por petición) el rol activo de la sesión.
     */
    private function currentRole(): ?Role
    {
        if (! $this->roleResolved) {
            $roleId = session('current_role_id');
            $this->role = $roleId ? Role::find($roleId) : null;
            $this->roleResolved = true;
        }

        return $this->role;
    }
}
