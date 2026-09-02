<?php

namespace Tests\Feature\Contracts;

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
 * Un contrato no puede existir sin plan.
 *
 * EL AGUJERO QUE CIERRA
 * ---------------------
 * El plan era opcional: `if ($request->filled('plan_id'))`. Si no
 * venía, el contrato se daba de alta igual, sin ruido y sin plan.
 *
 * El problema no se ve al crearlo, se ve un mes después. Del plan
 * salen el precio, los servicios que se facturan y el IVA de cada
 * uno; sin él la corrida mensual no tiene nada que cobrar y el
 * contrato no genera factura. El cliente queda con servicio y sin
 * cobro, y eso solo se nota al cuadrar el mes.
 *
 * Lo que se defiende aquí:
 *
 *  1. Que sin plan no se cree el contrato.
 *  2. Que el plan siga teniendo que ser de la sucursal del contrato
 *     (la comprobación que ya existía, para que no se pierda al
 *     cambiar la forma de validar).
 *  3. Que el mensaje diga qué falta, no "el campo plan id es
 *     obligatorio".
 *  4. Que cuando no hay ningún plan dado de alta, la pantalla lo
 *     diga y ofrezca crearlos en vez de mostrar un desplegable
 *     vacío.
 */
class ContractPlanRequiredTest extends TestCase
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

    /** @param  array<int, Branch>  $sucursales */
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

    /** @param  array<string, mixed>  $extra */
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

    // ==================== Sin plan no hay contrato ====================

    public function test_sin_plan_no_se_crea_el_contrato(): void
    {
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sucursal]);

        $cliente = $this->cliente($empresa);

        $this->post(route('contracts.store'), $this->datos($cliente))
            ->assertSessionHasErrors('plan_id');

        $this->assertSame(0, Contract::where('client_id', $cliente->id)->count());
    }

    public function test_el_plan_vacio_tampoco_vale(): void
    {
        // El desplegable manda cadena vacía cuando está en
        // "Seleccionar plan". Antes `filled()` la dejaba pasar de
        // largo y el contrato nacía con plan_id nulo.
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sucursal]);

        $cliente = $this->cliente($empresa);

        $this->post(route('contracts.store'), $this->datos($cliente, ['plan_id' => '']))
            ->assertSessionHasErrors('plan_id');

        $this->assertSame(0, Contract::where('client_id', $cliente->id)->count());
    }

    public function test_el_mensaje_explica_que_falta(): void
    {
        // "El campo plan id es obligatorio" no le dice a nadie por qué
        // importa. El mensaje tiene que explicar la consecuencia.
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sucursal]);

        $respuesta = $this->post(route('contracts.store'), $this->datos($this->cliente($empresa)));

        $errores = session('errors')->get('plan_id');

        $this->assertStringContainsString('plan de servicio', $errores[0]);
        $this->assertStringContainsString('facturar', $errores[0]);

        $respuesta->assertRedirect();
    }

    public function test_un_plan_que_no_existe_se_rechaza(): void
    {
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sucursal]);

        $cliente = $this->cliente($empresa);

        $this->post(route('contracts.store'), $this->datos($cliente, ['plan_id' => 999999]))
            ->assertSessionHasErrors('plan_id');

        $this->assertSame(0, Contract::where('client_id', $cliente->id)->count());
    }

    // ==================== El plan sigue siendo de la sucursal ====================

    public function test_no_vale_un_plan_de_otra_sucursal(): void
    {
        // Esta comprobación ya existía y se hacía a mano. Al pasarla a
        // una regla de validación podría haberse perdido sin que nadie
        // se enterara: de ahí esta prueba.
        $empresa = Company::factory()->consolidada()->create();
        $bogota = Branch::factory()->create(['company_id' => $empresa->id]);
        $medellin = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$bogota, $medellin], consolidado: true);

        $cliente = $this->cliente($empresa);

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'branch_id' => $bogota->id,
            'plan_id' => $this->plan($medellin, 'Plan de Medellin')->id,
        ]))->assertSessionHasErrors('plan_id');

        $this->assertSame(0, Contract::where('client_id', $cliente->id)->count());
    }

    public function test_el_plan_de_la_sucursal_elegida_si_vale(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $bogota = Branch::factory()->create(['company_id' => $empresa->id]);
        $medellin = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$bogota, $medellin], consolidado: true);

        $cliente = $this->cliente($empresa);
        $plan = $this->plan($bogota, 'Plan de Bogota');

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'branch_id' => $bogota->id,
            'plan_id' => $plan->id,
        ]))->assertSessionHasNoErrors();

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertSame($plan->id, (int) $contrato->plan_id);
        $this->assertSame($bogota->id, (int) $contrato->branch_id);
    }

    // ==================== La pantalla cuando no hay planes ====================

    public function test_sin_planes_la_pantalla_sugiere_crearlos(): void
    {
        // Un desplegable vacío no explica nada. Si no hay ni un plan,
        // la pantalla dice qué falta y ofrece el camino para
        // arreglarlo.
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sucursal]);

        // El alta se entra desde la ficha del cliente: la ruta lleva
        // el cliente en la URL.
        $this->get(route('contracts.create', $this->cliente($empresa)))
            ->assertOk()
            ->assertSee('Todavia no hay planes', escape: false)
            ->assertSee(route('plans.create'), escape: false);
    }

    public function test_con_planes_la_pantalla_muestra_el_desplegable(): void
    {
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sucursal]);
        $this->plan($sucursal, 'Plan 300M');

        $respuesta = $this->get(route('contracts.create', $this->cliente($empresa)))->assertOk();

        $respuesta->assertSee('Plan 300M', escape: false);
        $respuesta->assertDontSee('Todavia no hay planes', escape: false);
    }

    public function test_los_planes_de_otra_empresa_no_cuentan(): void
    {
        // Si el aislamiento fallara, la pantalla mostraría el
        // desplegable con planes ajenos en vez del aviso — y el alta
        // fallaría después, sin explicación.
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $ajena = Branch::factory()->create();
        $this->plan($ajena, 'Plan de otra empresa');

        $this->entrarEn($empresa, [$sucursal]);

        $this->get(route('contracts.create', $this->cliente($empresa)))
            ->assertOk()
            ->assertSee('Todavia no hay planes', escape: false)
            ->assertDontSee('Plan de otra empresa', escape: false);
    }
}
