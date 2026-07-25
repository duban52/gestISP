<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Modo oscuro: interruptor en la barra superior y preferencia
 * guardada en el usuario.
 *
 * AdminLTE solo recuerda el tema en la sesión; aquí se comprueba que
 * además quede en la base de datos, para que no se pierda al cerrar
 * sesión (o al expirar por inactividad).
 */
class DarkModeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $this->user->assignRole($this->role);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $this->role->id]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->role->id,
        ]);
    }

    public function test_el_interruptor_aparece_en_la_barra_superior(): void
    {
        $respuesta = $this->get(route('profile.edit'));

        $respuesta->assertOk();
        $respuesta->assertSee('adminlte-darkmode-widget');
    }

    public function test_por_defecto_el_panel_se_ve_claro(): void
    {
        $respuesta = $this->get(route('profile.edit'));

        $respuesta->assertOk();
        $respuesta->assertDontSee('class="sidebar-mini dark-mode"', false);
    }

    public function test_activar_el_modo_oscuro_lo_guarda_en_el_usuario(): void
    {
        $this->assertFalse((bool) $this->user->dark_mode);

        $this->post(route('adminlte.darkmode.toggle'))->assertOk();

        $this->assertTrue((bool) $this->user->fresh()->dark_mode);
    }

    public function test_volver_a_pulsarlo_regresa_al_modo_claro(): void
    {
        $this->post(route('adminlte.darkmode.toggle'));
        $this->assertTrue((bool) $this->user->fresh()->dark_mode);

        $this->post(route('adminlte.darkmode.toggle'));
        $this->assertFalse((bool) $this->user->fresh()->dark_mode);
    }

    public function test_la_preferencia_guardada_se_aplica_al_cargar_la_pagina(): void
    {
        $this->user->forceFill(['dark_mode' => true])->save();

        $respuesta = $this->get(route('profile.edit'));

        $respuesta->assertOk();
        $respuesta->assertSee('dark-mode', false);
    }

    public function test_el_tema_sobrevive_a_una_sesion_nueva(): void
    {
        // El usuario dejó activado el modo oscuro
        $this->user->forceFill(['dark_mode' => true])->save();

        // Sesión nueva (como tras un cierre por inactividad): la clave
        // del tema no está en la sesión y debe reponerse desde el
        // usuario, no volver al tema claro.
        $this->app['auth']->logout();
        session()->flush();

        $this->actingAs($this->user->fresh())->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->role->id,
        ]);

        $this->get(route('profile.edit'))->assertSee('dark-mode', false);
        $this->assertTrue(session('adminlte_dark_mode'));
    }

    public function test_cargar_paginas_no_cambia_la_preferencia(): void
    {
        $this->get(route('profile.edit'));
        $this->get(route('profile.edit'));

        // Solo el interruptor debe escribir: navegar no altera el tema
        $this->assertFalse((bool) $this->user->fresh()->dark_mode);
    }

    public function test_los_estilos_propios_del_modo_oscuro_se_cargan(): void
    {
        $respuesta = $this->get(route('profile.edit'));

        // Hoja con los ajustes de las tablas, DataTables y Select2
        $respuesta->assertSee('css/gestisp/dark-mode.css', false);
    }
}
