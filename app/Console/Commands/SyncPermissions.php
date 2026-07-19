<?php

namespace App\Console\Commands;

use App\Support\PermissionLabels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincroniza los permisos declarados en el código con la base de
 * datos.
 *
 * El middleware check.permission:X exige que el permiso X exista;
 * si un módulo nuevo declara un permiso que la base de datos no
 * tiene, esa pantalla queda inaccesible. RoleSeeder solo sirve
 * para instalaciones desde cero (usa create y falla con
 * duplicados), así que las bases de datos ya en uso se quedaban
 * atrás cada vez que se agregaba un módulo.
 *
 * Este comando recorre los controladores, extrae todos los
 * permisos declarados, crea los que falten y se los asigna al rol
 * superadministrador (que por definición los tiene todos). Es
 * idempotente: se puede ejecutar en cada despliegue.
 *
 *   php artisan permissions:sync
 *   php artisan permissions:sync --dry-run   (solo informa)
 */
class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
                            {--dry-run : Muestra los cambios sin aplicarlos}';

    protected $description = 'Crea en la base de datos los permisos declarados en los controladores';

    /**
     * Rol que siempre debe tener todos los permisos.
     */
    private const SUPERADMIN_ROLE = 'superadministrador';

    public function handle(): int
    {
        $declared = $this->declaredPermissions();

        if ($declared->isEmpty()) {
            $this->error('No se encontraron permisos declarados en los controladores.');

            return self::FAILURE;
        }

        $existing = Permission::pluck('name');
        $missing = $declared->diff($existing)->values();

        $this->info("Permisos declarados en el código: {$declared->count()}");
        $this->info("Permisos existentes en la base de datos: {$existing->count()}");

        if ($missing->isEmpty()) {
            $this->info('Todo sincronizado: no falta ningún permiso.');
        } else {
            $this->warn("Permisos faltantes: {$missing->count()}");
            $this->table(
                ['Permiso', 'Descripción que se creará'],
                $missing->map(fn ($name) => [$name, PermissionLabels::describe($name)])->all()
            );
        }

        if ($this->option('dry-run')) {
            $this->comment('Modo --dry-run: no se aplicó ningún cambio.');

            return self::SUCCESS;
        }

        foreach ($missing as $name) {
            Permission::create([
                'name' => $name,
                'guard_name' => 'web',
                'description' => PermissionLabels::describe($name),
            ]);
        }

        // El superadministrador debe tener siempre todos los
        // permisos, incluidos los que existían pero nunca se le
        // asignaron (por ejemplo, creados después del seeder)
        $granted = $this->grantAllToSuperadmin();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info("Permisos creados: {$missing->count()}");
        $this->info("Permisos asignados al superadministrador: {$granted}");
        $this->comment('Los demás roles se ajustan desde el módulo de Roles.');

        return self::SUCCESS;
    }

    /**
     * Extrae todos los permisos declarados con
     * check.permission:NOMBRE en los controladores.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function declaredPermissions()
    {
        $permissions = collect();

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/check\.permission:([a-zA-Z_\-\.]+)/',
                $file->getContents(),
                $matches
            );

            $permissions = $permissions->merge($matches[1]);
        }

        return $permissions->unique()->sort()->values();
    }

    /**
     * Asigna al superadministrador todos los permisos que aún no
     * tenga. Devuelve cuántos se agregaron.
     */
    private function grantAllToSuperadmin(): int
    {
        $role = Role::where('name', self::SUPERADMIN_ROLE)->first();

        if (!$role) {
            $this->warn('No existe el rol ' . self::SUPERADMIN_ROLE . '; no se asignaron permisos.');

            return 0;
        }

        $all = Permission::pluck('name');
        $current = $role->permissions->pluck('name');
        $toGrant = $all->diff($current);

        if ($toGrant->isNotEmpty()) {
            $role->givePermissionTo($toGrant->all());
        }

        return $toGrant->count();
    }
}
