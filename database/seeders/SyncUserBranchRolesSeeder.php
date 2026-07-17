<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder de reparación: sincroniza los roles de Spatie
 * (model_has_roles) con las asignaciones reales por sucursal
 * (tabla pivote user_branch).
 *
 * Históricamente UserController::update() actualizaba la pivote
 * sin tocar los roles de Spatie, dejando usuarios cuyo menú y
 * checks de Gate evaluaban un rol distinto al que usa el login.
 * El controlador ya quedó corregido (syncBranchesAndRoles); este
 * seeder repara los datos que quedaron desincronizados.
 *
 * Es idempotente: si todo está en orden no cambia nada. Puede
 * ejecutarse las veces que haga falta:
 *
 *   php artisan db:seed --class=SyncUserBranchRolesSeeder
 */
class SyncUserBranchRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roleNames = Role::pluck('name', 'id');
        $repaired = 0;

        foreach (User::with('branches')->get() as $user) {
            // Roles que el usuario debería tener según sus sucursales
            $expected = $user->branches
                ->pluck('pivot.role_id')
                ->unique()
                ->map(fn ($id) => $roleNames[$id] ?? null)
                ->filter()
                ->sort()
                ->values()
                ->all();

            $current = $user->roles()->pluck('name')->sort()->values()->all();

            if ($expected !== $current) {
                $this->command?->info(sprintf(
                    'Sincronizando %s: [%s] -> [%s]',
                    $user->email,
                    implode(', ', $current) ?: 'ninguno',
                    implode(', ', $expected) ?: 'ninguno'
                ));

                $user->syncRoles($expected);
                $repaired++;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(
            $repaired > 0
                ? "Reparados {$repaired} usuario(s) desincronizado(s)."
                : 'Todos los usuarios ya estaban sincronizados.'
        );
    }
}
