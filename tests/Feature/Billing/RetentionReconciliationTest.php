<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\RetentionType;
use App\Billing\Services\InvoiceGenerator;
use App\Models\Invoice;
use App\Reports\BillingReport;
use App\Reports\Enums\Granularity;
use App\Reports\Support\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * Conciliación entre lo facturado y lo cobrado cuando hay retenciones.
 *
 * El punto de todo esto: lo FACTURADO es la cifra que se declara a la
 * DIAN y se reporta a la CRC y a MinTIC, y los tres miden ingreso
 * causado, no caja. La retención no puede disminuirla —la factura se
 * pagó completa, solo cambió quién recibió cada parte—, pero sí tiene
 * que aparecer explicando por qué entró menos efectivo, o la tasa de
 * recaudo y la cartera quedan mal.
 */
class RetentionReconciliationTest extends BillingTestCase
{
    private function periodoDelMes(): ReportPeriod
    {
        return new ReportPeriod(
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth(),
            Granularity::Mes,
        );
    }

    /** Factura de $100.000 cobrada con 4% de retención. */
    private function cobrarConRetencion(): Invoice
    {
        $this->openCashRegister();

        $contrato = $this->createBillableContract(price: 100000, taxPercent: 0);
        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

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

        return $factura->fresh();
    }

    // ============ Lo facturado no se toca ============

    public function test_la_retencion_no_disminuye_lo_facturado(): void
    {
        $this->cobrarConRetencion();

        $resumen = (new BillingReport($this->periodoDelMes(), $this->branch->id))->resumen();

        // Los $100.000 facturados siguen siendo $100.000. Es la base
        // de la declaración de renta e IVA, del reporte de ingresos a
        // la CRC y de la contraprestación de MinTIC: restarle la
        // retención sería subreportar a los tres.
        $this->assertEquals(100000, $resumen['facturado']);
    }

    public function test_la_conciliacion_separa_lo_recaudado_de_lo_retenido(): void
    {
        $this->cobrarConRetencion();

        $resumen = (new BillingReport($this->periodoDelMes(), $this->branch->id))->resumen();

        $this->assertEquals(96000, $resumen['recaudado'], 'A la caja solo entraron $96.000');
        $this->assertEquals(4000, $resumen['retenido']);
        $this->assertEquals(100000, $resumen['cancelado'], 'El cliente sí canceló los $100.000');
    }

    public function test_la_tasa_de_recaudo_no_castiga_al_cliente_que_retuvo(): void
    {
        $this->cobrarConRetencion();

        $resumen = (new BillingReport($this->periodoDelMes(), $this->branch->id))->resumen();

        // Antes se calculaba sobre el efectivo y este cliente, que
        // pagó el 100%, aparecía con 96% de recaudo.
        $this->assertEquals(100.0, $resumen['tasa_recaudo']);
    }

    public function test_lo_retenido_no_queda_como_cartera(): void
    {
        $this->cobrarConRetencion();

        $resumen = (new BillingReport($this->periodoDelMes(), $this->branch->id))->resumen();

        // La factura quedó saldada: no debe nada
        $this->assertEquals(0, $resumen['cartera']);
        $this->assertEquals(0, $resumen['cartera_vencida']);
    }

    public function test_sin_retenciones_la_conciliacion_queda_en_cero(): void
    {
        $this->openCashRegister();

        $contrato = $this->createBillableContract(price: 80000, taxPercent: 0);
        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 80000,
            'payment_method' => 'Efectivo',
        ])->assertOk();

        $resumen = (new BillingReport($this->periodoDelMes(), $this->branch->id))->resumen();

        $this->assertEquals(0, $resumen['retenido']);
        $this->assertEquals($resumen['recaudado'], $resumen['cancelado']);
        $this->assertEquals(100.0, $resumen['tasa_recaudo']);
    }

    // ==================== Pantallas ====================

    public function test_el_informe_de_facturacion_muestra_la_conciliacion(): void
    {
        $this->cobrarConRetencion();

        $this->get(route('reports.billing'))
            ->assertOk()
            ->assertSee('Conciliación de lo cobrado')
            ->assertSee('Retenido por los clientes')
            ->assertSee('Cancelado por los clientes');
    }

    public function test_el_cuadre_de_cajas_muestra_lo_retenido_aparte(): void
    {
        $this->cobrarConRetencion();

        $respuesta = $this->get(route('cash_register.summary'))->assertOk();

        // Aparece, pero fuera del cuadre: ese dinero nunca estuvo en
        // el cajón.
        $respuesta->assertSee('no hacen parte del cuadre');
        $respuesta->assertSee('Retención en la fuente (renta)');

        // El total de la caja sigue siendo solo el efectivo
        $this->assertEquals(96000, (float) $respuesta->viewData('totals')['income']);
        $this->assertEquals(4000, $respuesta->viewData('retentionTotals')['total']);
    }

    public function test_el_estado_de_cuenta_del_contrato_muestra_lo_retenido(): void
    {
        $factura = $this->cobrarConRetencion();

        $this->get(route('contracts.show', $factura->contract_id))
            ->assertOk()
            // Sin esta línea la factura aparece pagada con un pago
            // menor al total y parece un descuadre.
            ->assertSee('Retenido');
    }

    public function test_el_pdf_del_informe_incluye_la_conciliacion(): void
    {
        $this->cobrarConRetencion();

        $pdf = $this->get(route('reports.billing.pdf'))->assertOk();

        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }
}
