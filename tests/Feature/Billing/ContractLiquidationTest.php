<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceType;
use App\Billing\Services\ContractLiquidator;
use App\Billing\Services\InvoiceGenerator;
use App\Models\AditionalCharge;
use App\Models\Contract;
use App\Models\Invoice;

/**
 * La última factura de un contrato que se da de baja.
 *
 * EL PROBLEMA
 * -----------
 * Un contrato retirado deja de ser facturable, y está bien que así
 * sea. Pero las cuotas que le quedaban a un equipo diferido a doce
 * meses se quedaban ahí: no había más facturas donde cobrarlas. El
 * cliente se iba debiendo ese dinero y el sistema no lo reclamaba.
 */
class ContractLiquidationTest extends BillingTestCase
{
    private function cargo(Contract $contrato, array $datos = []): AditionalCharge
    {
        return AditionalCharge::create(array_merge([
            'contract_id' => $contrato->id,
            'user_id' => $this->admin->id,
            'description' => 'Equipo',
            'amount' => 300000,
            'tax_percentage' => 0,
            'installments_total' => 6,
            'status' => 'pendiente',
        ], $datos));
    }

    public function test_liquidar_cobra_todo_lo_que_quedaba_del_diferido(): void
    {
        $contrato = $this->createBillableContract(50000);
        $cargo = $this->cargo($contrato);

        // Dos cuotas ya facturadas en las mensuales.
        $cargo->update(['installments_billed' => 2]);

        $factura = app(ContractLiquidator::class)->liquidar($contrato->fresh(), $this->admin->id);

        $this->assertNotNull($factura);
        $this->assertSame(InvoiceType::Liquidacion->value, $factura->type);
        // 300.000 - 2 cuotas de 50.000 = 200.000, de una vez.
        $this->assertEquals(200000, $factura->total);
        $this->assertSame('Facturado', $cargo->fresh()->status);
    }

    public function test_un_cargo_de_contado_entra_completo(): void
    {
        $contrato = $this->createBillableContract(50000);
        $this->cargo($contrato, [
            'description' => 'Instalación',
            'amount' => 120000,
            'installments_total' => null,
        ]);

        $factura = app(ContractLiquidator::class)->liquidar($contrato->fresh(), $this->admin->id);

        $this->assertEquals(120000, $factura->total);
    }

    public function test_el_iva_del_cargo_viaja_a_la_liquidacion(): void
    {
        $contrato = $this->createBillableContract(50000);
        $this->cargo($contrato, [
            'description' => 'Equipo',
            'amount' => 100000,
            'tax_percentage' => 19,
            'installments_total' => null,
        ]);

        $factura = app(ContractLiquidator::class)->liquidar($contrato->fresh(), $this->admin->id);

        $this->assertEquals(100000, $factura->subtotal);
        $this->assertEquals(19000, $factura->tax);
        $this->assertEquals(119000, $factura->total);
    }

    public function test_sin_cargos_pendientes_no_se_emite_nada(): void
    {
        // Es el caso normal: la mayoría se va sin nada abierto. Emitir
        // una factura vacía gastaría un consecutivo del rango
        // autorizado para nada.
        $contrato = $this->createBillableContract(50000);

        $this->assertNull(app(ContractLiquidator::class)->liquidar($contrato, $this->admin->id));
        $this->assertSame(0, Invoice::where('contract_id', $contrato->id)->count());
    }

    public function test_cerrar_el_retiro_emite_la_liquidacion(): void
    {
        // De punta a punta: es el camino real, y la liquidación tiene
        // que salir ANTES de que el contrato deje de ser facturable.
        $contrato = $this->createBillableContract(50000);
        $this->cargo($contrato, ['installments_total' => null, 'amount' => 90000]);

        $orden = \App\Models\TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->admin->id,
            'created_by' => $this->admin->id,
            'type' => \App\Models\TechnicalOrder::SERVICIO,
            'detail' => 'Retiro de servicio',
            'status' => 'Prefinalizada',
            'initial_comment' => 'Retiro',
        ]);

        app(\App\Billing\Services\ContractStatusFromOrder::class)->aplicar($orden);

        $contrato->refresh();

        $this->assertSame(ContractStatus::Retirado->value, $contrato->status);

        $liquidacion = Invoice::where('contract_id', $contrato->id)
            ->where('type', InvoiceType::Liquidacion->value)
            ->first();

        $this->assertNotNull($liquidacion, 'No se emitió la factura de liquidación al retirar.');
        $this->assertEquals(90000, $liquidacion->total);
    }

    public function test_la_liquidacion_convive_con_la_mensual_del_mismo_mes(): void
    {
        // La mensual ya salió este mes y se llevó UNA cuota; la
        // liquidación cobra las que quedan, y es otro documento que no
        // la estorba (la comprobación de «ya facturado este período»
        // vive en la mensual, no aquí).
        $contrato = $this->createBillableContract(50000);
        $this->cargo($contrato, ['amount' => 300000, 'installments_total' => 6]);

        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        $liquidacion = app(ContractLiquidator::class)->liquidar($contrato->fresh(), $this->admin->id);

        $this->assertNotNull($liquidacion);
        // 300.000 menos la cuota que ya cobró la mensual.
        $this->assertEquals(250000, $liquidacion->total);
        $this->assertSame(2, Invoice::where('contract_id', $contrato->id)->count());
    }
}
