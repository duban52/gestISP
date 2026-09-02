<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder de sincronización: permisos de los grupos de afinidad.
 *
 * `RoleSeeder` ya los incluye para instalaciones nuevas, pero una base
 * que ya está en uso no vuelve a pasar por él. Este seeder los crea
 * sin duplicar nada:
 *
 *   php artisan db:seed --class=AffinityGroupPermissionSeeder
 *
 * Es idempotente (firstOrCreate), así que puede ejecutarse las veces
 * que haga falta.
 *
 * QUIÉN LOS RECIBE
 * ----------------
 * Solo superadministrador y administrador. Del grupo depende si un
 * contrato factura electrónicamente o no, así que no es una
 * clasificación comercial más: cambiarla tiene efecto tributario. Los
 * demás roles no reciben ninguno; si alguna instalación necesita que
 * un auxiliar pueda ver el listado, se le asigna a mano desde el
 * módulo de roles.
 */
class AffinityGroupPermissionSeeder extends Seeder
{
    /**
     * Permisos del módulo con su descripción legible.
     */
    private const PERMISSIONS = [
        'affinity_groups.index' => 'Ver grupos de afinidad',
        'affinity_groups.create' => 'Crear grupos de afinidad',
        'affinity_groups.edit' => 'Editar grupos de afinidad',
        'affinity_groups.destroy' => 'Eliminar grupos de afinidad',
    ];

    private const ROLES_WITH_ACCESS = ['superadministrador', 'administrador'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description],
            );
        }

        foreach (self::ROLES_WITH_ACCESS as $roleName) {
            $role = Role::where('name', $roleName)->first();

            if ($role) {
                $role->givePermissionTo(array_keys(self::PERMISSIONS));
                $this->command?->info("Permisos de grupos de afinidad asignados al rol {$roleName}.");
            } else {
                $this->command?->warn("Rol {$roleName} no encontrado; permisos no asignados.");
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
