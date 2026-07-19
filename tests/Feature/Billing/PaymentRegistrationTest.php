<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\PaymentStatus;
use App\Models\Invoice;

/**
 * Regresión del flujo de registro de pagos
 * (PaymentController::store).
 *
 * Regla de control interno: TODO cobro exige caja abierta del
 * usuario, sin importar el método de pago — cada peso recaudado
 * queda dentro del cuadre de una caja.
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
        $register = $this->openCashRegister();

        $response = $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'payment_method' => 'transferencia',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        // El pago quedó dentro de la caja abierta, con su
        // movimiento de ingreso registrado
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $register->id,
            'transaction_type' => 'Ingreso',
        ]);
        $this->assertEqualsWithDelta(100000, (float) $register->fresh()->total_income, 0.01);

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
        $this->openCashRegister();

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
        $this->openCashRegister();

        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 150000,
            'payment_method' => 'transferencia',
        ])->assertStatus(422);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Pendiente->value, $invoice->status);
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_rechaza_cualquier_pago_sin_caja_abierta(): void
    {
        $invoice = $this->generateInvoice(price: 100000);

        // Sin caja abierta NINGÚN método es válido, incluidas las
        // transferencias (antes se colaban por fuera del cuadre)
        foreach (['cash', 'card', 'transferencia'] as $method) {
            $this->postJson(route('payments.store'), [
                'invoice_id' => $invoice->id,
                'amount' => 100000,
                'payment_method' => $method,
            ])->assertStatus(422);
        }

        $this->assertSame(0, $invoice->payments()->count());
    }
}
