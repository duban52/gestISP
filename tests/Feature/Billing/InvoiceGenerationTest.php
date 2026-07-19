<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Models\AditionalCharge;
use App\Models\Invoice;
use Illuminate\Database\QueryException;

/**
 * Regresión del flujo de generación de facturas mensuales
 * (InvoiceController::generateInvoices) tras la fase 1 de
 * fundaciones: enums, branch_id, subtotal y period_start/end.
 */
class InvoiceGenerationTest extends BillingTestCase
{
    public function test_genera_factura_de_mes_completo(): void
    {
        $contract = $this->createBillableContract(price: 100000, taxPercent: 19);

        $response = $this->post(route('invoices.generate'));

        $response->assertRedirect(route('invoices.index'));

        $invoice = Invoice::where('contract_id', $contract->id)->first();

        $this->assertNotNull($invoice, 'La factura no se generó');
        $this->assertSame(InvoiceStatus::Pendiente->value, $invoice->status);
        $this->assertSame($this->branch->id, $invoice->branch_id);
        $this->assertSame(now()->format('Ym'), $invoice->billed_year_month);

        // Mes completo: subtotal = precio base, total = base + IVA
        $this->assertEqualsWithDelta(100000, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(19000, (float) $invoice->tax, 0.01);
        $this->assertEqualsWithDelta(119000, (float) $invoice->total, 0.01);

        // Período real como fechas consultables
        $this->assertSame(now()->startOfMonth()->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $invoice->period_end->toDateString());

        // Un ítem por servicio del plan
        $this->assertCount(1, $invoice->invoice_items);
    }

    public function test_prorratea_cuando_el_contrato_se_activa_a_mitad_de_mes(): void
    {
        // Activación el día 20 del mes actual
        $activation = now()->startOfMonth()->addDays(19);
        $contract = $this->createBillableContract(
            price: 100000,
            taxPercent: 0,
            activationDate: $activation->toDateString()
        );

        $this->post(route('invoices.generate'));

        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $daysInMonth = now()->daysInMonth;
        $remainingDays = $daysInMonth - 19;
        $expected = 100000 * $remainingDays / $daysInMonth;

        $this->assertEqualsWithDelta($expected, (float) $invoice->total, 0.01);
        $this->assertSame($activation->toDateString(), $invoice->period_start->toDateString());
    }

    public function test_no_duplica_factura_para_el_mismo_periodo(): void
    {
        $contract = $this->createBillableContract();

        $this->post(route('invoices.generate'));
        $this->post(route('invoices.generate'));

        $this->assertSame(
            1,
            Invoice::where('contract_id', $contract->id)->count(),
            'Se generó más de una factura para el mismo período'
        );
    }

    public function test_incluye_cargos_adicionales_pendientes(): void
    {
        $contract = $this->createBillableContract(price: 50000, taxPercent: 0);

        $charge = AditionalCharge::create([
            'contract_id' => $contract->id,
            'user_id' => $this->admin->id,
            'description' => 'Traslado de servicio',
            'amount' => 30000,
            'status' => 'pendiente',
        ]);

        $this->post(route('invoices.generate'));

        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->assertEqualsWithDelta(80000, (float) $invoice->total, 0.01);
        $this->assertCount(2, $invoice->invoice_items);

        // El cargo queda marcado como facturado y no se re-factura
        $this->assertSame('Facturado', $charge->fresh()->status);
    }

    public function test_la_base_de_datos_rechaza_periodos_duplicados(): void
    {
        $contract = $this->createBillableContract();

        $this->post(route('invoices.generate'));

        // Saltarse la validación de aplicación debe chocar contra la
        // restricción única (contract_id, billed_year_month)
        $this->expectException(QueryException::class);

        Invoice::create([
            'contract_id' => $contract->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'billed_year_month' => now()->format('Ym'),
            'issue_date' => now(),
            'due_date' => now()->addDays(20),
            'total' => 1000,
            'status' => InvoiceStatus::Pendiente->value,
        ]);
    }
}
