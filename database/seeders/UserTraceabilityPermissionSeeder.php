<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder de sincronización: permisos de trazabilidad y control de
 * acceso de usuarios (ver sesiones, cerrarlas, habilitar/inhabilitar).
 *
 * Es idempotente (firstOrCreate), así que puede ejecutarse varias
 * veces sin duplicar nada:
 *
 *   php artisan db:seed --class=UserTraceabilityPermissionSeeder
 *
 * Son acciones sensibles (exponen IPs y controlan el acceso de cada
 * empleado), así que solo se asignan a superadministrador. El
 * administrador y los demás roles se habilitan a mano desde el
 * módulo de roles si la empresa lo decide.
 */
class UserTraceabilityPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'users.trace' => 'Ver trazabilidad y sesiones de un usuario',
        'users.sessions.close' => 'Cerrar sesiones activas de un usuario de forma remota',
        'users.disable' => 'Habilitar o inhabilitar el acceso de un usuario',
    ];

    public function run(): void
    {
        $creados = [];

        foreach (self::PERMISSIONS as $name => $description) {
            $permiso = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description],
            );

            if ($permiso->description !== $description) {
                $permiso->update(['description' => $description]);
            }

            $creados[] = $permiso->name;
        }

        $rol = Role::where('name', 'superadministrador')->first();

        if ($rol) {
            $rol->givePermissionTo($creados);
        } else {
            $this->command?->warn("Rol 'superadministrador' no encontrado: se omite la asignación.");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Permisos de trazabilidad de usuarios sincronizados (' . count($creados) . ').');
    }
}
