<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El selector de sucursal en los formularios de alta.
 *
 * QUÉ DEFIENDE
 * ------------
 * `branchParaEscritura()` exige la sucursal en panel consolidado. Si un
 * formulario no la pide, esa pantalla queda **inservible**: el usuario
 * rellena todo, envía, y se lleva un error de un campo que no existe.
 *
 * Al revés es igual de malo: pintar el selector cuando solo hay una
 * sucursal alcanzable es un campo de más en cada alta, y el usuario
 * pidió expresamente que en ese caso se asuma.
 *
 * Las dos mitades tienen que decidir con el MISMO criterio —
 * `hayQueElegirSucursal()`—, y eso es lo que se comprueba aquí,
 * pantalla por pantalla. Sin esto, añadir un formulario nuevo y
 * olvidarse del selector no lo nota nadie hasta que un cliente trabaja
 * en consolidado.
 *
 * Se renderiza de verdad (no se inspecciona el Blade) porque los dos
 * fallos que ha habido en este proyecto con vistas —`@php(...)` en
 * línea y comillas escapadas dentro de `route()`— compilan sin
 * quejarse y solo revientan al renderizar.
 */
class BranchSelectorTest extends TestCase
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

    /**
     * Las pantallas de alta que guardan en una sucursal.
     *
     * @return array<string, array{0: string}>
     */
    public static function formularios(): array
    {
        return [
            'plan' => ['plans.create'],
            'servicio' => ['services.create'],
            'material' => ['materials.create'],
            'categoría' => ['categories.create'],
            'almacén' => ['warehouses.create'],
            'red óptica' => ['networks.create'],
            'OLT' => ['olts.create'],
            'router' => ['routers.create'],
        ];
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

    /**
     * @dataProvider formularios
     */
    public function test_en_consolidado_el_formulario_pide_la_sucursal(string $ruta): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Norte']);
        $sur = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Sur']);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $respuesta = $this->get(route($ruta))->assertOk();

        // El campo, con las dos sucursales entre las que elegir.
        $respuesta->assertSee('name="branch_id"', escape: false);
        $respuesta->assertSee('Sede Norte', escape: false);
        $respuesta->assertSee('Sede Sur', escape: false);
    }

    /**
     * @dataProvider formularios
     */
    public function test_con_una_sola_sucursal_no_se_pregunta(string $ruta): void
    {
        // Es lo que pidió el usuario: sin alternativa no se pregunta.
        $empresa = Company::factory()->create();
        $unica = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Única']);

        $this->entrarEn($empresa, [$unica]);

        $this->get(route($ruta))
            ->assertOk()
            ->assertDontSee('name="branch_id"', escape: false);
    }

    /**
     * @dataProvider formularios
     */
    public function test_en_consolidado_con_acceso_a_una_sola_tampoco(string $ruta): void
    {
        // Mismo criterio, otra causa: la empresa trabaja consolidada
        // pero este usuario solo alcanza una sede.
        $empresa = Company::factory()->consolidada()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        Branch::factory()->create(['company_id' => $empresa->id]); // sin acceso

        $this->entrarEn($empresa, [$suya], consolidado: true);

        $this->get(route($ruta))
            ->assertOk()
            ->assertDontSee('name="branch_id"', escape: false);
    }

    // ==================== La sucursal ajena no se ofrece ====================

    public function test_no_se_ofrece_una_sucursal_de_otra_empresa(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Propia']);
        $otra = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Propia Dos']);

        Branch::factory()->create(['name' => 'Sede De Otra Empresa']);

        $this->entrarEn($empresa, [$una, $otra], consolidado: true);

        $this->get(route('plans.create'))
            ->assertOk()
            ->assertSee('Sede Propia', escape: false)
            ->assertDontSee('Sede De Otra Empresa', escape: false);
    }

    public function test_no_se_ofrece_una_sucursal_sin_acceso_concedido(): void
    {
        // De la misma empresa, pero el usuario no la tiene asignada.
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Con Acceso']);
        $otra = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Con Acceso Dos']);

        Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Vetada']);

        $this->entrarEn($empresa, [$una, $otra], consolidado: true);

        $this->get(route('plans.create'))
            ->assertOk()
            ->assertSee('Sede Con Acceso', escape: false)
            ->assertDontSee('Sede Vetada', escape: false);
    }
}
