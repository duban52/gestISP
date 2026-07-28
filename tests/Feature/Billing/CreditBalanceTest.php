<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\NoteType;
use App\Billing\Services\CreditBalanceService;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\NoteIssuer;
use App\Billing\Services\PaymentRegistrar;
use App\Models\AccountCredit;
use App\Models\CashRegister;
use App\Models\Invoice;
use App\Models\Payment;
use Tests\Feature\Billing\BillingTestCase;

/**
 * Saldo a favor del cliente.
 *
 * Cubre las dos formas en que un contrato queda con dinero a favor
 * —una nota crédito mayor que el saldo, y un pago por adelantado— y
 * lo esencial: que ese dinero se consuma solo con las facturas
 * siguientes y no se pierda por el camino.
 */
class CreditBalanceTest extends BillingTestCase
{
    private function abrirCaja(): CashRegister
    {
        return CashRegister::create([
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
            'opening_balance' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function facturaDe(float $total, array $extra = []): Invoice
    {
        $contrato = $this->createBillableContract(price: $total, taxPercent: 0);

        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        if ($extra) {
            $factura->update($extra);
        }

        return $factura->fresh();
    }

    // ============ Nota crédito mayor que el saldo ============

    public function test_el_excedente_de_una_nota_credito_queda_a_favor_del_cliente(): void
    {
        // Factura de 100.000 que el cliente YA pagó
        $factura = $this->facturaDe(100000, [
            'pending_invoice_amount' => 0,
            'status' => InvoiceStatus::Pagada->value,
        ]);

        // Se anula por completo: los 100.000 no se le deben cobrar
        app(NoteIssuer::class)->emitir($factura, [
            'type' => NoteType::Credito->value,
            'concept_code' => '2',
            'reason' => 'Se anula la factura: el servicio nunca se prestó ese mes.',
            'subtotal' => 100000,
            'tax' => 0,
        ]);

        $contrato = $factura->contract;

        // Como no debía nada, los 100.000 quedan a su favor
        $this->assertEquals(100000, app(CreditBalanceService::class)->saldo($contrato));
        $this->assertEquals(0, (float) $factura->fresh()->pending_invoice_amount);
    }

    public function test_la_nota_credito_abona_lo_que_cabe_y_el_resto_queda_a_favor(): void
    {
        // Factura de 100.000 con 30.000 aún pendientes
        $factura = $this->facturaDe(100000, [
            'pending_invoice_amount' => 30000,
            'status' => InvoiceStatus::PendienteParcial->value,
        ]);

        app(NoteIssuer::class)->emitir($factura, [
            'type' => NoteType::Credito->value,
            'concept_code' => '2',
            'reason' => 'Se anula la factura completa por acuerdo con el cliente.',
            'subtotal' => 100000,
            'tax' => 0,
        ]);

        // 30.000 saldan la factura; 70.000 quedan a favor
        $this->assertEquals(0, (float) $factura->fresh()->pending_invoice_amount);
        $this->assertEquals(70000, app(CreditBalanceService::class)->saldo($factura->contract));
    }

    public function test_no_se_puede_devolver_mas_de_lo_facturado(): void
    {
        $factura = $this->facturaDe(50000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('supera el valor total');

        app(NoteIssuer::class)->emitir($factura, [
            'type' => NoteType::Credito->value,
            'concept_code' => '3',
            'reason' => 'Intento de devolver más de lo que se facturó.',
            'subtotal' => 80000,
            'tax' => 0,
        ]);
    }

    // ============ Estado propio de la factura ============

    public function test_una_factura_saldada_por_nota_no_figura_como_pagada(): void
    {
        $factura = $this->facturaDe(60000);

        app(NoteIssuer::class)->emitir($factura, [
            'type' => NoteType::Credito->value,
            'concept_code' => '2',
            'reason' => 'Anulación total de la factura por error de facturación.',
            'subtotal' => 60000,
            'tax' => 0,
        ]);

        $factura->refresh();

        // El dinero nunca se recaudó: no puede contarse como cobrado
        $this->assertSame(InvoiceStatus::SaldadaConNota->value, $factura->status);
        $this->assertNotSame(InvoiceStatus::Pagada->value, $factura->status);
        $this->assertEquals(0, (float) $factura->pending_invoice_amount);
    }

    // ==================== Anticipos ====================

    public function test_un_anticipo_queda_a_favor_y_entra_a_la_caja(): void
    {
        $caja = $this->abrirCaja();
        $contrato = $this->createBillableContract(price: 50000, taxPercent: 0);

        $resultado = app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $contrato->id,
            'amount' => 300000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);

        $this->assertEquals(300000, $resultado['saldo_a_favor']);
        $this->assertEquals(0, $resultado['aplicado']);

        // El dinero entró a la caja como cualquier cobro
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $caja->id,
            'transaction_type' => 'Ingreso',
            'amount' => 300000,
        ]);

        // Y quedó un pago de tipo anticipo, sin factura asociada
        $pago = Payment::latest('id')->first();
        $this->assertSame('anticipo', $pago->type);
        $this->assertNull($pago->invoice_id);
        $this->assertSame($contrato->id, $pago->contract_id);
    }

    public function test_el_anticipo_abona_primero_lo_que_el_cliente_ya_debe(): void
    {
        $this->abrirCaja();

        $factura = $this->facturaDe(80000);
        $contrato = $factura->contract;

        $resultado = app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $contrato->id,
            'amount' => 200000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);

        // Se pagan los 80.000 que debía y quedan 120.000 a favor
        $this->assertEquals(80000, $resultado['aplicado']);
        $this->assertEquals(120000, $resultado['saldo_a_favor']);

        $factura->refresh();
        $this->assertEquals(0, (float) $factura->pending_invoice_amount);
        $this->assertSame(InvoiceStatus::Pagada->value, $factura->status);
    }

    public function test_sin_caja_abierta_no_se_recibe_un_anticipo(): void
    {
        $contrato = $this->createBillableContract(price: 50000, taxPercent: 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('caja abierta');

        app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $contrato->id,
            'amount' => 100000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);
    }

    // ============ Consumo mes a mes ============

    public function test_el_saldo_a_favor_paga_solo_las_facturas_siguientes(): void
    {
        $this->abrirCaja();

        $contrato = $this->createBillableContract(price: 50000, taxPercent: 0);

        // El cliente adelanta el equivalente a tres meses
        app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $contrato->id,
            'amount' => 150000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);

        $generador = app(InvoiceGenerator::class);
        $saldos = app(CreditBalanceService::class);

        // Mes 1
        $generador->generateForContract($contrato, now(), $this->admin->id);
        $mes1 = Invoice::where('contract_id', $contrato->id)->latest('id')->first();

        $this->assertSame(InvoiceStatus::Pagada->value, $mes1->status);
        $this->assertEquals(0, (float) $mes1->pending_invoice_amount);
        $this->assertEquals(100000, $saldos->saldo($contrato->fresh()));

        // Mes 2
        $generador->generateForContract($contrato, now()->addMonthNoOverflow(), $this->admin->id);
        $mes2 = Invoice::where('contract_id', $contrato->id)->latest('id')->first();

        $this->assertSame(InvoiceStatus::Pagada->value, $mes2->status);
        $this->assertEquals(50000, $saldos->saldo($contrato->fresh()));

        // Mes 3: se agota el saldo
        $generador->generateForContract($contrato, now()->addMonthsNoOverflow(2), $this->admin->id);
        $this->assertEquals(0, $saldos->saldo($contrato->fresh()));

        // Mes 4: ya sin saldo, la factura nace pendiente
        $generador->generateForContract($contrato, now()->addMonthsNoOverflow(3), $this->admin->id);
        $mes4 = Invoice::where('contract_id', $contrato->id)->latest('id')->first();

        $this->assertSame(InvoiceStatus::Pendiente->value, $mes4->status);
        $this->assertGreaterThan(0, (float) $mes4->pending_invoice_amount);
    }

    public function test_un_anticipo_parcial_deja_la_factura_en_pendiente_parcial(): void
    {
        $this->abrirCaja();

        $factura = $this->facturaDe(100000);

        app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $factura->contract_id,
            'amount' => 40000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);

        $factura->refresh();

        $this->assertEquals(60000, (float) $factura->pending_invoice_amount);
        $this->assertSame(InvoiceStatus::PendienteParcial->value, $factura->status);
        $this->assertEquals(0, app(CreditBalanceService::class)->saldo($factura->contract));
    }

    // ==================== Libro de movimientos ====================

    public function test_cada_movimiento_queda_registrado_y_explicado(): void
    {
        $this->abrirCaja();

        $factura = $this->facturaDe(60000);
        $contrato = $factura->contract;

        app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $contrato->id,
            'amount' => 100000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);

        $movimientos = AccountCredit::where('contract_id', $contrato->id)->get();

        // Una entrada por el anticipo y una aplicación a la factura
        $this->assertSame(1, $movimientos->where('movement', AccountCredit::ENTRADA)->count());
        $this->assertSame(1, $movimientos->where('movement', AccountCredit::APLICACION)->count());

        $entrada = $movimientos->firstWhere('movement', AccountCredit::ENTRADA);
        $this->assertSame(AccountCredit::ORIGEN_ANTICIPO, $entrada->origin);
        $this->assertNotNull($entrada->payment_id);

        $aplicacion = $movimientos->firstWhere('movement', AccountCredit::APLICACION);
        $this->assertSame($factura->id, $aplicacion->invoice_id);

        // El saldo se explica con el libro: 100.000 − 60.000
        $this->assertEquals(40000, app(CreditBalanceService::class)->saldo($contrato));
    }

    public function test_un_anticipo_aparece_en_el_registro_de_pagos(): void
    {
        $this->abrirCaja();
        $contrato = $this->createBillableContract(price: 50000, taxPercent: 0);

        app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $contrato->id,
            'amount' => 120000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);

        // El anticipo es dinero que entró a la caja: tiene que verse
        // en el registro de pagos. Antes el listado filtraba solo por
        // invoice.contract.branch y los anticipos, que no tienen
        // factura, quedaban invisibles.
        $this->get(route('payments.index'))
            ->assertOk()
            ->assertSee($contrato->numero_visible)
            ->assertSee('Anticipo');
    }

    public function test_el_saldo_a_favor_queda_en_la_trazabilidad(): void
    {
        $this->abrirCaja();
        $contrato = $this->createBillableContract(price: 50000, taxPercent: 0);

        app(PaymentRegistrar::class)->registerAdvance([
            'contract_id' => $contrato->id,
            'amount' => 90000,
            'payment_method' => 'Efectivo',
        ], $this->admin->id);

        $this->assertDatabaseHas('audits', ['action' => 'invoices.credit_added']);
    }
}
