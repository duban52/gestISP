<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 de multiempresa: la entidad Empresa y su enlace con las
 * sucursales.
 *
 * QUÉ SE DEFIENDE AQUÍ
 * --------------------
 * 1. Que dos empresas puedan convivir. Hasta ahora era IMPOSIBLE:
 *    `branches.name` y `plans.name` eran únicas a nivel global, así
 *    que dos empresas no podían tener cada una su "Sucursal Principal"
 *    ni su plan "100 Megas".
 * 2. Que ninguna sucursal quede sin empresa. Una sucursal huérfana
 *    obligaría a preguntar "¿y si no tiene?" en cada consulta del
 *    sistema, y esa pregunta acaba olvidándose en algún sitio.
 * 3. Que el NIT deje de estar duplicado: las sucursales que comparten
 *    NIT son la misma empresa.
 *
 * Lo que esta fase NO hace todavía es aislar las consultas: eso es la
 * fase 2 (global scopes). Aquí solo se monta el andamiaje.
 */
class CompanyFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Convivencia de dos empresas ====================

    public function test_dos_empresas_pueden_tener_una_sucursal_con_el_mismo_nombre(): void
    {
        // Era imposible: branches.name tenía UNIQUE global.
        $primera = Company::factory()->create();
        $segunda = Company::factory()->create();

        Branch::factory()->create(['company_id' => $primera->id, 'name' => 'Sucursal Principal']);
        Branch::factory()->create(['company_id' => $segunda->id, 'name' => 'Sucursal Principal']);

        $this->assertSame(2, Branch::where('name', 'Sucursal Principal')->count());
    }

    public function test_una_empresa_no_puede_repetir_el_nombre_de_sucursal(): void
    {
        // Único POR EMPRESA, no global: dentro de la misma empresa dos
        // sucursales iguales sí serían un error de captura.
        $empresa = Company::factory()->create();

        Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Centro']);

        $this->expectException(QueryException::class);

        Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Centro']);
    }

    public function test_dos_sucursales_pueden_tener_un_plan_con_el_mismo_nombre(): void
    {
        // plans.name también tenía UNIQUE global. El plan es un
        // empaquetado comercial de la sede, no un dato fiscal.
        $usuario = User::factory()->create();
        $una = Branch::factory()->create();
        $otra = Branch::factory()->create();

        Plan::create(['name' => '100 Megas', 'branch_id' => $una->id, 'user_id' => $usuario->id]);
        Plan::create(['name' => '100 Megas', 'branch_id' => $otra->id, 'user_id' => $usuario->id]);

        $this->assertSame(2, Plan::where('name', '100 Megas')->count());
    }

    public function test_una_sucursal_no_puede_repetir_el_nombre_de_plan(): void
    {
        $usuario = User::factory()->create();
        $sucursal = Branch::factory()->create();

        Plan::create(['name' => '100 Megas', 'branch_id' => $sucursal->id, 'user_id' => $usuario->id]);

        $this->expectException(QueryException::class);

        Plan::create(['name' => '100 Megas', 'branch_id' => $sucursal->id, 'user_id' => $usuario->id]);
    }

    // ==================== Ninguna sucursal sin empresa ====================

    public function test_una_sucursal_creada_sin_empresa_toma_la_de_su_nit(): void
    {
        // Puente de compatibilidad: el formulario de sucursales sigue
        // pidiendo el NIT porque la pantalla de empresas aún no
        // existe. El NIT se traduce a empresa.
        $sucursal = Branch::factory()->create(['company_id' => null, 'nit' => '900123456']);

        $this->assertNotNull($sucursal->company_id);
        $this->assertSame('900123456', $sucursal->company->document_number);
    }

    public function test_dos_sucursales_con_el_mismo_nit_son_la_misma_empresa(): void
    {
        // Es el caso real: varias sedes de un solo contribuyente. Antes
        // el NIT estaba repetido en cada fila y podía divergir.
        $una = Branch::factory()->create(['company_id' => null, 'nit' => '900555111']);
        $otra = Branch::factory()->create(['company_id' => null, 'nit' => '900555111']);

        $this->assertSame($una->company_id, $otra->company_id);
        $this->assertSame(1, Company::where('document_number', '900555111')->count());
    }

    public function test_dos_sucursales_con_nit_distinto_son_empresas_distintas(): void
    {
        $una = Branch::factory()->create(['company_id' => null, 'nit' => '900111111']);
        $otra = Branch::factory()->create(['company_id' => null, 'nit' => '900222222']);

        $this->assertNotSame($una->company_id, $otra->company_id);
    }

    // ==================== La empresa ====================

    public function test_no_pueden_existir_dos_empresas_con_el_mismo_nit(): void
    {
        Company::factory()->create(['document_number' => '900999888']);

        $this->expectException(QueryException::class);

        Company::factory()->create(['document_number' => '900999888']);
    }

    public function test_la_identificacion_incluye_el_digito_de_verificacion(): void
    {
        $conDv = Company::factory()->create([
            'document_number' => '900123456',
            'verification_digit' => '7',
        ]);

        $sinDv = Company::factory()->create([
            'document_number' => '900123457',
            'verification_digit' => null,
        ]);

        $this->assertSame('900123456-7', $conDv->identificacion());
        $this->assertSame('900123457', $sinDv->identificacion());
    }

    public function test_la_empresa_nace_independiente_y_sin_facturacion_electronica(): void
    {
        // Los valores por defecto reproducen el comportamiento de
        // siempre: nadie que actualice se encuentra el sistema
        // cambiado sin haberlo pedido.
        $empresa = Company::create([
            'legal_name' => 'Fibra Andina S.A.S.',
            'document_number' => '901000111',
        ]);

        $this->assertFalse($empresa->esConsolidada());
        $this->assertFalse($empresa->electronic_invoicing_enabled);
        $this->assertTrue($empresa->active);
    }

    public function test_una_empresa_puede_trabajar_consolidada(): void
    {
        $empresa = Company::factory()->consolidada()->create();

        $this->assertTrue($empresa->esConsolidada());
    }

    public function test_el_nombre_visible_prefiere_el_comercial(): void
    {
        $conMarca = Company::factory()->create([
            'legal_name' => 'Comunicaciones del Norte S.A.S.',
            'trade_name' => 'NorteNet',
        ]);

        $sinMarca = Company::factory()->create([
            'legal_name' => 'Fibra Andina S.A.S.',
            'trade_name' => null,
        ]);

        $this->assertSame('NorteNet', $conMarca->nombreVisible());
        $this->assertSame('Fibra Andina S.A.S.', $sinMarca->nombreVisible());
    }

    // ==================== Relaciones ====================

    public function test_la_empresa_conoce_sus_sucursales_y_al_reves(): void
    {
        $empresa = Company::factory()->create();

        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);
        Branch::factory()->create(); // de otra empresa

        $this->assertEqualsCanonicalizing(
            [$norte->id, $sur->id],
            $empresa->branches->pluck('id')->all(),
        );

        $this->assertSame($empresa->id, $norte->company->id);
    }

    public function test_los_usuarios_de_la_empresa_se_deducen_de_sus_sucursales(): void
    {
        // No hay tabla usuario-empresa: la pertenencia se deduce de las
        // sucursales, que es donde se concede el acceso y el rol.
        // Guardarla aparte sería una segunda verdad que puede divergir.
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $dentro = User::factory()->create();
        $dentro->branches()->attach($sucursal->id);

        $fuera = User::factory()->create();
        $fuera->branches()->attach(Branch::factory()->create()->id);

        $ids = $empresa->users()->pluck('users.id')->all();

        $this->assertContains($dentro->id, $ids);
        $this->assertNotContains($fuera->id, $ids);
    }

    public function test_un_usuario_puede_pertenecer_a_dos_empresas(): void
    {
        // Escenario explícito del plan: Usuario A en Empresa 1 y en
        // Empresa 2, con sucursales distintas en cada una.
        $primera = Company::factory()->create();
        $segunda = Company::factory()->create();

        $norte = Branch::factory()->create(['company_id' => $primera->id]);
        $sur = Branch::factory()->create(['company_id' => $primera->id]);
        $centro = Branch::factory()->create(['company_id' => $segunda->id]);

        $usuario = User::factory()->create();
        $usuario->branches()->attach([$norte->id, $sur->id, $centro->id]);

        $empresas = $usuario->branches->pluck('company_id')->unique();

        $this->assertCount(2, $empresas);
    }
}
