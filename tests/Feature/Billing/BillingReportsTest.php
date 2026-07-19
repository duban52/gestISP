<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Models\BillingRun;
use App\Models\Invoice;

/**
 * Reportes gerenciales: corridas de facturación persistidas con
 * totales, badge "por recaudar" real y resumen de cajas por
 * período.
 */
class BillingReportsTest extends BillingTestCase
{
    public function test_cada_generacion_registra_una_corrida_con_totales(): void
    {
        $this->createBillableContract(price: 100000, taxPercent: 19);
        $this->createBillableContract(price: 50000, taxPercent: 0);

        $this->post(route('invoices.generate'));

        $run = BillingRun::where('branch_id', $this->branch->id)->firstOrFail();

        $this->assertSame(2, $run->contracts_count);
        $this->assertSame(2, $run->generated_count);
        $this->assertSame(0, $run->skipped_count);
        $this->assertEqualsWithDelta(150000, (float) $run->total_subtotal, 0.01);
        $this->assertEqualsWithDelta(19000, (float) $run->total_tax, 0.01);
        $this->assertEqualsWithDelta(169000, (float) $run->total_billed, 0.01);
        $this->assertSame(now()->format('Ym'), $run->billed_year_month);
        $this->assertSame($this->admin->id, $run->user_id);

        // La página del reporte carga y muestra la corrida
        $this->get(route('invoices.billing_runs'))
            ->assertOk()
            ->assertSee('REPORTES DE FACTURACIÓN', false)
            ->assertSee(number_format(169000, 2));
    }

    public function test_el_badge_por_recaudar_suma_los_saldos_abiertos(): void
    {
        // Factura del mes (100.000) + abono de 40.000 → saldo 60.000
        $contract = $this->createBillableContract(price: 100000, taxPercent: 0);
        $this->post(route('invoices.generate'));
        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->openCashRegister();
        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 40000,
            'payment_method' => 'transferencia',
        ])->assertOk();

        // Una vencida vieja de otro contrato con saldo 50.000
        $otherContract = $this->createBillableContract(price: 50000, taxPercent: 0);
        Invoice::create([
            'contract_id' => $otherContract->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'billed_year_month' => now()->subMonths(2)->format('Ym'),
            'issue_date' => now()->subMonths(2),
            'due_date' => now()->subMonths(2)->addDays(20),
            'total' => 50000,
            'pending_invoice_amount' => 50000,
            'status' => InvoiceStatus::Vencida->value,
        ]);

        // Por recaudar = 60.000 (parcial) + 50.000 (vencida) = 110.000
        $response = $this->get(route('invoices.index'))->assertOk();

        $this->assertEqualsWithDelta(110000, (float) $response->viewData('totalPendding'), 0.01);
    }

    public function test_el_resumen_de_cajas_muestra_el_cuadre_del_periodo(): void
    {
        $contract = $this->createBillableContract(price: 100000, taxPercent: 0);
        $this->post(route('invoices.generate'));
        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $register = $this->openCashRegister(initialAmount: 20000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'payment_method' => 'cash',
        ])->assertOk();

        $response = $this->get(route('cash_register.summary'))->assertOk();

        $response->assertSee('RESUMEN DE CAJAS POR PERÍODO', false)
            ->assertSee($this->admin->name, false);

        // Totales del período: ingreso 100.000, esperado 120.000
        $this->assertEqualsWithDelta(100000, (float) $response->viewData('totals')['income'], 0.01);
        $this->assertEqualsWithDelta(120000, (float) $response->viewData('totals')['expected'], 0.01);
        $this->assertSame(1, $response->viewData('totals')['open_count']);
    }
}
