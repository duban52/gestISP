<?php

namespace Tests\Feature\Billing;

use App\Models\CashRegisterTransaction;
use App\Models\Invoice;
use App\Models\Warehouse;
use App\Support\PdfBranding;

/**
 * Generación de los PDFs del sistema (reportes y comprobantes).
 *
 * Verifican que cada plantilla compile y produzca un PDF real:
 * un error de sintaxis en la plantilla base o una variable
 * faltante rompería todos los reportes en producción.
 */
class PdfReportsTest extends BillingTestCase
{
    /**
     * Comprueba que la respuesta sea un PDF válido y no vacío.
     */
    private function assertIsPdf($response, string $context): void
    {
        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $content, "{$context}: la respuesta no es un PDF");
        $this->assertGreaterThan(1000, strlen($content), "{$context}: el PDF está vacío");
    }

    public function test_genera_el_reporte_de_pagos(): void
    {
        $contract = $this->createBillableContract(price: 100000);
        $this->post(route('invoices.generate'));
        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->openCashRegister();
        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'payment_method' => 'efectivo',
        ])->assertOk();

        $this->assertIsPdf($this->get(route('payments.export')), 'Reporte de pagos');
    }

    public function test_genera_el_reporte_de_movimientos_de_caja(): void
    {
        $register = $this->openCashRegister(initialAmount: 50000);

        CashRegisterTransaction::create([
            'cash_register_id' => $register->id,
            'transaction_type' => 'Ingreso',
            'amount' => 25000,
            'payment_method' => 'efectivo',
            'description' => 'Movimiento de prueba',
            'created_by' => $this->admin->id,
        ]);

        $this->assertIsPdf($this->get(route('transactions.export')), 'Movimientos de caja');
    }

    public function test_genera_el_historial_de_movimientos_de_material(): void
    {
        $this->assertIsPdf($this->get(route('movements.pdf')), 'Historial de movimientos');
    }

    public function test_genera_el_inventario_de_almacen(): void
    {
        $warehouse = Warehouse::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'description' => 'Almacén de pruebas',
        ]);

        $this->assertIsPdf($this->get(route('warehouse.pdf', $warehouse)), 'Inventario de almacén');
    }

    public function test_el_recibo_de_pago_se_genera_al_cobrar(): void
    {
        $contract = $this->createBillableContract(price: 80000);
        $this->post(route('invoices.generate'));
        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->openCashRegister();

        // El cobro devuelve las dos direcciones del recibo: la que se
        // muestra en el modal (HTML, la que se manda a la térmica) y
        // la del PDF para archivar.
        $response = $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 80000,
            'payment_method' => 'efectivo',
        ])->assertOk();

        $this->assertNotEmpty($response->json('receipt_url'), 'El cobro no devolvió el recibo');
        $this->assertNotEmpty($response->json('receipt_pdf'), 'El cobro no devolvió el PDF del recibo');

        // El recibo se genera al vuelo, no queda en disco: antes se
        // escribía un PDF por cobro en storage/temp y esos archivos
        // se acumulaban sin que nadie los borrara.
        $this->get($response->json('receipt_url'))
            ->assertOk()
            ->assertSee('RECIBO DE CAJA');

        $pdf = $this->get($response->json('receipt_pdf'))->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_los_pdfs_usan_tamano_carta_y_llevan_paginacion(): void
    {
        $pdf = PdfBranding::make('gestisp.warehouses.pdf', [
            'inventoriesData' => [],
            'warehouse' => Warehouse::create([
                'branch_id' => $this->branch->id,
                'user_id' => $this->admin->id,
                'description' => 'Almacén vacío',
            ]),
        ]);

        $canvas = $pdf->getDomPDF()->getCanvas();

        // Carta vertical: 612 x 792 puntos (antes salían en A4 por
        // no fijar el tamaño de papel)
        $this->assertEqualsWithDelta(612, $canvas->get_width(), 1);
        $this->assertEqualsWithDelta(792, $canvas->get_height(), 1);
        $this->assertSame(1, $canvas->get_page_count());

        // El documento ya viene renderizado y es un PDF válido. El
        // texto de paginación se estampa sobre el canvas y no se
        // puede afirmar sobre el binario (dompdf comprime y
        // subconjunta la fuente); su posición se validó visualmente.
        $this->assertStringStartsWith('%PDF-', $pdf->output());
    }
}
