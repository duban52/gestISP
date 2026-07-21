<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserTraceabilityPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Control de acceso de los usuarios: habilitar/inhabilitar y cerrar
 * sus sesiones.
 *
 * Se comprueba que inhabilitar bloquee el login, expulse a quien ya
 * estaba dentro y cierre sus sesiones; que habilitar lo restablezca;
 * y que las acciones estén detrás de su permiso y protegidas contra
 * la autoinhabilitación.
 */
class UserAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Role $superRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(UserTraceabilityPermissionSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->superRole = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create([
            'number_phone' => '3000000000',
            'password' => bcrypt('secret-clave'),
            'is_active' => true,
        ]);
        $this->admin->assignRole($this->superRole);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $this->superRole->id]);
    }

    private function comoAdmin(): void
    {
        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
        ]);
    }

    private function otroUsuario(bool $activo = true): User
    {
        $u = User::factory()->create([
            'number_phone' => '30' . random_int(10000000, 99999999),
            'password' => bcrypt('clave-usuario'),
            'is_active' => $activo,
        ]);
        $u->assignRole(Role::where('name', 'tecnico')->firstOrFail());
        $u->branches()->attach($this->branch->id, [
            'role_id' => Role::where('name', 'tecnico')->firstOrFail()->id,
        ]);

        return $u;
    }

    private function sesionDe(User $user, array $atributos = []): UserSession
    {
        return UserSession::create(array_merge([
            'user_id' => $user->id,
            'branch_id' => $this->branch->id,
            'session_id' => 'sess-' . uniqid(),
            'ip_address' => '190.0.0.1',
            'browser' => 'Chrome', 'platform' => 'Windows 10/11', 'device_type' => 'Escritorio',
            'login_at' => now(), 'last_activity_at' => now(),
        ], $atributos));
    }

    // ==================== Login bloqueado ====================

    public function test_un_usuario_inhabilitado_no_puede_iniciar_sesion(): void
    {
        $usuario = $this->otroUsuario(activo: false);

        $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'clave-usuario',
            'branch_id' => $this->branch->id,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_el_mensaje_distingue_al_usuario_inhabilitado(): void
    {
        $usuario = $this->otroUsuario(activo: false);

        $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'clave-usuario',
            'branch_id' => $this->branch->id,
        ])->assertSessionHasErrors(['email' => 'Su usuario está inhabilitado. Comuníquese con un administrador.']);
    }

    public function test_un_usuario_habilitado_si_puede_iniciar_sesion(): void
    {
        $usuario = $this->otroUsuario(activo: true);

        $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'clave-usuario',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertAuthenticatedAs($usuario);
    }

    // ==================== Inhabilitar ====================

    public function test_inhabilitar_cierra_las_sesiones_activas(): void
    {
        $this->comoAdmin();
        $usuario = $this->otroUsuario();
        $sesion = $this->sesionDe($usuario);

        $this->post(route('users.toggle-active', $usuario))
            ->assertRedirect()
            ->assertSessionHas('success-update');

        $this->assertFalse($usuario->fresh()->is_active);
        $this->assertNotNull($sesion->fresh()->logout_at);
        $this->assertSame(UserSession::REASON_FORCED, $sesion->fresh()->logout_reason);
    }

    public function test_habilitar_restablece_el_acceso(): void
    {
        $this->comoAdmin();
        $usuario = $this->otroUsuario(activo: false);

        $this->post(route('users.toggle-active', $usuario))->assertRedirect();

        $this->assertTrue($usuario->fresh()->is_active);
    }

    public function test_no_se_puede_inhabilitar_a_uno_mismo(): void
    {
        $this->comoAdmin();

        $this->post(route('users.toggle-active', $this->admin))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_el_middleware_expulsa_a_un_usuario_inhabilitado_con_sesion_abierta(): void
    {
        $usuario = $this->otroUsuario(activo: false);

        // Autenticado pero ya inhabilitado: el middleware lo saca
        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) Role::where('name', 'tecnico')->firstOrFail()->id,
        ]);

        $this->get('/gestisp/users')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ==================== Cerrar todas las sesiones ====================

    public function test_cerrar_todas_las_sesiones_del_usuario(): void
    {
        $this->comoAdmin();
        $usuario = $this->otroUsuario();
        $s1 = $this->sesionDe($usuario);
        $s2 = $this->sesionDe($usuario);

        $this->post(route('users.sessions.close-all', $usuario))
            ->assertRedirect()
            ->assertSessionHas('success-update');

        $this->assertNotNull($s1->fresh()->logout_at);
        $this->assertNotNull($s2->fresh()->logout_at);
    }

    public function test_cerrar_las_propias_sesiones_conserva_la_actual(): void
    {
        $actual = $this->sesionDe($this->admin);
        $otra = $this->sesionDe($this->admin);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
            '_trace_session_id' => $actual->id,
        ]);

        $this->post(route('users.sessions.close-all', $this->admin))->assertRedirect();

        // La sesión desde la que se ejecuta no se cierra; la otra sí
        $this->assertNull($actual->fresh()->logout_at, 'La sesión actual no debe cerrarse');
        $this->assertNotNull($otra->fresh()->logout_at);
    }

    // ==================== Permisos ====================

    public function test_sin_permiso_no_se_puede_inhabilitar(): void
    {
        $usuario = $this->otroUsuario();

        // Auxiliar sin el permiso users.disable
        $aux = User::factory()->create(['number_phone' => '3099999999']);
        $rol = Role::where('name', 'auxiliar administrativo')->firstOrFail();
        $aux->assignRole($rol);
        $aux->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($aux)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->post(route('users.toggle-active', $usuario))->assertForbidden();

        $this->assertTrue($usuario->fresh()->is_active);
    }

    public function test_el_listado_muestra_el_estado(): void
    {
        $this->comoAdmin();
        $this->otroUsuario(activo: false);

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('Inhabilitado');
    }
}
