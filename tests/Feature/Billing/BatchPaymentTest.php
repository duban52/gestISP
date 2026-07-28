<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\RetentionType;
use App\Billing\Services\InvoiceGenerator;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentBatch;

/**
 * Cobro múltiple: varias facturas en una sola entrega de dinero.
 *
 * El caso es el de quien llega a pagar el servicio de su mamá, su
 * hermana y su abuela. Lo que se comprueba aquí es lo que no puede
 * fallar en un mostrador:
 *
 *  - Que cada contrato quede con SU pago y SU recibo (no un revoltijo).
 *  - Que sea TODO o NADA: si una factura no se puede cobrar, no se
 *    cobra ninguna. Lo contrario deja al cliente con plata entregada
 *    y facturas a medio pagar.
 */
class BatchPaymentTest extends BillingTestCase
{
    /** Crea un contrato con su factura del mes ya generada. */
    private function contratoConFactura(float $precio): Invoice
    {
        $contrato = $this->createBillableContract(price: $precio, taxPercent: 0);

        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        return Invoice::where('contract_id', $contrato->id)->firstOrFail();
    }

    /** Cuerpo mínimo de un cobro múltiple. */
    private function cobro(array $items, array $extra = []): array
    {
        return array_merge([
            'payment_method' => 'Efectivo',
            'items' => $items,
        ], $extra);
    }

    // ==================== Camino feliz ====================

    public function test_cobra_las_facturas_de_varios_contratos_de_una_vez(): void
    {
        $this->openCashRegister();

        $mama = $this->contratoConFactura(50000);
        $hermana = $this->contratoConFactura(60000);
        $abuela = $this->contratoConFactura(45000);

        $respuesta = $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $mama->id, 'amount' => 50000],
            ['invoice_id' => $hermana->id, 'amount' => 60000],
            ['invoice_id' => $abuela->id, 'amount' => 45000],
        ], [
            'payer_name' => 'María Restrepo',
            'payer_document' => '43567890',
        ]))->assertOk();

        $this->assertTrue($respuesta->json('success'));
        $this->assertSame(3, $respuesta->json('batch.pagos'));
        $this->assertSame(3, $respuesta->json('batch.contratos'));

        // Las tres facturas quedaron pagadas
        foreach ([$mama, $hermana, $abuela] as $factura) {
            $factura->refresh();
            $this->assertEquals(0, (float) $factura->pending_invoice_amount);
            $this->assertSame(InvoiceStatus::Pagada->value, $factura->status);
        }

        // Un solo lote, tres pagos, y queda constancia de quién pagó
        $lote = PaymentBatch::firstOrFail();

        $this->assertSame('María Restrepo', $lote->payer_name);
        $this->assertEquals(155000, (float) $lote->total_amount);
        $this->assertSame(3, $lote->payments()->count());
    }

    public function test_cada_pago_conserva_su_factura_y_su_contrato(): void
    {
        $this->openCashRegister();

        $uno = $this->contratoConFactura(50000);
        $dos = $this->contratoConFactura(60000);

        $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $uno->id, 'amount' => 50000],
            ['invoice_id' => $dos->id, 'amount' => 60000],
        ]))->assertOk();

        // No se fusionan importes: cada factura tiene su propio pago
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $uno->id,
            'contract_id' => $uno->contract_id,
            'amount' => 50000,
        ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $dos->id,
            'contract_id' => $dos->contract_id,
            'amount' => 60000,
        ]);
    }

    public function test_el_dinero_entra_a_la_caja_movimiento_por_movimiento(): void
    {
        $caja = $this->openCashRegister();

        $uno = $this->contratoConFactura(50000);
        $dos = $this->contratoConFactura(60000);

        $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $uno->id, 'amount' => 50000],
            ['invoice_id' => $dos->id, 'amount' => 60000],
        ]))->assertOk();

        // Dos movimientos, no uno de 110.000: el cuadre de caja debe
        // poder decir de qué factura salió cada peso.
        $this->assertSame(2, $caja->transactions()->count());
        $this->assertEquals(110000, (float) $caja->fresh()->total_income);
    }

    public function test_admite_varias_facturas_del_mismo_contrato(): void
    {
        $this->openCashRegister();

        // Un cliente atrasado: dos meses de la misma cuenta
        $contrato = $this->createBillableContract(price: 40000, taxPercent: 0);
        $generador = app(InvoiceGenerator::class);

        $generador->generateForContract($contrato, now(), $this->admin->id);
        $generador->generateForContract($contrato, now()->addMonthNoOverflow(), $this->admin->id);

        $facturas = Invoice::where('contract_id', $contrato->id)->orderBy('id')->get();

        $this->assertCount(2, $facturas);

        $respuesta = $this->postJson(route('payments.storeBatch'), $this->cobro(
            $facturas->map(fn ($f) => ['invoice_id' => $f->id, 'amount' => 40000])->all()
        ))->assertOk();

        // Dos pagos, pero un solo contrato: el recibo será uno solo
        $this->assertSame(2, $respuesta->json('batch.pagos'));
        $this->assertSame(1, $respuesta->json('batch.contratos'));
    }

    public function test_el_cobro_multiple_admite_retenciones_por_factura(): void
    {
        $this->openCashRegister();

        $uno = $this->contratoConFactura(100000);
        $dos = $this->contratoConFactura(50000);

        $this->postJson(route('payments.storeBatch'), $this->cobro([
            [
                'invoice_id' => $uno->id,
                'amount' => 96000,
                'retentions' => [[
                    'type' => RetentionType::Renta->value,
                    'concept_code' => 'servicios_generales_declarante',
                    'base' => 100000,
                    'rate' => 4,
                    'amount' => 4000,
                ]],
            ],
            ['invoice_id' => $dos->id, 'amount' => 50000],
        ]))->assertOk();

        // Ambas quedan saldadas, aunque de una entraron 4.000 menos
        $this->assertEquals(0, (float) $uno->fresh()->pending_invoice_amount);
        $this->assertEquals(0, (float) $dos->fresh()->pending_invoice_amount);

        $lote = PaymentBatch::firstOrFail();

        $this->assertEquals(146000, (float) $lote->total_amount);
        $this->assertEquals(4000, (float) $lote->total_retentions);
    }

    // ==================== Todo o nada ====================

    public function test_si_una_factura_falla_no_se_cobra_ninguna(): void
    {
        $this->openCashRegister();

        $buena = $this->contratoConFactura(50000);
        $mala = $this->contratoConFactura(60000);

        $respuesta = $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $buena->id, 'amount' => 50000],
            // Se intenta cobrar más de lo que debe
            ['invoice_id' => $mala->id, 'amount' => 99999],
        ]))->assertStatus(422);

        // El error dice CUÁL factura falló: en un lote de cinco,
        // "excede el saldo" a secas no le sirve al cajero.
        $this->assertStringContainsString($mala->displayNumber(), $respuesta->json('error'));

        // Y la que sí se podía cobrar quedó intacta
        $this->assertEquals(50000, (float) $buena->fresh()->pending_invoice_amount);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PaymentBatch::count());
    }

    public function test_no_se_puede_repetir_la_misma_factura_en_el_lote(): void
    {
        $this->openCashRegister();

        $factura = $this->contratoConFactura(50000);

        $respuesta = $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $factura->id, 'amount' => 25000],
            ['invoice_id' => $factura->id, 'amount' => 25000],
        ]))->assertStatus(422);

        $this->assertStringContainsString('repetida', $respuesta->json('error'));
        $this->assertSame(0, Payment::count());
    }

    public function test_sin_caja_abierta_no_se_cobra_el_lote(): void
    {
        $uno = $this->contratoConFactura(50000);
        $dos = $this->contratoConFactura(60000);

        $respuesta = $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $uno->id, 'amount' => 50000],
            ['invoice_id' => $dos->id, 'amount' => 60000],
        ]))->assertStatus(422);

        $this->assertStringContainsString('caja abierta', $respuesta->json('error'));
        $this->assertSame(0, PaymentBatch::count());
    }

    public function test_un_lote_vacio_se_rechaza(): void
    {
        $this->openCashRegister();

        $this->postJson(route('payments.storeBatch'), [
            'payment_method' => 'Efectivo',
            'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    // ==================== Trazabilidad ====================

    public function test_el_cobro_multiple_queda_en_la_trazabilidad(): void
    {
        $this->openCashRegister();

        $uno = $this->contratoConFactura(50000);
        $dos = $this->contratoConFactura(60000);

        $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $uno->id, 'amount' => 50000],
            ['invoice_id' => $dos->id, 'amount' => 60000],
        ], ['payer_name' => 'Un tercero']))->assertOk();

        $this->assertDatabaseHas('audits', ['action' => 'payments.batch_registered']);
    }

    // ============ Reactivación del servicio ============

    public function test_un_contrato_en_presuspension_se_reactiva_al_pagar_en_lote(): void
    {
        $this->openCashRegister();

        $factura = $this->contratoConFactura(50000);
        $otra = $this->contratoConFactura(30000);

        Contract::whereKey($factura->contract_id)->update(['status' => 'Pre-suspensión']);

        $this->postJson(route('payments.storeBatch'), $this->cobro([
            ['invoice_id' => $factura->id, 'amount' => 50000],
            ['invoice_id' => $otra->id, 'amount' => 30000],
        ]))->assertOk();

        // Cobrar en lote no puede saltarse las transiciones del
        // contrato: quien paga queda reactivado igual que siempre.
        $this->assertSame('Activo', Contract::find($factura->contract_id)->status);
    }
}
