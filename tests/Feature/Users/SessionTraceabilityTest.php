<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\FailedLogin;
use App\Models\User;
use App\Models\UserSession;
use App\Support\UserAgentParser;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserTraceabilityPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Trazabilidad de sesiones de los usuarios.
 *
 * Se comprueba que iniciar y cerrar sesión deje rastro, que un
 * intento fallido se registre, que una sesión inactiva deje de
 * contar como activa, que el cierre remoto expulse de verdad, y que
 * todo esté detrás de su permiso.
 */
class SessionTraceabilityTest extends TestCase
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

    // ==================== Registro ====================

    public function test_iniciar_sesion_deja_rastro(): void
    {
        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'secret-clave',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertAuthenticatedAs($this->admin);

        $sesion = UserSession::where('user_id', $this->admin->id)->first();

        $this->assertNotNull($sesion);
        $this->assertSame($this->branch->id, $sesion->branch_id);
        $this->assertNotNull($sesion->login_at);
        $this->assertNotNull($sesion->ip_address);
        $this->assertNull($sesion->logout_at);
    }

    public function test_cerrar_sesion_marca_la_salida(): void
    {
        // El id de la fila viaja en la sesión (_trace_session_id).
        // En pruebas el driver de sesión es 'array' y no persiste
        // entre peticiones, así que se siembra en la misma petición
        // del logout, que es lo que ocurre en un navegador real.
        $sesion = $this->sesion();

        $this->actingAs($this->admin)
            ->withSession([
                'branch_id' => (string) $this->branch->id,
                'current_role_id' => (string) $this->superRole->id,
                '_trace_session_id' => $sesion->id,
            ])
            ->post('/logout')
            ->assertRedirect();

        $sesion->refresh();
        $this->assertNotNull($sesion->logout_at);
        $this->assertSame(UserSession::REASON_MANUAL, $sesion->logout_reason);
    }

    public function test_un_intento_fallido_se_registra(): void
    {
        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'clave-incorrecta',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertGuest();

        $fallido = FailedLogin::where('email', $this->admin->email)->first();

        $this->assertNotNull($fallido);
        $this->assertSame($this->admin->id, $fallido->user_id);
        $this->assertNotNull($fallido->ip_address);
    }

    public function test_un_intento_con_correo_inexistente_tambien_se_registra(): void
    {
        $this->post('/login', [
            'email' => 'nadie@ejemplo.com',
            'password' => 'lo-que-sea',
            'branch_id' => $this->branch->id,
        ]);

        $fallido = FailedLogin::where('email', 'nadie@ejemplo.com')->first();

        $this->assertNotNull($fallido);
        $this->assertNull($fallido->user_id, 'No hay usuario, pero el intento debe quedar registrado');
    }

    // ==================== Actividad y estado ====================

    private function sesion(array $atributos = []): UserSession
    {
        return UserSession::create(array_merge([
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
            'session_id' => 'sess-' . uniqid(),
            'ip_address' => '190.0.0.1',
            'user_agent' => 'Test',
            'browser' => 'Chrome', 'platform' => 'Windows 10/11', 'device_type' => 'Escritorio',
            'login_at' => now(),
            'last_activity_at' => now(),
        ], $atributos));
    }

    public function test_una_sesion_sin_actividad_reciente_deja_de_estar_activa(): void
    {
        $vieja = $this->sesion([
            'last_activity_at' => now()->subMinutes((int) config('session.lifetime') + 5),
        ]);
        $viva = $this->sesion(['last_activity_at' => now()]);

        $activas = UserSession::query()->active()->pluck('id');

        $this->assertTrue($activas->contains($viva->id));
        $this->assertFalse($activas->contains($vieja->id), 'La sesión inactiva no debe contar como activa');
    }

    public function test_el_barrido_marca_como_expiradas_las_inactivas(): void
    {
        $vieja = $this->sesion([
            'last_activity_at' => now()->subMinutes((int) config('session.lifetime') + 5),
        ]);

        $this->artisan('sessions:sweep')->assertSuccessful();

        $vieja->refresh();
        $this->assertNotNull($vieja->logout_at);
        $this->assertSame(UserSession::REASON_EXPIRED, $vieja->logout_reason);
    }

    public function test_no_marca_las_sesiones_todavia_activas(): void
    {
        $viva = $this->sesion(['last_activity_at' => now()]);

        $this->artisan('sessions:sweep');

        $this->assertNull($viva->fresh()->logout_at);
    }

    // ==================== Cierre remoto ====================

    public function test_cerrar_una_sesion_de_forma_remota(): void
    {
        $this->comoAdmin();
        $otra = $this->sesion();

        $this->post(route('users.sessions.close', [$this->admin, $otra]))
            ->assertRedirect()
            ->assertSessionHas('success-update');

        $otra->refresh();
        $this->assertNotNull($otra->logout_at);
        $this->assertSame(UserSession::REASON_FORCED, $otra->logout_reason);
    }

    public function test_no_se_puede_cerrar_la_sesion_de_otro_usuario_por_la_url(): void
    {
        $this->comoAdmin();

        $otroUsuario = User::factory()->create(['number_phone' => '3011111111']);
        $sesionAjena = UserSession::create([
            'user_id' => $otroUsuario->id,
            'session_id' => 'ajena', 'ip_address' => '1.1.1.1',
            'login_at' => now(), 'last_activity_at' => now(),
        ]);

        // Se pide cerrar la sesión ajena pasando el id del admin
        $this->post(route('users.sessions.close', [$this->admin, $sesionAjena]))
            ->assertNotFound();

        $this->assertNull($sesionAjena->fresh()->logout_at);
    }

    public function test_el_middleware_expulsa_a_una_sesion_cerrada_remotamente(): void
    {
        // Sesión ya cerrada de forma remota, referenciada por la
        // sesión viva de esta petición
        $sesion = $this->sesion([
            'logout_at' => now(),
            'logout_reason' => UserSession::REASON_FORCED,
        ]);

        $respuesta = $this->actingAs($this->admin)
            ->withSession([
                'branch_id' => (string) $this->branch->id,
                'current_role_id' => (string) $this->superRole->id,
                '_trace_session_id' => $sesion->id,
            ])
            ->get('/gestisp/users');

        // El middleware detecta el cierre forzado y lo expulsa
        $respuesta->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ==================== Pantalla ====================

    public function test_la_pantalla_de_trazabilidad_carga(): void
    {
        $this->comoAdmin();
        $this->sesion();
        $this->sesion(['logout_at' => now(), 'logout_reason' => UserSession::REASON_MANUAL]);

        $this->get(route('users.show', $this->admin))
            ->assertOk()
            ->assertSee('Sesiones activas')
            ->assertSee('Historial de sesiones')
            ->assertSee('190.0.0.1');
    }

    public function test_sin_permiso_no_se_ve_la_trazabilidad(): void
    {
        $sinPermiso = User::factory()->create(['number_phone' => '3022222222']);
        $rol = Role::where('name', 'tecnico')->firstOrFail();
        $sinPermiso->assignRole($rol);
        $sinPermiso->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($sinPermiso)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('users.show', $this->admin))->assertForbidden();
    }

    public function test_sin_permiso_no_se_puede_cerrar_una_sesion(): void
    {
        $rol = Role::where('name', 'tecnico')->firstOrFail();
        // Se le da ver la trazabilidad pero NO cerrar sesiones
        $rol->givePermissionTo('users.trace');

        $usuario = User::factory()->create(['number_phone' => '3033333333']);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $sesion = $this->sesion();

        $this->post(route('users.sessions.close', [$this->admin, $sesion]))
            ->assertForbidden();

        $this->assertNull($sesion->fresh()->logout_at);
    }

    // ==================== Parser ====================

    public function test_el_parser_distingue_navegador_y_equipo(): void
    {
        $edge = UserAgentParser::parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36 Edg/120.0'
        );
        $this->assertSame('Edge', $edge['browser'], 'Edge no debe confundirse con Chrome');
        $this->assertSame('Escritorio', $edge['device_type']);

        $movil = UserAgentParser::parse(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1 Mobile/15E148 Safari/604.1'
        );
        $this->assertSame('iOS', $movil['platform']);
        $this->assertSame('Móvil', $movil['device_type']);
    }
}
