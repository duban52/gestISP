<?php

namespace Tests\Feature\Billing;

use App\Billing\Services\MonthlyBillingRun;
use App\Models\Branch;
use App\Models\BillingRun;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reporte detallado de una corrida de facturación.
 *
 * El listado de corridas dice CUÁNTO se facturó; el detalle dice QUÉ
 * se facturó y a quién, y debe poder descargarse como soporte.
 */
class BillingRunReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $usuario;
    private Plan $plan;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->usuario = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $this->usuario->assignRole($this->rol);
        $this->usuario->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        $this->plan = Plan::create([
            'name' => 'Internet 100 Megas',
            'user_id' => $this->usuario->id,
            'branch_id' => $this->branch->id,
        ]);

        // El plan necesita un servicio para que la factura tenga ítems
        $servicio = Service::create([
            'name' => 'Internet 100 Megas',
            'base_price' => 80000,
            'tax_percentage' => 0,
            'branch_id' => $this->branch->id,
            'user_id' => $this->usuario->id,
        ]);

        $this->plan->services()->attach($servicio->id);

        $this->actingAs($this->usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    private function contrato(string $documento = '1111111'): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->usuario->id,
            'identity_number' => $documento,
            'name' => 'Ana',
            'last_name' => 'Restrepo',
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'user_id' => $this->usuario->id,
            'activation_date' => now()->subMonths(3),
        ]);
    }

    // ============ Enlace entre facturas y corrida ============

    public function test_las_facturas_quedan_ligadas_a_la_corrida_que_las_genero(): void
    {
        $this->contrato('1111111');
        $this->contrato('2222222');

        $resultado = app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);

        $this->assertNotNull($resultado['billing_run_id'], 'La corrida debe devolver su identificador.');

        $run = BillingRun::find($resultado['billing_run_id']);

        $this->assertSame(2, $run->invoices()->count());
        $this->assertSame($resultado['generated'], $run->generated_count);

        // Toda factura generada apunta a su corrida
        foreach (Invoice::all() as $factura) {
            $this->assertSame($run->id, $factura->billing_run_id);
        }
    }

    public function test_los_totales_de_la_corrida_coinciden_con_lo_facturado(): void
    {
        $this->contrato('3333333');

        $resultado = app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);
        $run = BillingRun::find($resultado['billing_run_id']);

        $this->assertEquals(Invoice::sum('total'), (float) $run->total_billed);
        $this->assertEquals(Invoice::sum('subtotal'), (float) $run->total_subtotal);
    }

    // ==================== Pantalla de detalle ====================

    public function test_el_detalle_muestra_los_datos_del_cliente_y_del_contrato(): void
    {
        $contrato = $this->contrato('4444444');

        $resultado = app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);

        $respuesta = $this->get(route('billing_runs.show', $resultado['billing_run_id']));

        $respuesta->assertOk();
        // Identificación, nombre y número de contrato (no el id)
        $respuesta->assertSee('4444444');
        $respuesta->assertSee('Ana');
        $respuesta->assertSee($contrato->fresh()->contract_number);
        // Y el desglose de lo facturado
        $respuesta->assertSee('Internet 100 Megas');
    }

    public function test_tras_generar_se_llega_al_detalle_para_descargar_el_reporte(): void
    {
        $this->contrato('5555555');

        $respuesta = $this->post(route('invoices.generate'));

        $run = BillingRun::latest('id')->first();

        $respuesta->assertRedirect(route('billing_runs.show', $run->id));
        $respuesta->assertSessionHas('success');
    }

    public function test_una_corrida_de_otra_sucursal_no_se_consulta(): void
    {
        $otra = Branch::factory()->create();

        $run = BillingRun::create([
            'branch_id' => $otra->id,
            'user_id' => $this->usuario->id,
            'billed_year_month' => now()->format('Ym'),
            'contracts_count' => 0,
            'generated_count' => 0,
            'skipped_count' => 0,
            'total_subtotal' => 0,
            'total_tax' => 0,
            'total_billed' => 0,
            'executed_at' => now(),
        ]);

        $this->get(route('billing_runs.show', $run))->assertForbidden();
    }

    // ==================== Descargas ====================

    public function test_el_reporte_se_descarga_en_excel(): void
    {
        $this->contrato('6666666');
        $resultado = app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);

        $respuesta = $this->get(route('billing_runs.excel', $resultado['billing_run_id']));

        $respuesta->assertOk();
        $respuesta->assertDownload();
    }

    public function test_el_reporte_se_descarga_en_csv(): void
    {
        $this->contrato('7777777');
        $resultado = app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);

        $respuesta = $this->get(route('billing_runs.csv', $resultado['billing_run_id']));

        $respuesta->assertOk();
        $respuesta->assertDownload();
    }

    public function test_el_reporte_se_descarga_en_pdf(): void
    {
        $this->contrato('8888888');
        $resultado = app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);

        $respuesta = $this->get(route('billing_runs.pdf', $resultado['billing_run_id']));

        $respuesta->assertOk();
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    // ============ Corridas anteriores al enlace ============

    public function test_una_corrida_antigua_recupera_sus_facturas_por_periodo(): void
    {
        $this->contrato('9999999');
        app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);

        // Se simula el historial previo: la corrida existe pero las
        // facturas no la referencian.
        Invoice::query()->update(['billing_run_id' => null]);
        $run = BillingRun::latest('id')->first();

        $this->assertTrue($run->detalle_deducido);
        $this->assertSame(1, $run->facturasDelReporte()->count());

        $this->get(route('billing_runs.show', $run))
            ->assertOk()
            ->assertSee('anterior al registro del detalle');
    }

    // ============ Índice de facturas ============

    public function test_el_listado_de_facturas_muestra_contrato_e_identificacion(): void
    {
        $contrato = $this->contrato('1234567');
        app(MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->usuario->id);

        $respuesta = $this->get(route('invoices.index'));

        $respuesta->assertOk();
        $respuesta->assertSee('N.º contrato');
        $respuesta->assertSee('Identificación');
        $respuesta->assertSee($contrato->fresh()->contract_number);
        $respuesta->assertSee('1234567');
    }
}
