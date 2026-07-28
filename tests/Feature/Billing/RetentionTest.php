<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\RetentionType;
use App\Billing\Services\InvoiceGenerator;
use App\Models\Invoice;
use App\Models\PaymentRetention;

/**
 * Retenciones practicadas por el cliente al pagar.
 *
 * Lo que no puede fallar: que la retención SALDE la factura (el
 * cliente ya pagó, solo que parte se la entregó al Estado) y que al
 * mismo tiempo NO entre a la caja (ese dinero no está en el cajón).
 * Confundir las dos cosas o le suspende el servicio a un cliente al
 * día, o descuadra la caja del punto de cobro.
 */
class RetentionTest extends BillingTestCase
{
    private function facturaDe(float $precio, float $iva = 0): Invoice
    {
        $contrato = $this->createBillableContract(price: $precio, taxPercent: $iva);

        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        return Invoice::where('contract_id', $contrato->id)->firstOrFail();
    }

    // ============ Efecto sobre la factura y sobre la caja ============

    public function test_la_retencion_salda_la_factura_junto_con_el_efectivo(): void
    {
        $this->openCashRegister();

        // Factura de 100.000. El cliente retiene el 4% de renta
        // (4.000) y entrega 96.000 en efectivo.
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

        $factura->refresh();

        // La factura queda saldada: 96.000 + 4.000 = 100.000
        $this->assertEquals(0, (float) $factura->pending_invoice_amount);
        $this->assertSame(InvoiceStatus::Pagada->value, $factura->status);
    }

    public function test_la_retencion_no_entra_a_la_caja(): void
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

        // A la caja entran los 96.000 recibidos, no los 100.000
        // facturados: la retención se la llevó la DIAN.
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $caja->id,
            'amount' => 96000,
        ]);

        $this->assertDatabaseMissing('cash_register_transactions', [
            'cash_register_id' => $caja->id,
            'amount' => 100000,
        ]);

        $this->assertEquals(96000, (float) $caja->fresh()->total_income);
    }

    public function test_se_guardan_base_tarifa_y_concepto_de_cada_retencion(): void
    {
        $this->openCashRegister();

        // Factura con IVA: 100.000 + 19% = 119.000
        $factura = $this->facturaDe(100000, iva: 19);

        // Retefuente 4% sobre el servicio + reteIVA 15% sobre el IVA
        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 119000 - 4000 - 2850,
            'payment_method' => 'Transferencia',
            'retentions' => [
                [
                    'type' => RetentionType::Renta->value,
                    'concept_code' => 'servicios_generales_declarante',
                    'base' => 100000,
                    'rate' => 4,
                    'amount' => 4000,
                    'certificate_number' => 'CERT-2026-118',
                ],
                [
                    'type' => RetentionType::Iva->value,
                    'concept_code' => 'reteiva_general',
                    'base' => 19000,
                    'rate' => 15,
                    'amount' => 2850,
                ],
            ],
        ])->assertOk();

        $retenciones = PaymentRetention::where('invoice_id', $factura->id)->get();

        $this->assertCount(2, $retenciones);

        $renta = $retenciones->firstWhere('type', RetentionType::Renta->value);

        $this->assertEquals(100000, (float) $renta->base);
        $this->assertEquals(4, (float) $renta->rate);
        $this->assertEquals(4000, (float) $renta->amount);
        $this->assertSame('CERT-2026-118', $renta->certificate_number);

        // Se guarda el TEXTO del concepto, no solo su código: si el
        // catálogo cambia, el documento emitido conserva lo que decía.
        $this->assertStringContainsString('Servicios en general', $renta->concept_label);

        $this->assertEquals(0, (float) $factura->fresh()->pending_invoice_amount);
    }

    public function test_un_cobro_totalmente_cubierto_por_retencion_no_genera_movimiento_de_caja(): void
    {
        $caja = $this->openCashRegister();

        $factura = $this->facturaDe(50000);

        // Caso extremo pero legal: el cliente retiene el 100%
        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 0,
            'payment_method' => 'Transferencia',
            'retentions' => [[
                'type' => RetentionType::Iva->value,
                'concept_code' => 'reteiva_no_domiciliados',
                'base' => 50000,
                'rate' => 100,
                'amount' => 50000,
            ]],
        ])->assertOk();

        $this->assertEquals(0, (float) $factura->fresh()->pending_invoice_amount);

        // No entró un peso: no hay movimiento de caja
        $this->assertDatabaseMissing('cash_register_transactions', [
            'cash_register_id' => $caja->id,
        ]);
    }

    // ==================== Validaciones ====================

    public function test_efectivo_mas_retencion_no_puede_superar_el_saldo(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(100000);

        // 98.000 + 4.000 = 102.000 sobre una factura de 100.000
        $respuesta = $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 98000,
            'payment_method' => 'Efectivo',
            'retentions' => [[
                'type' => RetentionType::Renta->value,
                'concept_code' => 'servicios_generales_declarante',
                'base' => 100000,
                'rate' => 4,
                'amount' => 4000,
            ]],
        ])->assertStatus(422);

        $this->assertStringContainsString('excede el saldo pendiente', $respuesta->json('error'));

        // Nada quedó a medias
        $this->assertEquals(100000, (float) $factura->fresh()->pending_invoice_amount);
        $this->assertSame(0, PaymentRetention::count());
    }

    public function test_una_retencion_no_puede_superar_su_propia_base(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(100000);

        $respuesta = $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 40000,
            'payment_method' => 'Efectivo',
            'retentions' => [[
                'type' => RetentionType::Renta->value,
                'concept_code' => 'servicios_generales_declarante',
                'base' => 10000,
                'rate' => 4,
                // Incoherente: 60.000 sobre una base de 10.000
                'amount' => 60000,
            ]],
        ])->assertStatus(422);

        $this->assertStringContainsString('no puede superar su base', $respuesta->json('error'));
        $this->assertSame(0, PaymentRetention::count());
    }

    public function test_el_tipo_de_retencion_debe_ser_uno_de_los_conocidos(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(100000);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 96000,
            'payment_method' => 'Efectivo',
            'retentions' => [[
                'type' => 'retencion_inventada',
                'base' => 100000,
                'rate' => 4,
                'amount' => 4000,
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('retentions.0.type');
    }

    public function test_si_el_pago_se_reversa_su_retencion_deja_de_saldar_la_factura(): void
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
            ]],
        ])->assertOk();

        $this->assertEquals(0, (float) $factura->fresh()->pending_invoice_amount);

        // Se anula el pago
        \App\Models\Payment::where('invoice_id', $factura->id)->firstOrFail()->delete();

        // Vuelve a deberse TODO: los 96.000 del pago anulado y
        // también los 4.000 de su retención. Dejar la retención
        // contando daría por cobrada una plata que ya no existe.
        $this->assertEquals(100000, $factura->fresh()->getPendingAmount());
    }

    // ==================== Trazabilidad y reporte ====================

    public function test_la_retencion_queda_en_la_trazabilidad(): void
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
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('audits', ['action' => 'payments.retention_applied']);
    }

    public function test_el_reporte_de_retenciones_muestra_lo_practicado(): void
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
                'certificate_number' => 'CERT-99',
            ]],
        ])->assertOk();

        $this->get(route('retentions.index'))
            ->assertOk()
            ->assertSee('CERT-99')
            ->assertSee($factura->contract->numero_visible);
    }

    public function test_el_reporte_de_retenciones_se_exporta(): void
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
            ]],
        ])->assertOk();

        $this->get(route('retentions.export'))->assertOk();

        $pdf = $this->get(route('retentions.pdf'))->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $this->assertDatabaseHas('audits', ['action' => 'retentions.exported']);
    }

    // ==================== Catálogo ====================

    public function test_el_catalogo_propone_la_base_correcta_de_cada_impuesto(): void
    {
        // El reteIVA se calcula sobre el IVA, no sobre el servicio:
        // es el error contable más común y el formulario lo previene.
        $this->assertSame('iva', RetentionType::Iva->baseSugerida());
        $this->assertSame('subtotal', RetentionType::Renta->baseSugerida());
        $this->assertSame('subtotal', RetentionType::Ica->baseSugerida());

        // El ICA lo fija cada municipio: no se propone tarifa
        $this->assertEquals(0.0, RetentionType::Ica->tarifa('ica_servicios'));
        $this->assertEquals(4.0, RetentionType::Renta->tarifa('servicios_generales_declarante'));
        $this->assertEquals(15.0, RetentionType::Iva->tarifa('reteiva_general'));
    }
}
