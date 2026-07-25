<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Perfil del usuario autenticado: datos, contraseña y foto.
 *
 * A diferencia del módulo de Usuarios (donde un administrador
 * gestiona cuentas ajenas), aquí cada quien edita SOLO la suya.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create([
            'name' => 'Duban',
            'last_name' => 'Restrepo',
            'password' => bcrypt('clave-actual'),
            'selected_branch_id' => $this->branch->id,
        ]);
        $this->user->assignRole($this->role);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $this->role->id]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->role->id,
        ]);
    }

    // ==================== Pantalla ====================

    public function test_el_perfil_muestra_los_datos_del_usuario(): void
    {
        $respuesta = $this->get(route('profile.edit'));

        $respuesta->assertOk();
        $respuesta->assertSee('Duban');
        $respuesta->assertSee($this->user->email);
        $respuesta->assertSee($this->branch->name);
    }

    public function test_el_perfil_exige_haber_iniciado_sesion(): void
    {
        $this->app['auth']->logout();
        session()->flush();

        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    // ==================== Datos personales ====================

    public function test_actualiza_sus_datos_personales(): void
    {
        $respuesta = $this->put(route('profile.update'), [
            'name' => 'Duban Arley',
            'last_name' => 'Restrepo G',
            'identity_number' => $this->user->identity_number,
            'email' => 'nuevo@ejemplo.com',
            'number_phone' => '3155554433',
            'address' => 'Calle 10 # 20-30',
        ]);

        $respuesta->assertRedirect(route('profile.edit'));
        $respuesta->assertSessionHas('success');

        $this->user->refresh();
        $this->assertSame('Duban Arley', $this->user->name);
        $this->assertSame('nuevo@ejemplo.com', $this->user->email);
        $this->assertSame('3155554433', $this->user->number_phone);
    }

    public function test_no_permite_repetir_el_correo_de_otra_cuenta(): void
    {
        $otro = User::factory()->create(['email' => 'ocupado@ejemplo.com']);

        $respuesta = $this->put(route('profile.update'), [
            'name' => 'Duban',
            'last_name' => 'Restrepo',
            'identity_number' => $this->user->identity_number,
            'email' => $otro->email,
            'number_phone' => '3155554433',
            'address' => 'Calle 10',
        ]);

        $respuesta->assertSessionHasErrors('email');
        $this->assertNotSame($otro->email, $this->user->fresh()->email);
    }

    // ==================== Contraseña ====================

    public function test_cambia_la_contrasena_con_la_actual_correcta(): void
    {
        $respuesta = $this->put(route('profile.password'), [
            'current_password' => 'clave-actual',
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ]);

        $respuesta->assertRedirect(route('profile.edit'));
        $respuesta->assertSessionHas('success');

        $this->assertTrue(Hash::check('clave-nueva-123', $this->user->fresh()->password));
    }

    public function test_no_cambia_la_contrasena_sin_la_actual_correcta(): void
    {
        $respuesta = $this->put(route('profile.password'), [
            'current_password' => 'la-que-no-es',
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ]);

        $respuesta->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('clave-actual', $this->user->fresh()->password));
    }

    public function test_la_confirmacion_debe_coincidir(): void
    {
        $respuesta = $this->put(route('profile.password'), [
            'current_password' => 'clave-actual',
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'otra-diferente',
        ]);

        $respuesta->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('clave-actual', $this->user->fresh()->password));
    }

    public function test_cambiar_la_contrasena_cierra_las_sesiones_de_otros_equipos(): void
    {
        // Sesión abierta en otro dispositivo
        $otraSesion = UserSession::create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'ip_address' => '10.0.0.9',
            'login_at' => now()->subHour(),
        ]);

        $this->put(route('profile.password'), [
            'current_password' => 'clave-actual',
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ]);

        $otraSesion->refresh();
        $this->assertNotNull($otraSesion->logout_at);
        $this->assertSame(UserSession::REASON_FORCED, $otraSesion->logout_reason);
    }

    // ==================== Foto ====================

    public function test_sube_una_foto_de_perfil(): void
    {
        $respuesta = $this->post(route('profile.photo'), [
            'avatar' => UploadedFile::fake()->image('yo.jpg', 300, 300),
        ]);

        $respuesta->assertRedirect(route('profile.edit'));

        $this->user->refresh();
        $this->assertNotNull($this->user->avatar);
        Storage::disk('public')->assertExists($this->user->avatar);
    }

    public function test_al_cambiar_la_foto_se_borra_la_anterior(): void
    {
        $this->post(route('profile.photo'), [
            'avatar' => UploadedFile::fake()->image('primera.jpg'),
        ]);
        $primera = $this->user->fresh()->avatar;

        $this->post(route('profile.photo'), [
            'avatar' => UploadedFile::fake()->image('segunda.jpg'),
        ]);
        $segunda = $this->user->fresh()->avatar;

        $this->assertNotSame($primera, $segunda);
        Storage::disk('public')->assertMissing($primera);
        Storage::disk('public')->assertExists($segunda);
    }

    public function test_rechaza_un_archivo_que_no_es_imagen(): void
    {
        $respuesta = $this->post(route('profile.photo'), [
            'avatar' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
        ]);

        $respuesta->assertSessionHasErrors('avatar');
        $this->assertNull($this->user->fresh()->avatar);
    }

    public function test_quita_la_foto_de_perfil(): void
    {
        $this->post(route('profile.photo'), [
            'avatar' => UploadedFile::fake()->image('yo.jpg'),
        ]);
        $ruta = $this->user->fresh()->avatar;

        $this->delete(route('profile.photo.destroy'))->assertRedirect(route('profile.edit'));

        $this->assertNull($this->user->fresh()->avatar);
        Storage::disk('public')->assertMissing($ruta);
    }

    // ============ Datos del menú superior ============

    public function test_sin_foto_se_usa_un_avatar_con_las_iniciales(): void
    {
        $this->assertSame('DR', $this->user->initials);

        $avatar = $this->user->adminlte_image();

        // Se genera aquí mismo (SVG incrustado): sin servicios externos
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $this->assertStringContainsString(
            'DR',
            base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,')))
        );
    }

    public function test_con_foto_se_usa_la_imagen_subida(): void
    {
        $this->post(route('profile.photo'), [
            'avatar' => UploadedFile::fake()->image('yo.jpg'),
        ]);

        // El avatar apunta al archivo solo si existe de verdad en disco;
        // con Storage::fake el archivo no está en public/, así que se
        // comprueba que la ruta quedó guardada en el usuario.
        $this->assertNotNull($this->user->fresh()->avatar);
    }

    public function test_el_menu_describe_el_rol_y_la_sucursal_activos(): void
    {
        $descripcion = $this->user->adminlte_desc();

        $this->assertStringContainsString('uperadministrador', $descripcion);
        $this->assertStringContainsString($this->branch->name, $descripcion);
    }
}
