<?php

namespace App\Policies;

use App\Http\Middleware\EnsureSuperadmin;
use App\Models\MassAction;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Quién puede ver y revertir acciones masivas.
 *
 * SOLO EL SUPERADMINISTRADOR, Y POR ROL ACTIVO
 * --------------------------------------------
 * No por un permiso marcable en el módulo de roles. Es el mismo
 * criterio que ya protege la trazabilidad, las copias de seguridad y
 * el catálogo del sistema, y por la misma razón: desde aquí se
 * deshacen cortes, se anulan facturas y se borran contratos. Un
 * permiso que alguien pueda conceder marcando una casilla es
 * demasiado fácil de conceder.
 *
 * SE MIRA EL ROL DE LA SESIÓN, NO LOS ROLES DEL USUARIO
 * -----------------------------------------------------
 * En gestISP una persona puede tener varios roles y elegir con cuál
 * entra. Quien entró como administrador de una sucursal está
 * trabajando como administrador, aunque también sea superadministrador
 * en otra. Mirar `hasRole()` le daría acceso en un contexto en el que
 * no lo tiene.
 *
 * DOBLE PUERTA, A PROPÓSITO
 * -------------------------
 * La ruta ya pasa por el middleware `superadmin`. Esto lo repite
 * porque las dos puertas protegen cosas distintas: el middleware, la
 * pantalla; la política, la acción concreta sobre una acción masiva
 * concreta — y es la que mira si esa acción se puede revertir siquiera.
 */
class MassActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->esSuperadmin();
    }

    public function view(User $user, MassAction $accion): bool
    {
        return $this->esSuperadmin();
    }

    /**
     * ¿Puede revertir ESTA acción?
     *
     * Además del rol, las condiciones del propio registro: que el tipo
     * sepa deshacerse, que esté terminada, que no se haya revertido ya
     * y que quede algo que deshacer.
     */
    public function revert(User $user, MassAction $accion): bool
    {
        return $this->esSuperadmin() && $accion->sePuedeRevertir();
    }

    private function esSuperadmin(): bool
    {
        $rol = Role::find(session('current_role_id'));

        return $rol?->name === EnsureSuperadmin::ROL;
    }
}
