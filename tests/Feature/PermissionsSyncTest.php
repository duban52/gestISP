<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sincronía entre los permisos declarados en el código
 * (check.permission:X) y los que existen en la base de datos.
 *
 * Un permiso declarado que no exista en la BD dejaba la pantalla
 * inaccesible; antes además provocaba un error 500 en el servidor.
 */
class PermissionsSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Todos los permisos usados con check.permission en los
     * controladores.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function declaredPermissions()
    {
        $permissions = collect();

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            preg_match_all('/check\.permission:([a-zA-Z_\-\.]+)/', $file->getContents(), $m);
            $permissions = $permissions->merge($m[1]);
        }

        return $permissions->unique()->sort()->values();
    }

    public function test_el_comando_crea_los_permisos_declarados_que_falten(): void
    {
        $this->seed(RoleSeeder::class);

        $declared = $this->declaredPermissions();

        // Simular una base de datos desactualizada (como el
        // servidor, sembrado antes de agregar módulos nuevos)
        Permission::whereIn('name', ['warehouses.show', 'cash_register.summary'])->delete();

        $this->artisan('permissions:sync')->assertSuccessful();

        $existing = Permission::pluck('name');

        foreach ($declared as $permission) {
            $this->assertTrue(
                $existing->contains($permission),
                "El permiso '{$permission}' está declarado en un controlador pero no existe en la base de datos"
            );
        }
    }

    public function test_el_superadministrador_termina_con_todos_los_permisos(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('name', 'superadministrador')->firstOrFail();
        $role->revokePermissionTo('users.index');

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertSame(
            Permission::count(),
            $role->fresh()->permissions()->count(),
            'El superadministrador no quedó con todos los permisos'
        );
    }

    public function test_un_permiso_inexistente_da_403_y_no_error_500(): void
    {
        $this->seed(RoleSeeder::class);

        $branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $user = User::factory()->create(['number_phone' => '3000000000']);
        $user->assignRole($role);
        $user->branches()->attach($branch->id, ['role_id' => $role->id]);

        // Base de datos desactualizada: la pantalla exige un
        // permiso que ya no existe
        Permission::where('name', 'warehouses.index')->delete();

        $this->actingAs($user)
            ->withSession(['branch_id' => $branch->id, 'current_role_id' => $role->id])
            ->get(route('warehouses.index'))
            ->assertForbidden(); // 403, nunca 500
    }
}
