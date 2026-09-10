<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\InvoiceXmlBuilder;
use App\Billing\Enums\InvoiceStatus;
use App\Models\Invoice;

/**
 * El saldo anterior: lo que el cliente arrastra de facturas viejas.
 *
 * EL ERROR QUE HABÍA
 * ------------------
 * La representación gráfica imprimía `pending_invoice_amount` bajo la
 * etiqueta «SALDO ANTERIOR». Pero esa columna NO es el saldo anterior:
 * es el saldo de la propia factura —total menos lo pagado—.
 *
 * El efecto era el peor posible para cobrar: un cliente con un mes
 * vencido veía «TOTAL A PAGAR» con el importe del mes solamente, y
 * pagándolo creía quedar al día.
 *
 * LO QUE NO PUEDE CAMBIAR
 * -----------------------
 * El XML de la DIAN. El documento fiscal es ESTA venta; el saldo
 * anterior es cobranza. Sumarlo al `PayableAmount` sería declararle a la
 * DIAN un importe que no corresponde a lo vendido este mes.
 */
class PreviousBalanceTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Dos facturas del mismo contrato: la vieja queda sin pagar. */
    private function contratoConDeuda(): array
    {
        $contrato = $this->contratoElectronico(precio: 100000, iva: 0);

        $vieja = $this->emitir($contrato);
        $vieja->forceFill([
            'status' => InvoiceStatus::Vencida->value,
            'pending_invoice_amount' => $vieja->total,
        ])->save();

        // La siguiente del mismo contrato, un mes después.
        $nueva = app(\App\Billing\Services\InvoiceGenerator::class)->generateForContract(
            $contrato->fresh(),
            now()->addMonth(),
            $this->admin->id,
        )['invoice'];

        return [$vieja, $nueva];
    }

    public function test_el_saldo_anterior_son_las_facturas_viejas_sin_pagar(): void
    {
        [$vieja, $nueva] = $this->contratoConDeuda();

        $this->assertEqualsWithDelta((float) $vieja->total, $nueva->saldoAnterior(), 0.01);
    }

    public function test_el_total_a_pagar_suma_lo_que_arrastra(): void
    {
        // Es la cifra que de verdad hay que cobrarle hoy.
        [$vieja, $nueva] = $this->contratoConDeuda();

        $this->assertEqualsWithDelta(
            (float) $nueva->total + (float) $vieja->total,
            $nueva->totalAPagar(),
            0.01,
        );
    }

    public function test_una_factura_sin_deuda_previa_no_arrastra_nada(): void
    {
        $factura = $this->emitir($this->contratoElectronico(precio: 100000, iva: 0));

        $this->assertSame(0.0, $factura->saldoAnterior());
        $this->assertEqualsWithDelta((float) $factura->total, $factura->totalAPagar(), 0.01);
    }

    public function test_lo_anulado_no_cuenta_como_deuda(): void
    {
        // Una factura anulada no se cobra. Contarla haría reclamarle al
        // cliente algo que el propio sistema dio por no emitido.
        [$vieja, $nueva] = $this->contratoConDeuda();

        $vieja->forceFill(['status' => InvoiceStatus::Anulada->value])->save();

        $this->assertSame(0.0, $nueva->fresh()->saldoAnterior());
    }

    public function test_lo_pagado_deja_de_arrastrarse(): void
    {
        [$vieja, $nueva] = $this->contratoConDeuda();

        $vieja->forceFill([
            'status' => InvoiceStatus::Pagada->value,
            'pending_invoice_amount' => 0,
        ])->save();

        $this->assertSame(0.0, $nueva->fresh()->saldoAnterior());
    }

    public function test_el_saldo_anterior_no_entra_en_el_xml_de_la_dian(): void
    {
        // LA PARTE QUE NO PUEDE CAMBIAR. El documento fiscal es esta
        // venta: declararle a la DIAN el saldo arrastrado seria
        // declararle un importe que no corresponde a lo vendido.
        [$vieja, $nueva] = $this->contratoConDeuda();

        $this->assertGreaterThan(0, $nueva->saldoAnterior());

        $xml = app(InvoiceXmlBuilder::class)->construir(
            $nueva->load('invoice_items', 'contract.client.taxResponsibilities'),
            $this->rango->fresh(),
            $this->configuracion->fresh(),
        )['xml'];

        $this->assertStringContainsString(
            '<cbc:PayableAmount currencyID="COP">' . number_format((float) $nueva->total, 2, '.', '') . '<',
            $xml,
        );

        $this->assertStringNotContainsString(
            number_format($nueva->totalAPagar(), 2, '.', '') . '</cbc:PayableAmount>',
            $xml,
        );
    }

    public function test_la_representacion_grafica_ensena_las_tres_cifras(): void
    {
        [$vieja, $nueva] = $this->contratoConDeuda();

        $html = view('gestisp.invoices.pdf', [
            'invoice' => $nueva->load(['contract.client', 'contract.branch', 'invoice_items']),
            'barcodeUrl' => '',
            'codeString' => '0100',
            'dian' => null,
            'logo' => null,
        ])->render();

        $this->assertStringContainsString('SALDO ANTERIOR', $html);
        $this->assertStringContainsString(number_format((float) $vieja->total, 2, '.', ''), $html);
        $this->assertStringContainsString(number_format($nueva->totalAPagar(), 2, '.', ''), $html);
    }
}
