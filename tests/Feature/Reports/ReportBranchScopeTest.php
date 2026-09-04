<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\User;
use App\Reports\GrowthReport;
use App\Support\BranchFilter;
use App\Reports\Support\ReportPeriod;
use App\Tenancy\CurrentContext;
use Database\Seeders\ManagementReportsPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Qué sucursales entran en un informe gerencial.
 *
 * DE DÓNDE VIENE ESTO
 * -------------------
 * El módulo nació con una sucursal por informe: se trabajaba en una
 * sede y se informaba de esa. La sucursal salía SIEMPRE de la sesión y
 * nunca de la petición, a propósito, para que nadie pidiera el informe
 * de una sede ajena cambiando la URL.
 *
 * El panel consolidado cambia la pregunta. Ahí no hay una sucursal
 * activa —el informe salía vacío y con el título en blanco— y lo que
 * se quiere es poder ver una sede, sumar varias, o verlas todas menos
 * alguna.
 *
 * LA LÍNEA QUE NO SE CRUZA
 * ------------------------
 * La selección ahora sí viene de la petición, pero se cruza con el
 * alcance del usuario antes de usarla. La garantía de antes se
 * mantiene y es lo que más se prueba aquí: **solo se informa de sedes
 * a las que el usuario llega**.
 */
class ReportBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);
        $this->seed(ManagementReportsPermissionSeeder::class);

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

    /** Un contrato activo en la sucursal, dentro del período de prueba. */
    private function contrato(Branch $sucursal): Contract
    {
        $autor = User::factory()->create();

        $plan = Plan::create([
            'name' => 'Plan ' . $sucursal->id,
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        $cliente = Client::factory()->create([
            'company_id' => $sucursal->company_id,
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'status' => 'Activo',
            'activation_date' => '2026-03-10',
            'user_id' => $autor->id,
        ]);
    }

    private function periodo(): ReportPeriod
    {
        return ReportPeriod::fromRequest('2026-01-01', '2026-06-30', 'month');
    }

    private function altas(array $branchIds): int
    {
        // series() devuelve un array con claves labels/altas/bajas/…,
        // no una lista de filas: hay que entrar por la clave.
        $serie = (new GrowthReport($this->periodo(), $branchIds))->series();

        return (int) collect($serie['altas'])->sum();
    }

    // ==================== El normalizador ====================

    public function test_admite_una_sucursal_suelta_varias_o_ninguna(): void
    {
        // El entero suelto se admite a propósito: es como llamaban al
        // módulo las decenas de sitios y pruebas que ya existían.
        $this->assertSame([7], BranchFilter::normalizar(7));
        $this->assertSame([7, 9], BranchFilter::normalizar([7, 9]));
        $this->assertSame([], BranchFilter::normalizar(null));
    }

    public function test_limpia_repetidas_vacias_y_basura(): void
    {
        // Los ids llegan de la URL como texto y pueden venir repetidos
        // o vacíos. Sin limpiarlos, un `whereIn` con un cero dentro
        // ensucia la consulta sin avisar.
        $this->assertSame([3, 5], BranchFilter::normalizar(['3', '5', '3', '', '0', 'abc']));
    }

    // ==================== Modo independiente ====================

    public function test_en_modo_independiente_manda_la_sucursal_activa(): void
    {
        $empresa = Company::factory()->create();
        $activa = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contrato($activa);
        $this->contrato($otra);

        $this->entrarEn($empresa, [$activa, $otra]);

        // Aunque la URL pida las dos, en modo independiente se ignora:
        // el usuario ya eligió sucursal al entrar.
        $this->get(route('reports.growth', [
            'desde' => '2026-01-01',
            'hasta' => '2026-06-30',
            'sucursales' => [$activa->id, $otra->id],
        ]))->assertOk()->assertSee($activa->name, escape: false);

        $this->assertSame(1, $this->altas([$activa->id]));
    }

    public function test_en_modo_independiente_no_se_ofrece_elegir(): void
    {
        $empresa = Company::factory()->create();
        $activa = Branch::factory()->create(['company_id' => $empresa->id]);
        Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$activa]);

        $this->get(route('reports.growth'))
            ->assertOk()
            ->assertDontSee('name="sucursales[]"', escape: false);
    }

    // ==================== Panel consolidado ====================

    public function test_sin_elegir_nada_se_suman_todas_las_suyas(): void
    {
        // Es el valor por defecto al entrar, y también lo que pasa si
        // alguien vacía las casillas: marcar todas y no marcar ninguna
        // dan el mismo informe.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contrato($norte);
        $this->contrato($sur);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $this->assertSame(2, $this->altas([$norte->id, $sur->id]));

        $this->get(route('reports.growth'))
            ->assertOk()
            ->assertSee('Todas las sucursales (2)', escape: false);
    }

    public function test_se_puede_informar_de_una_sola(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contrato($norte);
        $this->contrato($sur);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $this->assertSame(1, $this->altas([$norte->id]));

        // Con una sola, la cabecera dice su nombre.
        $this->get(route('reports.growth', ['sucursales' => [$norte->id]]))
            ->assertOk()
            ->assertSee($norte->name, escape: false);
    }

    public function test_se_pueden_sumar_varias_y_dejar_una_fuera(): void
    {
        // Este es el caso que pidió el usuario: elegir varias y
        // excluir alguna.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $centro = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contrato($norte);
        $this->contrato($centro);
        $this->contrato($sur);

        $this->entrarEn($empresa, [$norte, $centro, $sur], consolidado: true);

        // Dos de tres: el sur queda fuera.
        $this->assertSame(2, $this->altas([$norte->id, $centro->id]));
        $this->assertSame(3, $this->altas([$norte->id, $centro->id, $sur->id]));
    }

    // ==================== La línea que no se cruza ====================

    public function test_una_sucursal_fuera_del_alcance_se_descarta(): void
    {
        // De la misma empresa, pero sin acceso concedido. Pedirla por
        // la URL no puede colar sus datos en el informe.
        $empresa = Company::factory()->consolidada()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        $otraSuya = Branch::factory()->create(['company_id' => $empresa->id]);
        $sinAcceso = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contrato($suya);
        $this->contrato($sinAcceso);

        $this->entrarEn($empresa, [$suya, $otraSuya], consolidado: true);

        $respuesta = $this->get(route('reports.growth', [
            'desde' => '2026-01-01',
            'hasta' => '2026-06-30',
            'sucursales' => [$sinAcceso->id],
        ]))->assertOk();

        // Pedir SOLO una ajena deja la selección vacía, y vacía
        // significa "todas las suyas": las dos alcanzables.
        $respuesta->assertSee('Todas las sucursales (2)', escape: false);

        // Y el contrato de la sede sin acceso no entra en el total.
        $this->assertSame(1, $this->altas([$suya->id, $otraSuya->id]));
    }

    public function test_lo_ajeno_se_cae_y_lo_propio_se_queda(): void
    {
        // Mezclar una suya con una ajena en la URL no debe rechazar la
        // petición entera ni aceptar la ajena: se queda con la suya.
        $empresa = Company::factory()->consolidada()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        $otraSuya = Branch::factory()->create(['company_id' => $empresa->id]);
        $sinAcceso = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$suya, $otraSuya], consolidado: true);

        $this->get(route('reports.growth', [
            'sucursales' => [$suya->id, $sinAcceso->id],
        ]))
            ->assertOk()
            // Una sola sobrevive, así que la cabecera es su nombre.
            ->assertSee($suya->name, escape: false);
    }

    public function test_una_sucursal_de_otra_empresa_se_descarta(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $ajena = Branch::factory()->create();
        $this->contrato($ajena);

        $this->contrato($una);

        $this->entrarEn($empresa, [$una, $otra], consolidado: true);

        $this->get(route('reports.growth', ['sucursales' => [$ajena->id]]))
            ->assertOk()
            ->assertSee('Todas las sucursales (2)', escape: false);

        $this->assertSame(1, $this->altas([$una->id, $otra->id]));
    }

    // ==================== La pantalla ====================

    public function test_el_selector_aparece_en_consolidado_con_varias(): void
    {
        $empresa = Company::factory()->consolidada()->create();

        // Nombres explicitos y sin apostrofos a proposito: el factory
        // los saca de fake()->city(), que a veces devuelve cosas como
        // «O'Konborough». El HTML lo escapa a &#039; y la busqueda
        // literal de abajo falla — un fallo intermitente que solo
        // aparece cuando Faker acierta con uno de esos.
        $norte = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Norte']);
        $sur = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Sur']);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $this->get(route('reports.growth'))
            ->assertOk()
            // El campo real del formulario: si no esta, no hay nada
            // que enviar. No depende del texto de los botones.
            ->assertSee('name="sucursales[]"', escape: false)
            ->assertSee($norte->name, escape: false)
            ->assertSee($sur->name, escape: false);
    }

    public function test_con_una_sola_alcanzable_no_hay_nada_que_elegir(): void
    {
        // Consolidado, pero el usuario solo llega a una sede: un
        // selector de un elemento es ruido.
        $empresa = Company::factory()->consolidada()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$suya], consolidado: true);

        $this->get(route('reports.growth'))
            ->assertOk()
            ->assertDontSee('name="sucursales[]"', escape: false);
    }
}
