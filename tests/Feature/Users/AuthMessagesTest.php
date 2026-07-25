<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mensajes de autenticación y restablecimiento de contraseña.
 *
 * Faltaban los archivos de idioma, así que el usuario veía la CLAVE
 * cruda en pantalla ("auth.failed", "passwords.user",
 * "validation.required") en lugar de un mensaje. Aquí se comprueba
 * que ya no ocurra y que cada mensaje explique la situación.
 */
class AuthMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create([
            'password' => bcrypt('clave-correcta'),
            'selected_branch_id' => $this->branch->id,
        ]);
        $this->user->assignRole($this->role);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $this->role->id]);
    }

    /** Ninguna traducción puede quedarse sin resolver. */
    private function assertSinClavesCrudas(array $mensajes): void
    {
        foreach ($mensajes as $mensaje) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(auth|passwords|validation)\./',
                $mensaje,
                "El mensaje quedó sin traducir: {$mensaje}"
            );
        }
    }

    // ==================== Inicio de sesión ====================

    public function test_credenciales_incorrectas_dan_un_mensaje_entendible(): void
    {
        $respuesta = $this->from(route('login'))->post(route('login'), [
            'email' => $this->user->email,
            'password' => 'clave-equivocada',
            'branch_id' => $this->branch->id,
        ]);

        $errores = session('errors')->getBag('default')->all();

        $this->assertSinClavesCrudas($errores);
        $this->assertStringContainsString('correo o la contraseña', $errores[0]);
    }

    public function test_un_usuario_inhabilitado_recibe_una_explicacion_clara(): void
    {
        $this->user->update(['is_active' => false]);

        $this->from(route('login'))->post(route('login'), [
            'email' => $this->user->email,
            'password' => 'clave-correcta',
            'branch_id' => $this->branch->id,
        ]);

        $errores = session('errors')->getBag('default')->all();

        $this->assertSinClavesCrudas($errores);
        $this->assertStringContainsString('inhabilitado', $errores[0]);
    }

    public function test_sin_sucursal_se_pide_seleccionarla(): void
    {
        $this->from(route('login'))->post(route('login'), [
            'email' => $this->user->email,
            'password' => 'clave-correcta',
        ]);

        $errores = session('errors')->getBag('default')->all();

        $this->assertSinClavesCrudas($errores);
        $this->assertStringContainsString('sucursal', $errores[0]);
    }

    public function test_los_campos_vacios_no_muestran_claves_de_validacion(): void
    {
        $this->from(route('login'))->post(route('login'), []);

        $errores = session('errors')->getBag('default')->all();

        $this->assertNotEmpty($errores);
        $this->assertSinClavesCrudas($errores);
    }

    // ============ Restablecimiento de contraseña ============

    public function test_un_correo_desconocido_recibe_un_mensaje_claro(): void
    {
        $this->from(route('password.request'))->post('/password/email', [
            'email' => 'noexiste@ejemplo.com',
        ]);

        $errores = session('errors')->getBag('default')->all();

        $this->assertSinClavesCrudas($errores);
        $this->assertStringContainsString('No encontramos', $errores[0]);
    }

    public function test_un_enlace_vencido_explica_que_hacer(): void
    {
        $this->from(route('password.request'))->post('/password/reset', [
            'token' => 'token-invalido',
            'email' => $this->user->email,
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ]);

        $errores = session('errors')->getBag('default')->all();

        $this->assertSinClavesCrudas($errores);
        $this->assertStringContainsString('no es válido o ya venció', $errores[0]);
    }

    public function test_contrasenas_que_no_coinciden_lo_dicen_asi(): void
    {
        $token = Password::broker()->createToken($this->user);

        $this->from(route('password.request'))->post('/password/reset', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'otra-distinta-456',
        ]);

        $errores = session('errors')->getBag('default')->all();

        $this->assertSinClavesCrudas($errores);
        $this->assertStringContainsString('no coinciden', $errores[0]);
    }

    public function test_el_login_muestra_el_aviso_tras_restablecer_la_contrasena(): void
    {
        // La vista de login no mostraba session('status'), así que el
        // aviso de éxito quedaba invisible.
        $respuesta = $this->withSession(['status' => 'Su contraseña se actualizó correctamente.'])
            ->get(route('login'));

        $respuesta->assertOk();
        $respuesta->assertSee('Su contraseña se actualizó correctamente.');
    }

    // ==================== Páginas de error ====================

    public function test_la_pagina_403_explica_el_motivo(): void
    {
        // Un usuario sin el permiso del módulo recibe la página propia
        $sinPermisos = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $rolBasico = Role::create(['name' => 'rol-sin-permisos', 'guard_name' => 'web']);
        $sinPermisos->assignRole($rolBasico);
        $sinPermisos->branches()->attach($this->branch->id, ['role_id' => $rolBasico->id]);

        $respuesta = $this->actingAs($sinPermisos)->get('/');

        $respuesta->assertForbidden();
        $respuesta->assertSee('No tiene permiso para entrar aquí');
        $respuesta->assertSee('Ir al inicio');
    }
}
