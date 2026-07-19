<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\OverdueProcessor;
use App\Models\Branch;
use App\Models\Invoice;

/**
 * Cobranza: paso a Vencida, suspensión por umbral, aislamiento
 * entre sucursales (bug corregido en fase 2) y absorción de
 * facturas vencidas en la nueva factura del período.
 */
class OverdueAndSuspensionTest extends BillingTestCase
{
    /**
     * Crea una factura vencida (fecha pasada + estado Pendiente)
     * para un contrato, en un período pasado distinto por factura.
     */
    private function createOverdueInvoice($contract, int $monthsAgo): Invoice
    {
        $issue = now()->subMonths($monthsAgo);

        return Invoice::create([
            'contract_id' => $contract->id,
            'branch_id' => $contract->branch_id,
            'user_id' => $this->admin->id,
            'billed_year_month' => $issue->format('Ym'),
            'billed_period' => $issue->format('d M') . ' al ' . $issue->endOfMonth()->format('d M Y'),
            'issue_date' => $issue,
            'due_date' => $issue->copy()->addDays(20),
            'subtotal' => 50000,
            'total' => 50000,
            'pending_invoice_amount' => 50000,
            'status' => InvoiceStatus::Pendiente->value,
        ]);
    }

    public function test_marca_vencidas_y_suspende_al_alcanzar_el_umbral(): void
    {
        $contract = $this->createBillableContract();
        $this->createOverdueInvoice($contract, 2);
        $this->createOverdueInvoice($contract, 3);

        $processor = app(OverdueProcessor::class);
        $processor->markOverdueInvoices();
        $processor->refreshContractSuspensions($this->branch->id);

        $contract->refresh();

        $this->assertSame(ContractStatus::Suspendido->value, $contract->status);
        $this->assertSame(2, (int) $contract->overdue_invoices_count);
        $this->assertSame(
            2,
            Invoice::where('contract_id', $contract->id)
                ->where('status', InvoiceStatus::Vencida->value)
                ->count()
        );
    }

    public function test_no_suspende_contratos_de_otras_sucursales(): void
    {
        // Contrato moroso en OTRA sucursal (2 vencidas, umbral cumplido)
        $otherBranch = Branch::factory()->create();
        $otherAdmin = $this->admin; // reutilizamos el usuario; el contrato es lo relevante

        $foreignClientContract = $this->createBillableContract();
        $foreignClientContract->update(['branch_id' => $otherBranch->id]);
        $foreignClientContract->client->update(['branch_id' => $otherBranch->id]);

        $this->createOverdueInvoice($foreignClientContract, 2);
        $this->createOverdueInvoice($foreignClientContract, 3);

        // Correr la facturación de NUESTRA sucursal
        $this->post(route('invoices.generate'));

        // El contrato de la otra sucursal quedó con sus facturas
        // vencidas marcadas (regla global de fecha) pero NO fue
        // suspendido: la suspensión es por sucursal.
        $foreignClientContract->refresh();

        $this->assertNotSame(
            ContractStatus::Suspendido->value,
            $foreignClientContract->status,
            'La corrida de una sucursal suspendió un contrato de otra (bug cross-sucursal)'
        );
    }

    public function test_las_vencidas_quedan_abiertas_y_la_nueva_factura_solo_cobra_su_mes(): void
    {
        $contract = $this->createBillableContract(price: 100000, taxPercent: 0);
        $overdue = $this->createOverdueInvoice($contract, 2);

        // Marcarla vencida como lo haría el procesador
        app(OverdueProcessor::class)->markOverdueInvoices();
        $this->assertSame(InvoiceStatus::Vencida->value, $overdue->fresh()->status);

        $this->post(route('invoices.generate'));

        $newInvoice = Invoice::where('contract_id', $contract->id)
            ->where('billed_year_month', now()->format('Ym'))
            ->firstOrFail();

        // Modelo fase 4 (compatible con DIAN): la nueva factura
        // cobra SOLO su mes; la vencida NO se absorbe
        $this->assertEqualsWithDelta(100000, (float) $newInvoice->total, 0.01);
        $this->assertSame(InvoiceStatus::PendienteRiesgoCorte->value, $newInvoice->status);
        $this->assertCount(1, $newInvoice->invoice_items);

        // La vencida sigue abierta, cobrable de forma independiente
        $this->assertSame(InvoiceStatus::Vencida->value, $overdue->fresh()->status);

        // La deuda total del contrato es la suma de ambas
        $this->assertEqualsWithDelta(150000, $contract->outstandingBalance(), 0.01);

        // El contrato pasó a pre-suspensión con fecha de aviso
        // persistida (antes se perdía por el fillable)
        $contract->refresh();
        $this->assertSame(ContractStatus::PreSuspension->value, $contract->status);
        $this->assertNotNull($contract->suspension_warning_date);
    }
}
