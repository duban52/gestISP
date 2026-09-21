<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\DiscountType;
use App\Billing\Services\InvoiceGenerator;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Service;

/**
 * Descuento del contrato, con vigencia.
 *
 * VA EN LA LÍNEA, Y ESO ES LO QUE SE DEFIENDE AQUÍ
 * ------------------------------------------------
 * Un descuento que solo bajara el total dejaría el IVA calculado sobre
 * la base sin descontar: el cliente pagaría impuesto por dinero que no
 * se le cobró, y la DIAN lo rechaza con FAS07 —el tributo no
 * corresponde a la base por la tarifa—.
 *
 * Y SE AGOTA SOLO
 * ---------------
 * «Dos meses» no puede depender de que alguien se acuerde de entrar a
 * quitarlo el tercero.
 */
class ContractDiscountTest extends BillingTestCase
{
    private function conDescuento(Contract $contrato, array $datos): Contract
    {
        $contrato->update($datos);

        return $contrato->fresh();
    }

    private function facturar(Contract $contrato, ?\Carbon\CarbonInterface $cuando = null): ?Invoice
    {
        $resultado = app(InvoiceGenerator::class)
            ->generateForContract($contrato->fresh(), $cuando ?? now(), $this->admin->id);

        return $resultado['generated'] ? $resultado['invoice'] : null;
    }

    public function test_el_descuento_en_porcentaje_rebaja_la_linea(): void
    {
        $contrato = $this->conDescuento($this->createBillableContract(100000), [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 50,
            'discount_months' => 2,
        ]);

        $factura = $this->facturar($contrato);
        $renglon = $factura->invoice_items()->first();

        // El precio de lista se conserva; el descuento va aparte.
        $this->assertEquals(100000, $renglon->unit_price);
        $this->assertEquals(50000, $renglon->discount);
        $this->assertEquals(50000, $factura->subtotal);
        $this->assertEquals(50000, $factura->discount);
        $this->assertEquals(50000, $factura->total);
    }

    public function test_el_iva_se_calcula_sobre_la_base_ya_descontada(): void
    {
        // Es el punto entero: con IVA sobre el precio de lista, la DIAN
        // rechaza con FAS07.
        $contrato = $this->conDescuento($this->createBillableContract(100000, 19), [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 50,
        ]);

        $factura = $this->facturar($contrato);

        $this->assertEquals(50000, $factura->subtotal);
        $this->assertEquals(9500, $factura->tax);   // 19% de 50.000, no de 100.000
        $this->assertEquals(59500, $factura->total);
    }

    public function test_el_descuento_de_valor_fijo_se_reparte_entre_los_servicios(): void
    {
        $contrato = $this->createBillableContract(60000);

        // Un segundo servicio en el mismo plan.
        $contrato->plan->services()->attach(Service::factory()->create([
            'base_price' => 40000,
            'tax_percentage' => 0,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ])->id);

        $contrato = $this->conDescuento($contrato, [
            'discount_type' => DiscountType::Valor->value,
            'discount_value' => 10000,
        ]);

        $factura = $this->facturar($contrato);

        // 60% y 40% del descuento, y la suma exacta.
        $this->assertEquals(10000, $factura->invoice_items()->sum('discount'));
        $this->assertEquals(10000, $factura->discount);
        $this->assertEquals(90000, $factura->total);
    }

    public function test_el_descuento_no_puede_dejar_la_linea_en_negativo(): void
    {
        $contrato = $this->conDescuento($this->createBillableContract(30000), [
            'discount_type' => DiscountType::Valor->value,
            'discount_value' => 500000,
        ]);

        $factura = $this->facturar($contrato);

        $this->assertEquals(0, $factura->total);
        $this->assertEquals(30000, $factura->discount);
    }

    public function test_el_descuento_se_agota_con_los_meses_pactados(): void
    {
        $contrato = $this->conDescuento($this->createBillableContract(100000), [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 50,
            'discount_months' => 2,
        ]);

        $primera = $this->facturar($contrato, now());
        $segunda = $this->facturar($contrato, now()->addMonthNoOverflow());
        $tercera = $this->facturar($contrato, now()->addMonthsNoOverflow(2));

        $this->assertEquals(50000, $primera->total);
        $this->assertEquals(50000, $segunda->total);
        // Se acabó: la tercera va al precio completo, sin que nadie
        // tenga que acordarse de quitarlo.
        $this->assertEquals(100000, $tercera->total);
        $this->assertSame(2, $contrato->fresh()->discount_applied);
    }

    public function test_sin_meses_declarados_el_descuento_no_caduca(): void
    {
        $contrato = $this->conDescuento($this->createBillableContract(100000), [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 10,
        ]);

        $this->facturar($contrato, now());
        $tercera = $this->facturar($contrato, now()->addMonthsNoOverflow(2));

        $this->assertEquals(90000, $tercera->total);
    }

    public function test_el_descuento_no_toca_los_cargos_adicionales(): void
    {
        // Una promoción sobre la mensualidad no rebaja la instalación.
        $contrato = $this->conDescuento($this->createBillableContract(100000), [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 50,
        ]);

        \App\Models\AditionalCharge::create([
            'contract_id' => $contrato->id,
            'user_id' => $this->admin->id,
            'description' => 'Instalación',
            'amount' => 80000,
            'tax_percentage' => 0,
            'status' => 'pendiente',
        ]);

        $factura = $this->facturar($contrato);
        $cargo = $factura->invoice_items()->where('description', 'Instalación')->firstOrFail();

        $this->assertEquals(0, $cargo->discount);
        $this->assertEquals(130000, $factura->total);  // 50.000 + 80.000
    }

    // ---------------------------------------------------------- pantalla

    public function test_se_aplica_y_se_quita_desde_la_ficha_del_contrato(): void
    {
        $contrato = $this->createBillableContract(100000);

        $this->post(route('contracts.discount', $contrato), [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 25,
            'discount_months' => 3,
            'discount_reason' => 'Promoción de instalación',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($contrato->fresh()->descuentoVigente());

        $this->post(route('contracts.discount', $contrato), ['discount_type' => ''])
            ->assertSessionHasNoErrors();

        $contrato->refresh();
        $this->assertFalse($contrato->descuentoVigente());
        // El contador vuelve a cero: el próximo descuento son sus meses
        // enteros, no los que le quedaran a este.
        $this->assertSame(0, $contrato->discount_applied);
    }

    public function test_no_admite_un_porcentaje_mayor_que_cien(): void
    {
        $contrato = $this->createBillableContract(100000);

        $this->post(route('contracts.discount', $contrato), [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 150,
        ])->assertSessionHas('error');

        $this->assertFalse($contrato->fresh()->descuentoVigente());
    }
}
