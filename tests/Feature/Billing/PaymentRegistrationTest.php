<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\PaymentStatus;
use App\Models\Invoice;

/**
 * Regresión del flujo de registro de pagos
 * (PaymentController::store) tras la fase 1 de fundaciones.
 *
 * Los pagos se hacen por transferencia para no requerir caja
 * abierta (regla real: efectivo/tarjeta exigen caja).
 */
class PaymentRegistrationTest extends BillingTestCase
{
    /**
     * Genera una factura real por el flujo oficial y la retorna.
     */
    private function generateInvoice(float $price = 100000, float $taxPercent = 0): Invoice
    {
        $contract = $this->createBillableContract($price, $taxPercent);
        $this->post(route('invoices.generate'));

        return Invoice::where('contract_id', $contract->id)->firstOrFail();
    }

    public function test_pago_total_marca_la_factura_como_pagada(): void
    {
        $invoice = $this->generateInvoice(price: 100000);

        $response = $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'payment_method' => 'transferencia',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Pagada->value, $invoice->status);
        $this->assertEqualsWithDelta(0, (float) $invoice->getPendingAmount(), 0.01);

        // El pago queda auditado
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'status' => PaymentStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('payment_audits', [
            'action' => 'created',
        ]);
    }

    public function test_pago_parcial_deja_la_factura_en_pendiente_parcial(): void
    {
        $invoice = $this->generateInvoice(price: 100000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 40000,
            'payment_method' => 'transferencia',
        ])->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PendienteParcial->value, $invoice->status);
        $this->assertEqualsWithDelta(60000, (float) $invoice->getPendingAmount(), 0.01);

        // Un segundo abono por el resto la salda
        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 60000,
            'payment_method' => 'transferencia',
        ])->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Pagada->value, $invoice->status);
        $this->assertEqualsWithDelta(0, (float) $invoice->getPendingAmount(), 0.01);
    }

    public function test_rechaza_pagos_que_exceden_el_saldo(): void
    {
        $invoice = $this->generateInvoice(price: 100000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 150000,
            'payment_method' => 'transferencia',
        ])->assertStatus(422);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Pendiente->value, $invoice->status);
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_rechaza_pagos_en_efectivo_sin_caja_abierta(): void
    {
        $invoice = $this->generateInvoice(price: 100000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame(0, $invoice->payments()->count());
    }
}
