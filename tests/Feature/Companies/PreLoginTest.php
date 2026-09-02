<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Prelogin: primero quién eres, después desde dónde trabajas.
 *
 * QUÉ CAMBIÓ Y POR QUÉ
 * --------------------
 * El formulario de acceso pedía la sucursal ANTES de comprobar las
 * credenciales. Para poder ofrecerla existía `GET /user/branches`, una
 * ruta pública que, dado un correo, respondía si existía un usuario
 * con él y a qué sucursales pertenecía.
 *
 * Eso es enumeración de usuarios servida en bandeja: sin autenticarse,
 * cualquiera podía averiguar qué correos son válidos y cómo está
 * organizada la empresa. Y además impedía el multiempresa — un
 * desplegable de sucursales sueltas no distingue de qué empresa es
 * cada una.
 *
 * Lo que se defiende aquí:
 *
 *  1. Que esa ruta ya no exista.
 *  2. Que el acceso no pida ni acepte sucursal.
 *  3. Que quien tiene un solo contexto entre directo, sin pantallas
 *     de más.
 *  4. Que quien tiene varios elija, y solo entre los suyos.
 */
class PreLoginTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();
    }

    private function usuarioCon(array $sucursales, string $clave = 'clave-correcta'): User
    {
        $usuario = User::factory()->create(['password' => bcrypt($clave)]);
        $usuario->assignRole($this->rol);

        foreach ($sucursales as $sucursal) {
            $usuario->branches()->attach($sucursal->id, ['role_id' => $this->rol->id]);
        }

        return $usuario;
    }

    // ==================== La ruta que se retiró ====================

    public function test_ya_no_se_puede_averiguar_a_que_sucursales_pertenece_un_correo(): void
    {
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        // Sin autenticar. Antes esto respondía la lista de sucursales.
        $respuesta = $this->get('/user/branches?email=' . urlencode($usuario->email));

        $respuesta->assertNotFound();
        $respuesta->assertDontSee($sucursal->name);
    }

    public function test_el_formulario_de_acceso_ya_no_pide_sucursal(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('name="branch_id"', false)
            ->assertDontSee('user/branches', false);
    }

    // ==================== Entrar ====================

    public function test_con_un_solo_contexto_se_entra_directo(): void
    {
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        $respuesta = $this->post(route('login'), [
            'email' => $usuario->email,
            'password' => 'clave-correcta',
        ]);

        // Ni pasa por el selector ni hace falta mandar la sucursal
        $respuesta->assertRedirect('/');
        $this->assertAuthenticatedAs($usuario);
        $this->assertSame((string) $sucursal->id, session('branch_id'));
        $this->assertSame($sucursal->company_id, session('company_id'));
    }

    public function test_con_varios_contextos_se_pasa_por_el_selector(): void
    {
        $usuario = $this->usuarioCon([
            Branch::factory()->create(),
            Branch::factory()->create(),
        ]);

        $this->post(route('login'), [
            'email' => $usuario->email,
            'password' => 'clave-correcta',
        ])->assertRedirect(route('context.select'));

        // Autenticado, pero todavía sin contexto: no puede ver datos
        $this->assertAuthenticatedAs($usuario);
        $this->assertNull(session('company_id'));
    }

    public function test_el_acceso_ya_no_exige_ni_acepta_sucursal(): void
    {
        // Mandar una sucursal ajena no cambia nada: el dato se ignora
        // y el contexto lo decide el servidor.
        $suya = Branch::factory()->create();
        $ajena = Branch::factory()->create();
        $usuario = $this->usuarioCon([$suya]);

        $this->post(route('login'), [
            'email' => $usuario->email,
            'password' => 'clave-correcta',
            'branch_id' => $ajena->id,
        ])->assertRedirect('/');

        $this->assertSame((string) $suya->id, session('branch_id'));
    }

    public function test_una_empresa_consolidada_entra_sin_elegir_sucursal(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $usuario = $this->usuarioCon([
            Branch::factory()->create(['company_id' => $empresa->id]),
            Branch::factory()->create(['company_id' => $empresa->id]),
        ]);

        $this->post(route('login'), [
            'email' => $usuario->email,
            'password' => 'clave-correcta',
        ])->assertRedirect('/');

        $this->assertSame($empresa->id, session('company_id'));
        $this->assertNull(session('branch_id'));
        $this->assertCount(2, session('branch_ids'));
    }

    public function test_el_inicio_de_sesion_sigue_quedando_en_la_trazabilidad(): void
    {
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        $this->post(route('login'), [
            'email' => $usuario->email,
            'password' => 'clave-correcta',
        ]);

        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $usuario->id,
            'branch_id' => $sucursal->id,
        ]);
    }

    public function test_una_clave_incorrecta_sigue_sin_entrar(): void
    {
        $usuario = $this->usuarioCon([Branch::factory()->create()]);

        $this->post(route('login'), [
            'email' => $usuario->email,
            'password' => 'la-que-no-es',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ==================== Cambiar sin salir ====================

    public function test_el_selector_se_puede_abrir_estando_dentro(): void
    {
        // Antes, cambiar de sucursal obligaba a cerrar sesión. Y con
        // una sola opción la pantalla tiene que mostrarse igual:
        // devolverlo en silencio parecería que el botón no funciona.
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        $this->post(route('login'), [
            'email' => $usuario->email,
            'password' => 'clave-correcta',
        ]);

        $this->get(route('context.select'))
            ->assertOk()
            ->assertSee($sucursal->name);
    }

    public function test_se_cambia_de_empresa_sin_cerrar_sesion(): void
    {
        $primera = Branch::factory()->create();
        $segunda = Branch::factory()->create();
        $usuario = $this->usuarioCon([$primera, $segunda]);

        $this->actingAs($usuario)->post(route('context.store'), [
            'company_id' => $primera->company_id,
            'branch_id' => $primera->id,
        ]);

        $this->assertSame($primera->company_id, session('company_id'));

        $this->post(route('context.store'), [
            'company_id' => $segunda->company_id,
            'branch_id' => $segunda->id,
        ])->assertRedirect();

        $this->assertSame($segunda->company_id, session('company_id'));
        $this->assertAuthenticatedAs($usuario);
    }
}
