<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder de sincronización: permisos de los módulos de red
 * (routers, OLTs, ONTs y cuentas PPPoE).
 *
 * Estos módulos se crearon sin permisos; RoleSeeder ya los incluye
 * para instalaciones nuevas, pero las bases de datos existentes
 * necesitan este seeder para crearlos sin duplicar nada.
 *
 * Es idempotente (usa firstOrCreate), así que puede ejecutarse
 * varias veces sin efectos secundarios:
 *
 *   php artisan db:seed --class=NetworkModulePermissionSeeder
 *
 * Asigna los permisos nuevos a superadministrador y administrador.
 * Los demás roles (auxiliar administrativo, tecnico) no reciben
 * ninguno: asígnelos según necesidad desde el módulo de roles.
 */
class NetworkModulePermissionSeeder extends Seeder
{
    /**
     * Permisos de los módulos de red con su descripción legible.
     */
    private const PERMISSIONS = [
        // Routers (Mikrotik)
        'routers.index' => 'Ver routers',
        'routers.create' => 'Crear routers',
        'routers.edit' => 'Editar routers',
        'routers.destroy' => 'Eliminar routers',

        // OLTs
        'olts.index' => 'Ver OLTs',
        'olts.create' => 'Crear OLTs',
        'olts.edit' => 'Editar OLTs',
        'olts.vlans' => 'Crear VLANs y perfiles en la OLT',

        // ONTs
        'onts.index' => 'Ver ONTs (autorizadas y por autorizar)',
        'onts.show' => 'Ver detalle y estado de ONT',
        'onts.activate' => 'Activar/autorizar ONTs',
        'onts.destroy' => 'Eliminar ONTs',
        'onts.relocate' => 'Mover ONTs de puerto',
        'onts.catv' => 'Activar/desactivar CATV en ONTs',

        // Cuentas PPPoE
        'pppoe.index' => 'Ver cuentas PPPoE',
        'pppoe.show' => 'Ver detalle de cuenta PPPoE',
        'pppoe.create' => 'Crear cuentas PPPoE',
        'pppoe.edit' => 'Editar cuentas PPPoE',
        'pppoe.destroy' => 'Eliminar cuentas PPPoE',
        'pppoe.import' => 'Importar cuentas PPPoE desde el router',
        'pppoe.restart' => 'Reiniciar sesiones PPPoE',
    ];

    /**
     * Roles que reciben todos los permisos de red.
     */
    private const ROLES_WITH_ACCESS = ['superadministrador', 'administrador'];

    /**
     * Run the database seeds.
     */
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
                $this->command?->info("Permisos de red asignados al rol {$roleName}.");
            } else {
                $this->command?->warn("Rol {$roleName} no encontrado; permisos no asignados.");
            }
        }

        // Limpiar la caché de permisos de Spatie para que los
        // cambios apliquen de inmediato
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
