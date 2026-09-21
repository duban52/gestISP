<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\BillingMode;
use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Models\BillingRun;
use App\Models\Branch;
use App\Models\BranchBillingSetting;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * La corrida automática por sucursal y la mora diaria.
 *
 * EL PROBLEMA QUE RESUELVE
 * -----------------------
 * Marcar las facturas vencidas solo pasaba cuando alguien ABRÍA la
 * pantalla de facturas o lanzaba una corrida. Como el aviso de cobro
 * de las 08:00 busca facturas en estado «Vencida», si nadie entraba
 * no había vencidas, no salía ningún aviso y nadie se suspendía.
 *
 * Y LO QUE NO PUEDE ROMPER
 * ------------------------
 * Las sucursales que facturan a mano tienen que seguir igual: la
 * tarea diaria les marca la mora, pero NO les genera nada.
 */
class AutomaticBillingTest extends BillingTestCase
{
    /** Deja la sucursal del test facturando sola el día indicado. */
    private function automatica(int $dia, ?Branch $sucursal = null): BranchBillingSetting
    {
        $config = BranchBillingSetting::forBranch(($sucursal ?? $this->branch)->id);
        $config->update([
            'billing_mode' => BillingMode::Automatico->value,
            'billing_day' => $dia,
        ]);

        return $config;
    }

    public function test_la_sucursal_automatica_factura_el_dia_que_le_toca(): void
    {
        Carbon::setTestNow('2026-10-05 05:00:00');

        $contrato = $this->createBillableContract(80000);
        $this->automatica(5);

        $this->artisan('facturacion:diaria')->assertSuccessful();

        $this->assertDatabaseHas('invoices', [
            'contract_id' => $contrato->id,
            'billed_year_month' => '202610',
        ]);
    }

    public function test_la_sucursal_manual_no_factura_sola(): void
    {
        Carbon::setTestNow('2026-10-05 05:00:00');

        $contrato = $this->createBillableContract(80000);
        // Sin tocar nada: el defecto es manual.

        $this->artisan('facturacion:diaria')->assertSuccessful();

        $this->assertDatabaseMissing('invoices', ['contract_id' => $contrato->id]);
    }

    public function test_no_factura_los_dias_que_no_le_tocan(): void
    {
        Carbon::setTestNow('2026-10-06 05:00:00');

        $contrato = $this->createBillableContract(80000);
        $this->automatica(5);

        $this->artisan('facturacion:diaria')->assertSuccessful();

        $this->assertDatabaseMissing('invoices', ['contract_id' => $contrato->id]);
    }

    public function test_el_dia_31_no_se_salta_los_meses_cortos(): void
    {
        // Febrero no tiene 31: quien configuró «el 31» quiso decir el
        // último día, y si se comparara el número a secas esa sucursal
        // no facturaría en febrero y nadie se enteraría.
        Carbon::setTestNow('2027-02-28 05:00:00');

        $contrato = $this->createBillableContract(80000, 0, '2027-02-01');
        $this->automatica(31);

        $this->artisan('facturacion:diaria')->assertSuccessful();

        $this->assertDatabaseHas('invoices', [
            'contract_id' => $contrato->id,
            'billed_year_month' => '202702',
        ]);
    }

    public function test_no_factura_dos_veces_el_mismo_mes(): void
    {
        Carbon::setTestNow('2026-10-05 05:00:00');

        $contrato = $this->createBillableContract(80000);
        $this->automatica(5);

        $this->artisan('facturacion:diaria')->assertSuccessful();
        $this->artisan('facturacion:diaria')->assertSuccessful();

        $this->assertSame(1, Invoice::where('contract_id', $contrato->id)->count());
        $this->assertSame(1, BillingRun::where('branch_id', $this->branch->id)->count());
    }

    public function test_la_mora_se_marca_aunque_la_sucursal_sea_manual(): void
    {
        // Es el corazón del asunto: la cobranza no puede depender de
        // que alguien abra una pantalla.
        Carbon::setTestNow('2026-10-06 05:00:00');

        $contrato = $this->createBillableContract(80000);

        $factura = Invoice::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-21',
            'billed_year_month' => '202609',
            'subtotal' => 80000, 'discount' => 0, 'tax' => 0, 'total' => 80000,
            'pending_invoice_amount' => 80000,
            'status' => InvoiceStatus::Pendiente->value,
        ]);

        $this->artisan('facturacion:diaria')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Vencida->value, $factura->fresh()->status);
    }

    public function test_al_llegar_al_umbral_la_mora_diaria_suspende_el_contrato(): void
    {
        Carbon::setTestNow('2026-10-06 05:00:00');

        $contrato = $this->createBillableContract(80000);

        foreach (['202608' => '2026-08-21', '202609' => '2026-09-21'] as $periodo => $vence) {
            Invoice::create([
                'contract_id' => $contrato->id,
                'branch_id' => $this->branch->id,
                'issue_date' => Carbon::parse($vence)->subDays(20),
                'due_date' => $vence,
                'billed_year_month' => $periodo,
                'subtotal' => 80000, 'discount' => 0, 'tax' => 0, 'total' => 80000,
                'pending_invoice_amount' => 80000,
                'status' => InvoiceStatus::Pendiente->value,
            ]);
        }

        $this->artisan('facturacion:diaria')->assertSuccessful();

        // Umbral por defecto: 2 vencidas.
        $this->assertSame(ContractStatus::Suspendido->value, $contrato->fresh()->status);
    }

    public function test_la_pantalla_de_la_sucursal_ofrece_el_modo_y_el_dia(): void
    {
        $this->get(route('branches.edit', $this->branch))
            ->assertOk()
            ->assertSee('Cómo se factura')
            ->assertSee('name="billing_day"', false);
    }

    public function test_volver_a_manual_borra_el_dia(): void
    {
        // Dejar el día puesto haría creer que la sucursal sigue
        // programada cuando ya no lo está.
        $this->automatica(5);

        $this->put(route('branches.update', $this->branch), [
            'name' => $this->branch->name,
            'country' => $this->branch->country ?: 'Colombia',
            'department' => $this->branch->department ?: 'Antioquia',
            'municipality' => $this->branch->municipality ?: 'Yarumal',
            'address' => $this->branch->address ?: 'Calle 1',
            'number_phone' => $this->branch->number_phone ?: '3000000000',
            'company_id' => $this->branch->company_id,
            'proration_mode' => 'prorated',
            'billing_mode' => BillingMode::Manual->value,
            'billing_day' => 5,
            'due_days' => 20,
            'suspension_threshold' => 2,
            'suspension_days' => 24,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $config = BranchBillingSetting::forBranch($this->branch->id)->fresh();

        $this->assertSame(BillingMode::Manual, $config->billing_mode);
        $this->assertNull($config->billing_day);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
