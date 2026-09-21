<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\TaxClassification;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\InvoiceVoider;
use App\Models\AditionalCharge;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;

/**
 * El IVA de los cargos adicionales y la reversa al anular.
 *
 * DOS DEFECTOS QUE COSTABAN DINERO
 * --------------------------------
 * 1. El generador escribía `percentage_tax = 0` en todo cargo, porque
 *    el cargo no tenía dónde guardar una tarifa. Una reconexión o un
 *    equipo —gravados al 19%— se declaraban a la DIAN como excluidos.
 *
 * 2. Al facturar, el cargo quedaba «Facturado». Si luego se anulaba
 *    esa factura, nadie lo devolvía: el cargo seguía marcado como
 *    cobrado por una factura que ya no cobraba nada, y no volvía a
 *    entrar en ninguna. Ingreso perdido en silencio.
 */
class ChargeTaxAndVoidTest extends BillingTestCase
{
    private function cargo(Contract $contrato, array $datos = []): AditionalCharge
    {
        return AditionalCharge::create(array_merge([
            'contract_id' => $contrato->id,
            'user_id' => $this->admin->id,
            'description' => 'Reconexión',
            'amount' => 50000,
            'tax_percentage' => 19,
            'status' => 'pendiente',
        ], $datos));
    }

    private function facturar(Contract $contrato): Invoice
    {
        $resultado = app(InvoiceGenerator::class)
            ->generateForContract($contrato->fresh(), now(), $this->admin->id);

        $this->assertTrue($resultado['generated'], 'La factura no se generó.');

        return $resultado['invoice'];
    }

    // ---------------------------------------------------------- IVA

    public function test_el_cargo_con_iva_lo_lleva_en_su_renglon(): void
    {
        $contrato = $this->createBillableContract(0.01);
        $this->cargo($contrato);

        $factura = $this->facturar($contrato);
        $renglon = $factura->invoice_items()->where('description', 'Reconexión')->firstOrFail();

        $this->assertEquals(50000, $renglon->unit_price);
        $this->assertEquals(19, $renglon->percentage_tax);
        $this->assertEquals(9500, $renglon->tax);
        $this->assertSame(TaxClassification::Gravado->value, $renglon->tax_classification);
    }

    public function test_el_iva_del_cargo_entra_en_los_totales_y_cierran(): void
    {
        // Es la ecuación que mira la DIAN en FAU14: bruto + tributos =
        // valor a pagar. Si el IVA del cargo no entrara al total, la
        // factura se contradiría a sí misma.
        $contrato = $this->createBillableContract(100000, 19);
        $this->cargo($contrato);

        $factura = $this->facturar($contrato);

        $this->assertEquals(150000, $factura->subtotal);
        $this->assertEquals(28500, $factura->tax);
        $this->assertEquals(178500, $factura->total);
        $this->assertEquals(
            round($factura->subtotal + $factura->tax, 2),
            round($factura->total, 2),
        );
    }

    public function test_un_cargo_sin_iva_sigue_saliendo_excluido(): void
    {
        // El histórico se emitió así y no se reescribe hacia atrás.
        $contrato = $this->createBillableContract(0.01);
        $this->cargo($contrato, ['description' => 'Traslado', 'tax_percentage' => 0]);

        $renglon = $this->facturar($contrato)
            ->invoice_items()->where('description', 'Traslado')->firstOrFail();

        $this->assertEquals(0, $renglon->tax);
        $this->assertSame(TaxClassification::Excluido->value, $renglon->tax_classification);
    }

    public function test_la_cuota_diferida_tambien_lleva_su_iva(): void
    {
        $contrato = $this->createBillableContract(0.01);
        $this->cargo($contrato, [
            'description' => 'Equipo',
            'amount' => 300000,
            'installments_total' => 3,
        ]);

        $renglon = $this->facturar($contrato)
            ->invoice_items()->where('description', 'like', 'Equipo%')->firstOrFail();

        $this->assertSame('Equipo (cuota 1/3)', $renglon->description);
        $this->assertEquals(100000, $renglon->unit_price);
        $this->assertEquals(19000, $renglon->tax);
    }

    // ------------------------------------------------------- anulación

    public function test_anular_la_factura_devuelve_el_cargo_de_contado(): void
    {
        $contrato = $this->createBillableContract(0.01);
        $cargo = $this->cargo($contrato);

        $factura = $this->facturar($contrato);
        $this->assertSame('Facturado', $cargo->fresh()->status);

        app(InvoiceVoider::class)->void($factura, 'Error de digitación', $this->admin->id);

        $this->assertSame('pendiente', $cargo->fresh()->status);
    }

    public function test_anular_la_factura_devuelve_la_cuota_del_diferido(): void
    {
        $contrato = $this->createBillableContract(0.01);
        $cargo = $this->cargo($contrato, [
            'description' => 'Equipo',
            'amount' => 300000,
            'installments_total' => 3,
        ]);

        $factura = $this->facturar($contrato);
        $this->assertSame(1, $cargo->fresh()->installments_billed);

        app(InvoiceVoider::class)->void($factura, 'Error de digitación', $this->admin->id);

        $cargo->refresh();
        $this->assertSame(0, $cargo->installments_billed);
        $this->assertSame('pendiente', $cargo->status);
    }

    public function test_el_cargo_devuelto_vuelve_a_entrar_en_la_siguiente_factura(): void
    {
        // Es la razón de ser de la reversa: que el dinero no se pierda.
        $contrato = $this->createBillableContract(0.01);
        $cargo = $this->cargo($contrato);

        $primera = $this->facturar($contrato);
        app(InvoiceVoider::class)->void($primera, 'Error de digitación', $this->admin->id);

        // Otro período: el generador no factura dos veces el mismo mes.
        $segunda = app(InvoiceGenerator::class)
            ->generateForContract($contrato->fresh(), now()->addMonthNoOverflow(), $this->admin->id);

        $this->assertTrue($segunda['generated']);
        $this->assertTrue(
            $segunda['invoice']->invoice_items()->where('description', 'Reconexión')->exists(),
            'El cargo devuelto no volvió a facturarse.',
        );
    }

    public function test_anular_no_toca_los_renglones_de_servicios(): void
    {
        $contrato = $this->createBillableContract(100000);

        $factura = $this->facturar($contrato);

        $this->assertSame(
            0,
            InvoiceItem::where('invoice_id', $factura->id)->whereNotNull('aditional_charge_id')->count(),
        );

        app(InvoiceVoider::class)->void($factura, 'Error', $this->admin->id);

        $this->assertSame('Anulada', $factura->fresh()->status);
    }
}
