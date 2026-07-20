<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder de sincronización: permisos del módulo de informes
 * gerenciales.
 *
 * Es idempotente (usa firstOrCreate), así que puede ejecutarse las
 * veces que haga falta:
 *
 *   php artisan db:seed --class=ManagementReportsPermissionSeeder
 *
 * Los informes exponen la operación completa del negocio —
 * facturación, cartera y rendimiento del personal—, así que solo se
 * asignan a superadministrador y administrador. Los demás roles se
 * habilitan a mano desde el módulo de roles si la empresa lo
 * decide.
 */
class ManagementReportsPermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'reports.index' => 'Ver el tablero de informes gerenciales',
        'reports.growth' => 'Ver el informe de crecimiento de contratos',
        'reports.technical' => 'Ver el informe de órdenes técnicas y técnicos',
        'reports.billing' => 'Ver el informe de facturación y recaudo',
        'reports.provisioning' => 'Ver el informe de aprovisionamiento de red',
    ];

    private const ROLES = ['superadministrador', 'administrador'];

    public function run(): void
    {
        $creados = [];

        foreach (self::PERMISSIONS as $name => $description) {
            $permiso = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $description],
            );

            // Si el permiso ya existía sin descripción (o con una
            // antigua), se actualiza para que el módulo de roles lo
            // muestre con su texto legible
            if ($permiso->description !== $description) {
                $permiso->update(['description' => $description]);
            }

            $creados[] = $permiso->name;
        }

        foreach (self::ROLES as $nombreRol) {
            $rol = Role::where('name', $nombreRol)->first();

            if (!$rol) {
                $this->command?->warn("Rol '{$nombreRol}' no encontrado: se omite.");
                continue;
            }

            $rol->givePermissionTo($creados);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Permisos de informes gerenciales sincronizados (' . count($creados) . ').');
    }
}
