<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * En qué sucursal queda un contrato.
 *
 * LA DIVISIÓN
 * -----------
 * El CLIENTE es de la empresa; el CONTRATO es de una sucursal. Ahí es
 * donde se presta el servicio, y de ahí salen su prefijo, su
 * consecutivo y sus reglas de facturación.
 *
 * CUÁNDO SE PREGUNTA
 * ------------------
 * Solo cuando hay más de una sucursal alcanzable. Con una sola se
 * asume —da igual si es porque la empresa tiene una sede o porque el
 * usuario solo tiene acceso a una—, y el formulario no muestra el
 * campo. Preguntar sin alternativa es un campo de más en cada alta.
 */
class ContractBranchTest extends TestCase
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

    /** @param array<int, Branch> $sucursales */
    private function entrarEn(Company $empresa, array $sucursales, bool $consolidado = false): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);

        foreach ($sucursales as $s) {
            $usuario->branches()->attach($s->id, ['role_id' => $this->rol->id]);
        }

        $ids = collect($sucursales)->pluck('id')->all();

        $this->actingAs($usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => $consolidado ? null : (string) $sucursales[0]->id,
            'branch_ids' => $ids,
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer(
            $empresa->id,
            $ids,
            $consolidado ? null : $sucursales[0]->id,
        );

        return $usuario;
    }

    private function plan(Branch $sucursal, string $nombre = 'Plan 100M'): Plan
    {
        return Plan::create([
            'name' => $nombre,
            'branch_id' => $sucursal->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function cliente(Company $empresa): Client
    {
        return Client::factory()->create([
            'company_id' => $empresa->id,
            'branch_id' => null,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function datos(Client $cliente, array $extra = []): array
    {
        return array_merge([
            'client_id' => $cliente->id,
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'neighborhood' => 'Centro',
            'address' => 'Calle 20 # 19-30',
            'home_type' => 'Casa',
            'status' => 'Pendiente',
        ], $extra);
    }

    // ==================== Con una sola sucursal se asume ====================

    public function test_con_una_sola_sucursal_no_se_pregunta_y_se_asume(): void
    {
        // Es lo que pidió el usuario: si la empresa tiene una sola
        // sede, el contrato va ahí sin preguntar.
        $empresa = Company::factory()->create();
        $unica = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$unica]);

        $this->assertFalse(app(CurrentContext::class)->hayQueElegirSucursal());

        $cliente = $this->cliente($empresa);

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'plan_id' => $this->plan($unica)->id,
        ]))->assertSessionHasNoErrors();

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertSame($unica->id, $contrato->branch_id);
    }

    public function test_en_consolidado_con_acceso_a_una_sola_sede_tambien_se_asume(): void
    {
        // Mismo criterio, otra causa: la empresa trabaja consolidada
        // pero este usuario solo alcanza una sucursal. No hay nada que
        // elegir.
        $empresa = Company::factory()->consolidada()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        Branch::factory()->create(['company_id' => $empresa->id]); // sin acceso

        $this->entrarEn($empresa, [$suya], consolidado: true);

        $this->assertFalse(app(CurrentContext::class)->hayQueElegirSucursal());
        $this->assertSame($suya->id, app(CurrentContext::class)->branchParaEscritura());
    }

    // ==================== Con varias sí se pregunta ====================

    public function test_con_varias_sucursales_el_formulario_pide_la_sucursal(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Norte']);
        $sur = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sur']);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $this->assertTrue(app(CurrentContext::class)->hayQueElegirSucursal());

        $this->get(route('contracts.create', $this->cliente($empresa)))
            ->assertOk()
            ->assertSee('Sucursal del servicio')
            ->assertSee('name="branch_id"', false)
            ->assertSee('Norte')
            ->assertSee('Sur');
    }

    public function test_el_contrato_queda_en_la_sucursal_elegida(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id, 'contract_prefix' => 'NOR']);
        $sur = Branch::factory()->create(['company_id' => $empresa->id, 'contract_prefix' => 'SUR']);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $cliente = $this->cliente($empresa);

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'branch_id' => $sur->id,
            'plan_id' => $this->plan($sur)->id,
        ]))->assertSessionHasNoErrors();

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertSame($sur->id, $contrato->branch_id);
        // El consecutivo sale del prefijo de ESA sucursal
        $this->assertStringStartsWith('SUR', $contrato->contract_number);
    }

    // ==================== Lo que no se puede colar ====================

    public function test_no_se_puede_registrar_en_una_sucursal_ajena(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);
        $sinAcceso = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$una, $otra], consolidado: true);

        $cliente = $this->cliente($empresa);
        $antes = Contract::count();

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'branch_id' => $sinAcceso->id,
            'plan_id' => $this->plan($una)->id,
        ]));

        $this->assertSame($antes, Contract::count());
    }

    public function test_el_plan_tiene_que_ser_de_la_sucursal_del_contrato(): void
    {
        // Sin esto se le asignaría a un contrato de Norte un plan de
        // Sur, y el precio saldría del sitio equivocado. La pantalla ya
        // esconde los que no son, pero eso es ayuda visual.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $this->post(route('contracts.store'), $this->datos($this->cliente($empresa), [
            'branch_id' => $norte->id,
            'plan_id' => $this->plan($sur, 'Plan del Sur')->id,
        ]))->assertSessionHasErrors('plan_id');

        $this->assertSame(0, Contract::count());
    }

    // ==================== La pantalla en consolidado ====================

    public function test_en_consolidado_el_formulario_trae_planes_de_todas_sus_sedes(): void
    {
        // Con el filtro anterior por session('branch_id') —nulo en
        // consolidado— el desplegable de planes salía vacío y no se
        // podía crear ni un contrato.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $this->plan($norte, 'Plan del Norte');
        $this->plan($sur, 'Plan del Sur');

        $this->get(route('contracts.create', $this->cliente($empresa)))
            ->assertOk()
            ->assertSee('Plan del Norte')
            ->assertSee('Plan del Sur');
    }

    public function test_el_formulario_ofrece_clientes_de_toda_la_empresa(): void
    {
        // El cliente es de la empresa: uno dado de alta en Norte tiene
        // que poder contratar desde Sur.
        $empresa = Company::factory()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $deNorte = Client::factory()->create([
            'company_id' => $empresa->id,
            'branch_id' => $norte->id,
            'name' => 'DadoDeAltaEnNorte',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->entrarEn($empresa, [$sur]);

        $this->get(route('contracts.create', $deNorte))->assertOk();
    }
}
