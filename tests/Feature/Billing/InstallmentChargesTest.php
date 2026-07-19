<?php

namespace Tests\Feature\Billing;

use App\Billing\Services\InvoiceGenerator;
use App\Models\AditionalCharge;
use App\Models\Invoice;

/**
 * Cargos adicionales diferidos a cuotas: cada generación mensual
 * incluye una cuota en la factura hasta completar el cargo. Se
 * usa el servicio InvoiceGenerator directamente para simular
 * meses consecutivos.
 */
class InstallmentChargesTest extends BillingTestCase
{
    public function test_un_cargo_diferido_se_factura_en_cuotas_mensuales(): void
    {
        // Plan de 50.000 y un decodificador TDT de 90.000 en 3 cuotas
        $contract = $this->createBillableContract(price: 50000, taxPercent: 0);

        $charge = AditionalCharge::create([
            'contract_id' => $contract->id,
            'user_id' => $this->admin->id,
            'description' => 'Decodificador TDT',
            'amount' => 90000,
            'installments_total' => 3,
            'status' => 'pendiente',
        ]);

        $generator = app(InvoiceGenerator::class);

        // Mes 1: mensualidad + cuota 1/3
        $generator->generateForContract($contract->fresh(), now(), $this->admin->id);
        $invoice1 = Invoice::where('contract_id', $contract->id)->latest('id')->first();

        $this->assertEqualsWithDelta(80000, (float) $invoice1->total, 0.01);
        $this->assertTrue(
            $invoice1->invoice_items->contains(fn ($i) => str_contains($i->description, '(cuota 1/3)')),
            'La factura del mes 1 no incluye la cuota 1/3'
        );
        $this->assertSame('pendiente', $charge->fresh()->status);
        $this->assertSame(1, $charge->fresh()->installments_billed);

        // Mes 2: cuota 2/3
        $generator->generateForContract($contract->fresh(), now()->addMonthNoOverflow(), $this->admin->id);
        $invoice2 = Invoice::where('contract_id', $contract->id)->latest('id')->first();

        $this->assertTrue(
            $invoice2->invoice_items->contains(fn ($i) => str_contains($i->description, '(cuota 2/3)'))
        );

        // Mes 3: cuota 3/3 → el cargo queda Facturado
        $generator->generateForContract($contract->fresh(), now()->addMonthsNoOverflow(2), $this->admin->id);
        $invoice3 = Invoice::where('contract_id', $contract->id)->latest('id')->first();

        $this->assertTrue(
            $invoice3->invoice_items->contains(fn ($i) => str_contains($i->description, '(cuota 3/3)'))
        );
        $this->assertSame('Facturado', $charge->fresh()->status);
        $this->assertSame(3, $charge->fresh()->installments_billed);

        // Mes 4: ya no hay más cuotas — solo la mensualidad
        $generator->generateForContract($contract->fresh(), now()->addMonthsNoOverflow(3), $this->admin->id);
        $invoice4 = Invoice::where('contract_id', $contract->id)->latest('id')->first();

        $this->assertEqualsWithDelta(50000, (float) $invoice4->total, 0.01);
        $this->assertCount(1, $invoice4->invoice_items);

        // La suma de las cuotas facturadas es exactamente el cargo
        $totalCuotas = (float) $invoice1->total + (float) $invoice2->total
            + (float) $invoice3->total - 3 * 50000;
        $this->assertEqualsWithDelta(90000, $totalCuotas, 0.01);
    }

    public function test_la_ultima_cuota_ajusta_el_redondeo(): void
    {
        // 100.000 / 3 = 33.333,33 → la última cuota debe ser 33.333,34
        $charge = AditionalCharge::create([
            'contract_id' => $this->createBillableContract()->id,
            'user_id' => $this->admin->id,
            'description' => 'Router',
            'amount' => 100000,
            'installments_total' => 3,
            'status' => 'pendiente',
        ]);

        $this->assertEqualsWithDelta(33333.33, $charge->amountForInstallment(1), 0.001);
        $this->assertEqualsWithDelta(33333.33, $charge->amountForInstallment(2), 0.001);
        $this->assertEqualsWithDelta(33333.34, $charge->amountForInstallment(3), 0.001);

        // La suma exacta reconstruye el monto original
        $suma = $charge->amountForInstallment(1)
            + $charge->amountForInstallment(2)
            + $charge->amountForInstallment(3);
        $this->assertEqualsWithDelta(100000, $suma, 0.001);
    }

    public function test_un_cargo_de_contado_se_factura_completo_como_antes(): void
    {
        $contract = $this->createBillableContract(price: 50000, taxPercent: 0);

        $charge = AditionalCharge::create([
            'contract_id' => $contract->id,
            'user_id' => $this->admin->id,
            'description' => 'Traslado',
            'amount' => 30000,
            'status' => 'pendiente',
        ]);

        $this->post(route('invoices.generate'));

        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->assertEqualsWithDelta(80000, (float) $invoice->total, 0.01);
        $this->assertSame('Facturado', $charge->fresh()->status);
    }
}
