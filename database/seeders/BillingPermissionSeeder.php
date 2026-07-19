<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder de sincronización: permisos nuevos del módulo de
 * facturación/cajas.
 *
 * RoleSeeder ya los incluye para instalaciones nuevas; las bases
 * de datos existentes necesitan este seeder. Es idempotente
 * (firstOrCreate): puede ejecutarse las veces que haga falta:
 *
 *   php artisan db:seed --class=BillingPermissionSeeder
 *
 * Asigna los permisos a superadministrador y administrador; los
 * demás roles se gestionan desde el módulo de roles.
 */
class BillingPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'cash_register.summary' => 'Ver resumen de cajas por período',
    ];

    private const ROLES_WITH_ACCESS = ['superadministrador', 'administrador'];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description]
            );
        }

        foreach (self::ROLES_WITH_ACCESS as $roleName) {
            $role = Role::where('name', $roleName)->first();

            if ($role) {
                $role->givePermissionTo(array_keys(self::PERMISSIONS));
                $this->command?->info("Permisos de facturación asignados al rol {$roleName}.");
            } else {
                $this->command?->warn("Rol {$roleName} no encontrado; permisos no asignados.");
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
