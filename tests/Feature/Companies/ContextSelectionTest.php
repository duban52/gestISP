<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\ContextResolver;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Elección y cambio del contexto de trabajo (fase 3, paso A).
 *
 * QUÉ SE DEFIENDE
 * ---------------
 * 1. Que solo se pregunte cuando de verdad hay algo que elegir.
 * 2. Que un usuario NO pueda entrar a una empresa o sucursal que no
 *    tiene concedida, aunque escriba la petición a mano. Que la
 *    pantalla solo le ofrezca lo suyo no prueba nada.
 * 3. Que el panel consolidado solo exista donde la empresa lo tiene
 *    activado.
 * 4. Que el ROL salga de user_branch y nunca de la petición.
 * 5. Que al cambiar de contexto cambie de verdad lo que se ve.
 */
class ContextSelectionTest extends TestCase
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

    private function usuarioCon(array $sucursales): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);

        foreach ($sucursales as $sucursal) {
            $usuario->branches()->attach($sucursal->id, ['role_id' => $this->rol->id]);
        }

        return $usuario;
    }

    // ==================== Cuándo se pregunta ====================

    public function test_con_una_sola_sucursal_no_se_pregunta(): void
    {
        // Una pantalla con una única opción es un clic de más en cada
        // acceso.
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        $unico = app(ContextResolver::class)->unicoPara($usuario);

        $this->assertSame($sucursal->company_id, $unico['company_id']);
        $this->assertSame($sucursal->id, $unico['branch_id']);
    }

    public function test_con_dos_sucursales_de_la_misma_empresa_si_se_pregunta(): void
    {
        $empresa = Company::factory()->create();
        $usuario = $this->usuarioCon([
            Branch::factory()->create(['company_id' => $empresa->id]),
            Branch::factory()->create(['company_id' => $empresa->id]),
        ]);

        $this->assertNull(app(ContextResolver::class)->unicoPara($usuario));
    }

    public function test_una_empresa_consolidada_con_varias_sedes_no_pregunta(): void
    {
        // El panel consolidado ya abarca todas: no hay nada que elegir.
        $empresa = Company::factory()->consolidada()->create();
        $usuario = $this->usuarioCon([
            Branch::factory()->create(['company_id' => $empresa->id]),
            Branch::factory()->create(['company_id' => $empresa->id]),
        ]);

        $unico = app(ContextResolver::class)->unicoPara($usuario);

        $this->assertSame($empresa->id, $unico['company_id']);
        $this->assertNull($unico['branch_id']);
    }

    public function test_con_dos_empresas_siempre_se_pregunta(): void
    {
        $usuario = $this->usuarioCon([
            Branch::factory()->create(),
            Branch::factory()->create(),
        ]);

        $this->assertNull(app(ContextResolver::class)->unicoPara($usuario));
    }

    // ==================== Qué se ofrece ====================

    public function test_solo_se_ofrecen_las_empresas_del_usuario(): void
    {
        $suya = Branch::factory()->create();
        Branch::factory()->create(); // de otra empresa, sin acceso

        $usuario = $this->usuarioCon([$suya]);

        $disponibles = app(ContextResolver::class)->disponiblesPara($usuario);

        $this->assertCount(1, $disponibles);
        $this->assertSame($suya->company_id, $disponibles->first()['empresa']->id);
    }

    public function test_se_ven_las_sucursales_de_todas_sus_empresas(): void
    {
        // La trampa: Branch está acotado por empresa, así que al listar
        // contextos el scope escondería las sucursales de las demás
        // empresas del propio usuario — justo cuando hacen falta.
        $usuario = $this->usuarioCon([
            Branch::factory()->create(),
            Branch::factory()->create(),
            Branch::factory()->create(),
        ]);

        $this->assertCount(3, app(ContextResolver::class)->disponiblesPara($usuario));
    }

    // ==================== Que no se pueda forzar ====================

    public function test_no_se_puede_entrar_a_una_empresa_ajena(): void
    {
        $usuario = $this->usuarioCon([Branch::factory()->create()]);
        $ajena = Company::factory()->create();

        $this->expectException(RuntimeException::class);

        app(ContextResolver::class)->aplicar($usuario, $ajena->id, null);
    }

    public function test_no_se_puede_entrar_a_una_sucursal_ajena_de_su_propia_empresa(): void
    {
        // Caso fino: la empresa sí es suya, la sucursal no.
        $empresa = Company::factory()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        $ajena = Branch::factory()->create(['company_id' => $empresa->id]);

        $usuario = $this->usuarioCon([$suya]);

        $this->expectException(RuntimeException::class);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, $ajena->id);
    }

    public function test_no_se_puede_pedir_panel_consolidado_si_la_empresa_no_lo_usa(): void
    {
        // Se pide omitiendo la sucursal en la petición. Sin esta
        // comprobación, cualquiera vería todas las sedes de su empresa
        // aunque solo tenga acceso a una.
        $empresa = Company::factory()->create(); // independiente
        $usuario = $this->usuarioCon([
            Branch::factory()->create(['company_id' => $empresa->id]),
        ]);

        $this->expectException(RuntimeException::class);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, null);
    }

    public function test_por_http_una_empresa_ajena_se_rechaza(): void
    {
        $usuario = $this->usuarioCon([Branch::factory()->create()]);
        $ajena = Company::factory()->create();

        $this->actingAs($usuario)
            ->post(route('context.store'), ['company_id' => $ajena->id])
            ->assertSessionHasErrors('company_id');
    }

    // ==================== Lo que queda al entrar ====================

    public function test_al_entrar_se_fija_empresa_sucursal_y_rol(): void
    {
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        app(ContextResolver::class)->aplicar($usuario, $sucursal->company_id, $sucursal->id);

        $contexto = app(CurrentContext::class);

        $this->assertTrue($contexto->activo());
        $this->assertSame($sucursal->company_id, $contexto->companyId());
        $this->assertSame($sucursal->id, $contexto->branchId());
        $this->assertFalse($contexto->esConsolidado());

        // El rol sale de user_branch, nunca de la petición
        $this->assertSame((string) $this->rol->id, session('current_role_id'));
        $this->assertSame($sucursal->id, $usuario->fresh()->selected_branch_id);
    }

    public function test_el_panel_consolidado_abarca_todas_sus_sedes(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);
        // Una tercera de la misma empresa a la que NO tiene acceso
        $sinAcceso = Branch::factory()->create(['company_id' => $empresa->id]);

        $usuario = $this->usuarioCon([$una, $otra]);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, null);

        $contexto = app(CurrentContext::class);

        $this->assertTrue($contexto->esConsolidado());
        $this->assertNull($contexto->branchId());
        // Consolidado NO significa "todas las de la empresa": significa
        // todas las que ESTE usuario tiene concedidas.
        $this->assertEqualsCanonicalizing([$una->id, $otra->id], $contexto->branchIds());
        $this->assertFalse($contexto->permiteSucursal($sinAcceso->id));
    }

    public function test_cambiar_de_contexto_cambia_lo_que_se_ve(): void
    {
        $primera = Branch::factory()->create();
        $segunda = Branch::factory()->create();
        $usuario = $this->usuarioCon([$primera, $segunda]);

        $resolver = app(ContextResolver::class);
        $contexto = app(CurrentContext::class);

        $resolver->aplicar($usuario, $primera->company_id, $primera->id);
        $this->assertSame($primera->company_id, $contexto->companyId());

        // Sin cerrar sesión
        $resolver->aplicar($usuario, $segunda->company_id, $segunda->id);
        $this->assertSame($segunda->company_id, $contexto->companyId());
    }

    public function test_el_cambio_queda_en_la_trazabilidad(): void
    {
        // Responde "¿desde qué empresa hizo esto?" cuando alguien con
        // acceso a varias revisa una acción meses después.
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        $this->actingAs($usuario)->post(route('context.store'), [
            'company_id' => $sucursal->company_id,
            'branch_id' => $sucursal->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('audits', ['action' => 'context.switched']);
    }

    // ==================== La pantalla ====================

    public function test_con_una_sola_opcion_la_pantalla_se_puede_abrir_igual(): void
    {
        // Al ENTRAR no se muestra —se pasa de largo, ver PreLoginTest—,
        // pero si el usuario la abre a proposito estando dentro se le
        // enseña aunque solo tenga una opcion: devolverlo en silencio
        // pareceria que el boton no funciona.
        $sucursal = Branch::factory()->create();
        $usuario = $this->usuarioCon([$sucursal]);

        $this->actingAs($usuario)->get(route('context.select'))
            ->assertOk()
            ->assertSee($sucursal->name);
    }

    public function test_sin_contexto_no_se_pueden_ver_datos(): void
    {
        // Con varias empresas, EnsureBranchSession ya no elige por el
        // usuario. Si navegara sin contexto lo haria SIN la barrera de
        // aislamiento, asi que se le manda a elegir.
        $usuario = $this->usuarioCon([
            Branch::factory()->create(),
            Branch::factory()->create(),
        ]);

        $this->actingAs($usuario)->get(route('technicals_orders.index'))
            ->assertRedirect(route('context.select'));
    }

    public function test_la_pantalla_ofrece_las_dos_empresas(): void
    {
        $primera = Branch::factory()->create();
        $segunda = Branch::factory()->create();
        $usuario = $this->usuarioCon([$primera, $segunda]);

        $this->actingAs($usuario)->get(route('context.select'))
            ->assertOk()
            ->assertSee($primera->company->nombreVisible())
            ->assertSee($segunda->company->nombreVisible());
    }

    public function test_un_usuario_sin_sucursales_no_se_queda_encerrado(): void
    {
        // Antes esto dejaba al usuario dando vueltas con un 403 en el
        // propio panel, sin forma de salir ni de entender por qué.
        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);

        $this->actingAs($usuario)->get(route('context.select'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
