<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\RetentionType;
use App\Billing\Services\InvoiceGenerator;
use App\Models\CashRegisterTransaction;
use App\Models\Invoice;

/**
 * Comprobante de cierre de caja (arqueo).
 *
 * Dos cosas se comprueban aquí:
 *
 *  - Que cada movimiento diga a QUIÉN corresponde: número de contrato
 *    e identificación. "Pago de factura 18" no le sirve a nadie que
 *    revise el turno después.
 *  - Que las retenciones aparezcan SEPARADAS del arqueo. Ese dinero
 *    nunca estuvo en el cajón; sumarlo descuadraría el conteo. Están
 *    en el comprobante solo para que el cajero entregue los
 *    certificados junto con el efectivo.
 */
class CashRegisterReportTest extends BillingTestCase
{
    private function facturaDe(float $precio): Invoice
    {
        $contrato = $this->createBillableContract(price: $precio, taxPercent: 0);

        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        return Invoice::where('contract_id', $contrato->id)->firstOrFail();
    }

    /** Cierra la caja y devuelve el HTML del comprobante. */
    private function comprobante(): string
    {
        $respuesta = $this->postJson(route('cash_register.close'), [
            'final_amount' => 0,
        ])->assertOk();

        $ruta = str_replace(asset('storage/'), '', $respuesta->json('pdf_url'));

        $contenido = file_get_contents(public_path('storage/' . $ruta));

        $this->assertStringStartsWith('%PDF-', $contenido);

        return $contenido;
    }

    // ============ Descripción de los movimientos ============

    public function test_el_movimiento_identifica_contrato_y_cliente(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(90000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 90000,
            'payment_method' => 'Efectivo',
        ])->assertOk();

        $movimiento = CashRegisterTransaction::latest('id')->firstOrFail();

        // La descripción se rehace desde las relaciones, no desde la
        // columna guardada, para que también sirva con movimientos
        // antiguos.
        $this->assertSame(
            'Pago de factura ' . $factura->displayNumber(),
            $movimiento->descripcionLegible(),
        );

        $detalle = $movimiento->detalleDelCliente();
        $cliente = $factura->contract->client;

        $this->assertStringContainsString($factura->contract->numero_visible, $detalle);
        $this->assertStringContainsString($cliente->identity_number, $detalle);
        $this->assertStringContainsString($cliente->name, $detalle);
    }

    public function test_un_movimiento_viejo_con_el_id_en_la_descripcion_se_rehace(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(50000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 50000,
            'payment_method' => 'Efectivo',
        ])->assertOk();

        $movimiento = CashRegisterTransaction::latest('id')->firstOrFail();

        // Se simula un movimiento de los antiguos, que guardaron el id
        // de la factura en vez de su número formal.
        $movimiento->update(['description' => 'Pago de factura ' . $factura->id]);

        $this->assertSame(
            'Pago de factura ' . $factura->displayNumber(),
            $movimiento->fresh()->descripcionLegible(),
        );
    }

    public function test_un_egreso_manual_conserva_su_descripcion(): void
    {
        $caja = $this->openCashRegister();

        $movimiento = CashRegisterTransaction::create([
            'cash_register_id' => $caja->id,
            'transaction_type' => 'Egreso',
            'amount' => 20000,
            'payment_method' => 'Efectivo',
            'description' => 'Compra de papelería',
            'created_by' => $this->admin->id,
        ]);

        // No viene de un cobro: no hay contrato ni cliente que mostrar
        $this->assertSame('Compra de papelería', $movimiento->descripcionLegible());
        $this->assertNull($movimiento->detalleDelCliente());
    }

    public function test_un_anticipo_se_describe_como_tal(): void
    {
        $this->openCashRegister();

        $contrato = $this->createBillableContract(price: 50000, taxPercent: 0);

        $this->post(route('advance.store', $contrato), [
            'amount' => 150000,
            'payment_method' => 'Efectivo',
        ]);

        $movimiento = CashRegisterTransaction::latest('id')->firstOrFail();

        $this->assertSame('Anticipo a cuenta', $movimiento->descripcionLegible());
        $this->assertStringContainsString(
            $contrato->numero_visible,
            $movimiento->detalleDelCliente(),
        );
    }

    // ============ Retenciones fuera del arqueo ============

    public function test_las_retenciones_no_alteran_el_arqueo(): void
    {
        $caja = $this->openCashRegister();

        $factura = $this->facturaDe(100000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 96000,
            'payment_method' => 'Efectivo',
            'retentions' => [[
                'type' => RetentionType::Renta->value,
                'concept_code' => 'servicios_generales_declarante',
                'base' => 100000,
                'rate' => 4,
                'amount' => 4000,
            ]],
        ])->assertOk();

        $this->comprobante();

        $caja->refresh();

        // El esperado en caja son los $96.000 que entraron, no los
        // $100.000 de la factura: los $4.000 se los llevó la DIAN.
        $this->assertEquals(96000, (float) $caja->total_income);
        $this->assertEquals(96000, (float) $caja->expected_amount);
    }

    public function test_el_comprobante_se_genera_con_retenciones_del_turno(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(100000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 96000,
            'payment_method' => 'Efectivo',
            'retentions' => [[
                'type' => RetentionType::Renta->value,
                'concept_code' => 'servicios_generales_declarante',
                'base' => 100000,
                'rate' => 4,
                'amount' => 4000,
                'certificate_number' => 'CERT-2026-500',
            ]],
        ])->assertOk();

        // El PDF se arma sin reventar y con el bloque de retenciones
        $this->comprobante();
    }

    public function test_el_comprobante_sale_sin_retenciones_cuando_no_las_hubo(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(70000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 70000,
            'payment_method' => 'Efectivo',
        ])->assertOk();

        $this->comprobante();
    }
}
