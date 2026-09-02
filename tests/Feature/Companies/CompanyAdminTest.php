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
 * Administración de empresas y su enlace con las sucursales.
 *
 * POR QUÉ EXISTE ESTA PANTALLA
 * ----------------------------
 * Las fases anteriores crearon la entidad Empresa, la migración y el
 * comando de reparación, pero ninguna pantalla. El resultado era que
 * la empresa existía en la base y no había forma de crear una, de
 * cambiarle la razón social ni —lo más importante— de ponerla en panel
 * consolidado. El modo consolidado no se podía ni probar.
 *
 * Lo que se defiende aquí:
 *
 *  1. Que se pueda crear una empresa y darle sucursales.
 *  2. Que dos empresas no compartan identificación fiscal.
 *  3. Que el NIT salga de la empresa y no se escriba en la sucursal.
 *  4. Que no se pueda poner en consolidado una empresa con una sola
 *     sede, porque el "panel de todas las sucursales" mostraría una.
 *  5. Que haga falta permiso para todo esto.
 */
class CompanyAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $sucursal = Branch::factory()->create();

        $this->admin = User::factory()->create();
        $this->admin->assignRole($this->rol);
        $this->admin->branches()->attach($sucursal->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->admin)->withSession([
            'company_id' => $sucursal->company_id,
            'branch_id' => (string) $sucursal->id,
            'branch_ids' => [$sucursal->id],
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    private function datos(array $extra = []): array
    {
        return array_merge([
            'legal_name' => 'Fibra Andina S.A.S.',
            'document_type_code' => '31',
            'document_number' => '901234567',
            'verification_digit' => '8',
            'operation_mode' => Company::MODO_INDEPENDIENTE,
            'active' => '1',
        ], $extra);
    }

    // ==================== Alta ====================

    public function test_se_puede_crear_una_empresa(): void
    {
        $this->post(route('companies.store'), $this->datos())->assertRedirect();

        $empresa = Company::where('document_number', '901234567')->firstOrFail();

        $this->assertSame('Fibra Andina S.A.S.', $empresa->legal_name);
        $this->assertSame('901234567-8', $empresa->identificacion());
        $this->assertFalse($empresa->esConsolidada());
        // Nace sin facturación electrónica: activarla exige certificado,
        // resolución y habilitación, que no se piden aquí.
        $this->assertFalse($empresa->electronic_invoicing_enabled);
    }

    public function test_dos_empresas_no_pueden_compartir_identificacion(): void
    {
        // Serían el mismo contribuyente por duplicado.
        Company::factory()->create(['document_number' => '901234567', 'document_type_code' => '31']);

        $this->post(route('companies.store'), $this->datos())
            ->assertSessionHasErrors('document_number');
    }

    public function test_la_razon_social_es_obligatoria(): void
    {
        $this->post(route('companies.store'), $this->datos(['legal_name' => '']))
            ->assertSessionHasErrors('legal_name');
    }

    // ==================== Modalidad ====================

    public function test_no_se_puede_consolidar_una_empresa_con_una_sola_sucursal(): void
    {
        // El "panel de todas las sedes" mostraría una: no cambia nada y
        // confunde a quien lo activa esperando otra cosa.
        $empresa = Company::factory()->create();
        Branch::factory()->create(['company_id' => $empresa->id]);

        $this->put(route('companies.update', $empresa), $this->datos([
            'document_number' => $empresa->document_number,
            'operation_mode' => Company::MODO_CONSOLIDADO,
        ]))->assertSessionHasErrors('operation_mode');

        $this->assertFalse($empresa->fresh()->esConsolidada());
    }

    public function test_con_dos_sucursales_si_se_puede_consolidar(): void
    {
        $empresa = Company::factory()->create();
        Branch::factory()->count(2)->create(['company_id' => $empresa->id]);

        $this->put(route('companies.update', $empresa), $this->datos([
            'document_number' => $empresa->document_number,
            'operation_mode' => Company::MODO_CONSOLIDADO,
        ]))->assertSessionHasNoErrors();

        $this->assertTrue($empresa->fresh()->esConsolidada());
    }

    // ==================== La sucursal cuelga de la empresa ====================

    public function test_la_sucursal_se_crea_eligiendo_empresa_y_hereda_su_nit(): void
    {
        // El NIT ya no se escribe en la sucursal: se heredaba mal
        // cuando dos sedes del mismo contribuyente lo tenían distinto.
        $empresa = Company::factory()->create(['document_number' => '900777666']);

        $this->post(route('branches.store'), [
            'company_id' => $empresa->id,
            'name' => 'Sucursal Norte',
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'address' => 'Calle 20 # 19-30',
            'number_phone' => '3001112233',
        ])->assertRedirect();

        // Sin la barrera: la sucursal recien creada es de OTRA empresa
        // que la del contexto activo, asi que el scope la esconde. Que
        // la esconda es lo correcto — se comprueba en
        // CompanyIsolationTest— y aqui solo interesa el dato guardado.
        $sucursal = Branch::withoutGlobalScope('empresa')
            ->where('name', 'Sucursal Norte')->firstOrFail();

        $this->assertSame($empresa->id, $sucursal->company_id);
        $this->assertSame('900777666', $sucursal->nit);
    }

    public function test_dos_empresas_pueden_tener_una_sucursal_con_el_mismo_nombre(): void
    {
        // La validación del formulario tenía todavía un unique GLOBAL
        // sobre el nombre, heredado de cuando no existían las empresas.
        // Rechazaba nombres perfectamente válidos.
        $primera = Company::factory()->create();
        $segunda = Company::factory()->create();

        Branch::factory()->create(['company_id' => $primera->id, 'name' => 'Principal']);

        $this->post(route('branches.store'), [
            'company_id' => $segunda->id,
            'name' => 'Principal',
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'address' => 'Calle 20 # 19-30',
            'number_phone' => '3001112233',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Branch::withoutGlobalScope('empresa')->where('name', 'Principal')->count());
    }

    public function test_una_empresa_no_puede_repetir_nombre_de_sucursal(): void
    {
        $empresa = Company::factory()->create();
        Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Centro']);

        $this->post(route('branches.store'), [
            'company_id' => $empresa->id,
            'name' => 'Centro',
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'address' => 'Calle 20 # 19-30',
            'number_phone' => '3001112233',
        ])->assertSessionHasErrors('name');
    }

    // ==================== Acceso a la sucursal nueva ====================

    public function test_quien_crea_una_sucursal_se_queda_con_acceso(): void
    {
        // El acceso se concede POR SUCURSAL. Sin esto, la sucursal
        // recien creada no tiene a nadie: no sale en los listados,
        // nadie puede elegirla como contexto y no se puede ni editar.
        $empresa = Company::factory()->create();

        $this->post(route('branches.store'), [
            'company_id' => $empresa->id,
            'name' => 'Sede Nueva',
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'address' => 'Calle 20 # 19-30',
            'number_phone' => '3001112233',
            'darme_acceso' => '1',
        ])->assertRedirect(route('companies.show', $empresa->id));

        $sucursal = Branch::withoutGlobalScope('empresa')
            ->where('name', 'Sede Nueva')->firstOrFail();

        $this->assertTrue(
            $this->admin->branches()->withoutGlobalScope('empresa')
                ->where('branches.id', $sucursal->id)->exists(),
        );
    }

    public function test_se_puede_crear_una_sucursal_sin_darse_acceso(): void
    {
        // Conceder acceso es conceder acceso: la casilla llega marcada
        // pero se puede quitar.
        $empresa = Company::factory()->create();

        $this->post(route('branches.store'), [
            'company_id' => $empresa->id,
            'name' => 'Sede Ajena',
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'address' => 'Calle 20 # 19-30',
            'number_phone' => '3001112233',
            'darme_acceso' => '0',
        ]);

        $sucursal = Branch::withoutGlobalScope('empresa')
            ->where('name', 'Sede Ajena')->firstOrFail();

        $this->assertSame(0, $sucursal->users()->count());
    }

    public function test_la_ficha_avisa_de_las_sucursales_sin_usuarios(): void
    {
        // Es lo unico que delata que una sucursal quedo inservible.
        $empresa = Company::factory()->create();
        Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Huerfana']);

        $this->get(route('companies.show', $empresa))
            ->assertOk()
            ->assertSee('sin', false)
            ->assertSee('Nadie');
    }

    public function test_el_contador_de_sucursales_no_lo_esconde_el_aislamiento(): void
    {
        // La relacion branches() lleva el global scope de empresa: el
        // contador salia en CERO para las demas empresas mientras la
        // tabla de al lado si listaba sus sucursales.
        $empresa = Company::factory()->create(['legal_name' => 'Otra Empresa S.A.S.']);
        Branch::factory()->count(2)->create(['company_id' => $empresa->id]);

        $this->get(route('companies.show', $empresa))
            ->assertOk()
            ->assertSee('>2<', false);
    }

    // ==================== Las pantallas ====================

    public function test_el_listado_muestra_las_empresas_y_avisa_de_las_vacias(): void
    {
        // Una empresa sin sucursales no puede operar: nadie puede
        // entrar a ella, porque el acceso se concede por sucursal.
        $sinSedes = Company::factory()->create(['legal_name' => 'Recién Creada S.A.S.']);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertSee('Recién Creada S.A.S.')
            ->assertSee('Sin sucursales');
    }

    public function test_la_ficha_lista_las_sucursales_de_la_empresa(): void
    {
        $empresa = Company::factory()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Yarumal']);
        $ajena = Branch::factory()->create(['name' => 'Sede Ajena']);

        $this->get(route('companies.show', $empresa))
            ->assertOk()
            ->assertSee('Sede Yarumal')
            ->assertDontSee('Sede Ajena');
    }

    public function test_el_formulario_de_sucursal_ya_no_pide_nit(): void
    {
        $this->get(route('branches.create'))
            ->assertOk()
            ->assertSee('name="company_id"', false)
            ->assertDontSee('name="nit"', false);
    }

    // ==================== Permisos ====================

    public function test_sin_permiso_no_se_administran_empresas(): void
    {
        $this->rol->revokePermissionTo('companies.index');
        $this->rol->revokePermissionTo('companies.create');

        $this->get(route('companies.index'))->assertStatus(403);
        $this->get(route('companies.create'))->assertStatus(403);
    }
}
