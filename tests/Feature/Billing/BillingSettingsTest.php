<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\ProrationMode;
use App\Billing\Services\OverdueProcessor;
use App\Models\BranchBillingSetting;
use App\Models\Invoice;

/**
 * Configuración de facturación por sucursal (fase 3): modo de
 * prorrateo A/B, días de plazo y umbral de suspensión, todo
 * modificable sin tocar código.
 */
class BillingSettingsTest extends BillingTestCase
{
    public function test_los_defaults_reproducen_el_comportamiento_historico(): void
    {
        $settings = BranchBillingSetting::forBranch($this->branch->id);

        $this->assertSame(ProrationMode::Prorated, $settings->proration_mode);
        $this->assertSame(20, $settings->due_days);
        $this->assertSame(2, $settings->suspension_threshold);
        $this->assertSame(24, $settings->suspension_days);
    }

    public function test_modo_mes_completo_factura_todo_el_mes_aunque_se_active_a_mitad(): void
    {
        BranchBillingSetting::forBranch($this->branch->id)
            ->update(['proration_mode' => ProrationMode::FullMonth->value]);

        // Contrato activado el día 20: con opción A paga el mes entero
        $contract = $this->createBillableContract(
            price: 100000,
            taxPercent: 0,
            activationDate: now()->startOfMonth()->addDays(19)->toDateString()
        );

        $this->post(route('invoices.generate'));

        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->assertEqualsWithDelta(100000, (float) $invoice->total, 0.01);
        $this->assertSame(now()->startOfMonth()->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $invoice->period_end->toDateString());
    }

    public function test_los_dias_de_plazo_configurados_definen_el_vencimiento(): void
    {
        BranchBillingSetting::forBranch($this->branch->id)->update(['due_days' => 10]);

        $contract = $this->createBillableContract();
        $this->post(route('invoices.generate'));

        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->assertSame(
            now()->addDays(10)->toDateString(),
            $invoice->due_date->toDateString()
        );
    }

    public function test_el_umbral_de_suspension_configurado_se_respeta(): void
    {
        // Umbral subido a 3: con 2 vencidas el contrato NO se corta
        BranchBillingSetting::forBranch($this->branch->id)->update(['suspension_threshold' => 3]);

        $contract = $this->createBillableContract();

        foreach ([2, 3] as $monthsAgo) {
            Invoice::create([
                'contract_id' => $contract->id,
                'branch_id' => $contract->branch_id,
                'user_id' => $this->admin->id,
                'billed_year_month' => now()->subMonths($monthsAgo)->format('Ym'),
                'issue_date' => now()->subMonths($monthsAgo),
                'due_date' => now()->subMonths($monthsAgo)->addDays(20),
                'total' => 50000,
                'pending_invoice_amount' => 50000,
                'status' => InvoiceStatus::Vencida->value,
            ]);
        }

        app(OverdueProcessor::class)->refreshContractSuspensions($this->branch->id);

        $contract->refresh();
        $this->assertNotSame(ContractStatus::Suspendido->value, $contract->status);
        $this->assertSame(2, (int) $contract->overdue_invoices_count);
    }

    public function test_la_configuracion_se_edita_desde_el_modulo_de_sucursales(): void
    {
        $payload = [
            'nit' => $this->branch->nit,
            'name' => $this->branch->name,
            'country' => $this->branch->country,
            'department' => $this->branch->department,
            'municipality' => $this->branch->municipality,
            'address' => $this->branch->address,
            'number_phone' => '3000000000',
            // Configuración de facturación nueva
            'proration_mode' => ProrationMode::FullMonth->value,
            'due_days' => 15,
            'suspension_threshold' => 4,
            'suspension_days' => 30,
        ];

        $this->put(route('branches.update', $this->branch), $payload)
            ->assertRedirect(route('branches.index'));

        $settings = BranchBillingSetting::forBranch($this->branch->id)->fresh();

        $this->assertSame(ProrationMode::FullMonth, $settings->proration_mode);
        $this->assertSame(15, $settings->due_days);
        $this->assertSame(4, $settings->suspension_threshold);
        $this->assertSame(30, $settings->suspension_days);
    }

    public function test_rechaza_valores_de_configuracion_invalidos(): void
    {
        $payload = [
            'nit' => $this->branch->nit,
            'name' => $this->branch->name,
            'country' => $this->branch->country,
            'department' => $this->branch->department,
            'municipality' => $this->branch->municipality,
            'address' => $this->branch->address,
            'number_phone' => '3000000000',
            'proration_mode' => 'modo_inexistente',
            'due_days' => 500,
            'suspension_threshold' => 0,
            'suspension_days' => -1,
        ];

        $this->put(route('branches.update', $this->branch), $payload)
            ->assertSessionHasErrors(['proration_mode', 'due_days', 'suspension_threshold', 'suspension_days']);

        // La configuración no cambió
        $settings = BranchBillingSetting::forBranch($this->branch->id);
        $this->assertSame(ProrationMode::Prorated, $settings->proration_mode);
    }
}
