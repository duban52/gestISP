<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Integridad del contexto de sesión (sucursal y rol activos).
 *
 * Una sesión autenticada PERO sin sucursal/rol dejaba al usuario
 * encerrado con un 403 ("No tienes permiso para realizar esta
 * acción") en el propio dashboard. Pasaba al restablecer la
 * contraseña y al reabrir sesión con "recordarme".
 *
 * También se cubre aquí el cierre automático por inactividad.
 */
class SessionContextTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private Role $superRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->superRole = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create([
            'password' => bcrypt('clave-vieja'),
            'selected_branch_id' => $this->branch->id,
        ]);
        $this->user->assignRole($this->superRole);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $this->superRole->id]);
    }

    // ============ Recomposición del contexto ============

    public function test_una_sesion_sin_sucursal_activa_se_recompone_sola(): void
    {
        // Autenticado pero SIN branch_id/current_role_id: es lo que
        // dejaba el restablecimiento de contraseña o "recordarme".
        $respuesta = $this->actingAs($this->user)->get('/');

        // Antes: 403 sin salida. Ahora entra al dashboard.
        $respuesta->assertOk();

        $this->assertSame((string) $this->branch->id, session('branch_id'));
        $this->assertSame((string) $this->superRole->id, session('current_role_id'));
    }

    public function test_sin_sucursales_asignadas_se_pide_iniciar_sesion(): void
    {
        // Usuario sin ninguna sucursal: el contexto no se puede
        // recomponer, pero tampoco debe quedar encerrado en un 403.
        $huerfano = User::factory()->create(['selected_branch_id' => null]);
        $huerfano->assignRole($this->superRole);

        $respuesta = $this->actingAs($huerfano)->get('/');

        $respuesta->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ============ Restablecimiento de contraseña ============

    public function test_restablecer_la_contrasena_lleva_al_login_y_no_deja_sesion_a_medias(): void
    {
        $token = Password::broker()->createToken($this->user);

        $respuesta = $this->post('/password/reset', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ]);

        // Antes redirigía a /home (que no existe en este proyecto)
        // dejando además una sesión autenticada sin sucursal.
        $respuesta->assertRedirect(route('login'));
        $respuesta->assertSessionHas('status');
        $this->assertGuest();

        // La contraseña sí se cambió
        $this->assertTrue(
            Auth::validate(['email' => $this->user->email, 'password' => 'clave-nueva-123'])
        );
    }

    // ============ Cierre por inactividad ============

    public function test_la_sesion_se_cierra_tras_el_tiempo_de_inactividad(): void
    {
        config(['session.inactivity_timeout' => 15]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
            // Última actividad hace 16 minutos
            'last_activity_at' => time() - (16 * 60),
        ]);

        $respuesta = $this->get('/');

        $respuesta->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_la_sesion_sigue_viva_dentro_del_tiempo_permitido(): void
    {
        config(['session.inactivity_timeout' => 15]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
            // Actividad hace 5 minutos: aún dentro del margen
            'last_activity_at' => time() - (5 * 60),
        ]);

        $this->get('/')->assertOk();
    }

    public function test_el_cierre_por_inactividad_queda_registrado(): void
    {
        config(['session.inactivity_timeout' => 15]);

        // Sesión con rastro abierto en la trazabilidad
        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
            'last_activity_at' => time() - (20 * 60),
        ]);

        $rastro = UserSession::create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'ip_address' => '127.0.0.1',
            'login_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(20),
        ]);

        session(['_trace_session_id' => $rastro->id]);

        $this->get('/')->assertRedirect(route('login'));

        $rastro->refresh();
        $this->assertNotNull($rastro->logout_at);
        $this->assertSame(UserSession::REASON_EXPIRED, $rastro->logout_reason);
    }

    public function test_un_sondeo_automatico_no_mantiene_viva_la_sesion(): void
    {
        config(['session.inactivity_timeout' => 15]);

        $haceDiezMinutos = time() - (10 * 60);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
            'last_activity_at' => $haceDiezMinutos,
        ]);

        // El badge de notificaciones se consulta solo: no debe
        // contar como actividad del usuario.
        $this->get(route('notifications.poll'));

        $this->assertSame($haceDiezMinutos, session('last_activity_at'));
    }

    public function test_la_navegacion_real_si_renueva_la_actividad(): void
    {
        config(['session.inactivity_timeout' => 15]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
            'last_activity_at' => time() - (10 * 60),
        ]);

        $this->get('/')->assertOk();

        // Tras navegar, el contador vuelve a empezar
        $this->assertGreaterThan(time() - 5, session('last_activity_at'));
    }
}
