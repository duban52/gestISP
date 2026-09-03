<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso del informe de completitud fiscal.
 *
 *     php artisan db:seed --class=FiscalCompletenessPermissionSeeder
 *
 * Idempotente. Lo reciben superadministrador y administrador: el
 * informe enseña datos fiscales de todos los clientes de la empresa.
 */
class FiscalCompletenessPermissionSeeder extends Seeder
{
    private const PERMISOS = [
        'fiscal.completeness' => 'Ver el informe de completitud fiscal',
    ];

    private const ROLES = ['superadministrador', 'administrador'];

    public function run(): void
    {
        foreach (self::PERMISOS as $nombre => $descripcion) {
            Permission::firstOrCreate(
                ['name' => $nombre, 'guard_name' => 'web'],
                ['description' => $descripcion],
            );
        }

        foreach (self::ROLES as $rol) {
            Role::where('name', $rol)->first()?->givePermissionTo(array_keys(self::PERMISOS));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
