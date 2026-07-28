<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\RetentionType;
use App\Billing\Services\InvoiceGenerator;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Support\PaymentReceipt;

/**
 * Recibo de caja en tirilla térmica.
 *
 * Lo que se comprueba: que salga el detalle de lo facturado (no solo
 * el total), que el papel sea de 80 mm, y —lo más fácil de romper—
 * que un cobro múltiple produzca UN recibo por contrato: quien pagó
 * por su mamá, su hermana y su abuela tiene que salir con tres
 * tirillas, no con una.
 */
class PaymentReceiptTest extends BillingTestCase
{
    /** 80 mm en puntos: es el ancho del rollo estándar. */
    private const ANCHO_80MM = 226.77;

    private function facturaDe(float $precio, float $iva = 0): Invoice
    {
        $contrato = $this->createBillableContract(price: $precio, taxPercent: $iva);

        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        return Invoice::where('contract_id', $contrato->id)->firstOrFail();
    }

    private function cobrar(Invoice $factura, float $monto, array $retenciones = []): Payment
    {
        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => $monto,
            'payment_method' => 'Efectivo',
            'retentions' => $retenciones,
        ])->assertOk();

        return Payment::where('invoice_id', $factura->id)->latest('id')->firstOrFail();
    }

    // ==================== Contenido ====================

    public function test_el_recibo_trae_el_detalle_de_lo_facturado(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(80000);
        $pago = $this->cobrar($factura, 80000);

        $html = $this->get(route('payments.receipt', $pago))->assertOk()->getContent();

        // El número de contrato, no el id interno
        $this->assertStringContainsString($factura->contract->numero_visible, $html);

        // El desglose de la factura, no solo el total
        foreach ($factura->invoice_items as $item) {
            $this->assertStringContainsString($item->description, $html);
        }

        $this->assertStringContainsString('RECIBO DE CAJA', $html);
        $this->assertStringContainsString('TOTAL RECIBIDO', $html);
        $this->assertStringContainsString('DESCRIPCIÓN', $html);
    }

    public function test_el_recibo_identifica_al_cliente_y_al_cajero(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(60000);
        $pago = $this->cobrar($factura, 60000);

        $html = $this->get(route('payments.receipt', $pago))->assertOk()->getContent();

        $cliente = $factura->contract->client;

        $this->assertStringContainsString($cliente->name, $html);
        $this->assertStringContainsString($cliente->identity_number, $html);
        $this->assertStringContainsString($this->admin->name, $html);
    }

    public function test_un_abono_parcial_muestra_lo_que_queda_pendiente(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(100000);
        $pago = $this->cobrar($factura, 40000);

        $html = $this->get(route('payments.receipt', $pago))->assertOk()->getContent();

        $this->assertStringContainsString('Abono', $html);
        $this->assertStringContainsString('Queda pendiente', $html);
        $this->assertStringContainsString('60.000', $html);
    }

    public function test_el_recibo_muestra_las_retenciones_practicadas(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(100000);

        $pago = $this->cobrar($factura, 96000, [[
            'type' => RetentionType::Renta->value,
            'concept_code' => 'servicios_generales_declarante',
            'base' => 100000,
            'rate' => 4,
            'amount' => 4000,
            'certificate_number' => 'CERT-77',
        ]]);

        $html = $this->get(route('payments.receipt', $pago))->assertOk()->getContent();

        // El cliente tiene que ver que su retención se aplicó, y
        // nosotros que el faltante en efectivo tiene explicación.
        $this->assertStringContainsString('RETENCIONES PRACTICADAS', $html);
        $this->assertStringContainsString('RteFuente', $html);
        $this->assertStringContainsString('CERT-77', $html);
        $this->assertStringContainsString('Valor cancelado', $html);
    }

    // ==================== Formato ====================

    public function test_el_pdf_sale_en_papel_de_80_milimetros(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(70000);
        $pago = $this->cobrar($factura, 70000);

        $respuesta = $this->get(route('payments.receipt.pdf', $pago))->assertOk();

        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());

        // El ancho del papel es lo que hace que la tirilla salga bien
        // en una térmica; si alguien lo cambia a carta, esto avisa.
        $pdf = PaymentReceipt::pdf(PaymentReceipt::build(collect([$pago])));

        $this->assertEqualsWithDelta(
            self::ANCHO_80MM,
            $pdf->getDomPDF()->getCanvas()->get_width(),
            1,
        );
    }

    public function test_descargar_el_recibo_queda_en_la_trazabilidad(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(50000);
        $pago = $this->cobrar($factura, 50000);

        $this->get(route('payments.receipt.pdf', $pago))->assertOk();

        $this->assertDatabaseHas('audits', ['action' => 'payments.receipt_downloaded']);
    }

    // ============ Un recibo por contrato ============

    public function test_un_cobro_multiple_emite_un_recibo_por_cada_contrato(): void
    {
        $this->openCashRegister();

        $mama = $this->facturaDe(50000);
        $hermana = $this->facturaDe(60000);
        $abuela = $this->facturaDe(45000);

        $this->postJson(route('payments.storeBatch'), [
            'payment_method' => 'Efectivo',
            'payer_name' => 'María Restrepo',
            'items' => [
                ['invoice_id' => $mama->id, 'amount' => 50000],
                ['invoice_id' => $hermana->id, 'amount' => 60000],
                ['invoice_id' => $abuela->id, 'amount' => 45000],
            ],
        ])->assertOk();

        $lote = PaymentBatch::firstOrFail();
        $recibos = PaymentReceipt::build($lote->payments()->get());

        // Tres contratos, tres recibos: cada titular tiene derecho al
        // suyo aunque hayan pagado juntos.
        $this->assertCount(3, $recibos);

        $html = $this->get(route('payments.receipt.batch', $lote))->assertOk()->getContent();

        foreach ([$mama, $hermana, $abuela] as $factura) {
            $this->assertStringContainsString($factura->contract->numero_visible, $html);
        }

        // Y consta quién entregó el dinero, que no es ninguno de ellos
        $this->assertStringContainsString('María Restrepo', $html);
    }

    public function test_varias_facturas_del_mismo_contrato_van_en_un_solo_recibo(): void
    {
        $this->openCashRegister();

        // Dos meses atrasados del mismo cliente
        $contrato = $this->createBillableContract(price: 40000, taxPercent: 0);
        $generador = app(InvoiceGenerator::class);

        $generador->generateForContract($contrato, now(), $this->admin->id);
        $generador->generateForContract($contrato, now()->addMonthNoOverflow(), $this->admin->id);

        $facturas = Invoice::where('contract_id', $contrato->id)->orderBy('id')->get();

        $this->postJson(route('payments.storeBatch'), [
            'payment_method' => 'Efectivo',
            'items' => $facturas->map(fn ($f) => ['invoice_id' => $f->id, 'amount' => 40000])->all(),
        ])->assertOk();

        $lote = PaymentBatch::firstOrFail();
        $recibos = PaymentReceipt::build($lote->payments()->get());

        // UN recibo con las dos facturas, no dos tirillas: así lo
        // espera el cliente que paga sus meses atrasados.
        $this->assertCount(1, $recibos);
        $this->assertCount(2, $recibos[0]['lineas']);
        $this->assertEquals(80000, $recibos[0]['total_efectivo']);

        $html = $this->get(route('payments.receipt.batch', $lote))->assertOk()->getContent();

        foreach ($facturas as $factura) {
            $this->assertStringContainsString($factura->displayNumber(), $html);
        }
    }

    public function test_el_recibo_de_un_pago_del_lote_incluye_los_del_mismo_contrato(): void
    {
        $this->openCashRegister();

        $contrato = $this->createBillableContract(price: 40000, taxPercent: 0);
        $generador = app(InvoiceGenerator::class);

        $generador->generateForContract($contrato, now(), $this->admin->id);
        $generador->generateForContract($contrato, now()->addMonthNoOverflow(), $this->admin->id);

        $facturas = Invoice::where('contract_id', $contrato->id)->orderBy('id')->get();
        $ajena = $this->facturaDe(25000);

        $this->postJson(route('payments.storeBatch'), [
            'payment_method' => 'Efectivo',
            'items' => array_merge(
                $facturas->map(fn ($f) => ['invoice_id' => $f->id, 'amount' => 40000])->all(),
                [['invoice_id' => $ajena->id, 'amount' => 25000]],
            ),
        ])->assertOk();

        $primerPago = Payment::where('invoice_id', $facturas[0]->id)->firstOrFail();

        $html = $this->get(route('payments.receipt', $primerPago))->assertOk()->getContent();

        // Abrir el recibo de UN pago del lote trae las dos facturas de
        // ese contrato...
        foreach ($facturas as $factura) {
            $this->assertStringContainsString($factura->displayNumber(), $html);
        }

        // ...pero no la del contrato ajeno, que tiene su propio recibo
        $this->assertStringNotContainsString($ajena->displayNumber(), $html);
    }

    public function test_el_recibo_del_lote_se_descarga_en_un_solo_pdf(): void
    {
        $this->openCashRegister();

        $uno = $this->facturaDe(50000);
        $dos = $this->facturaDe(60000);

        $this->postJson(route('payments.storeBatch'), [
            'payment_method' => 'Efectivo',
            'items' => [
                ['invoice_id' => $uno->id, 'amount' => 50000],
                ['invoice_id' => $dos->id, 'amount' => 60000],
            ],
        ])->assertOk();

        $lote = PaymentBatch::firstOrFail();

        $respuesta = $this->get(route('payments.receipt.batch.pdf', $lote))->assertOk();

        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());
    }

    // ==================== Permisos ====================

    public function test_ver_el_recibo_exige_permiso(): void
    {
        $this->openCashRegister();

        $factura = $this->facturaDe(50000);
        $pago = $this->cobrar($factura, 50000);

        // Un usuario sin el permiso no puede ver comprobantes de caja
        $sinPermiso = $this->createSuperadmin($this->branch);
        $sinPermiso->roles()->first()->revokePermissionTo('payments.receipt');

        $this->actingAs($sinPermiso)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $sinPermiso->roles()->first()->id,
        ]);

        $this->get(route('payments.receipt', $pago))->assertStatus(403);
    }
}
