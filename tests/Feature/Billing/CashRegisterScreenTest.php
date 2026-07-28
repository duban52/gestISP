<?php

namespace Tests\Feature\Billing;

use App\Billing\Services\InvoiceGenerator;
use App\Models\CashRegister;
use App\Models\Invoice;

/**
 * Pantalla de gestión de caja.
 *
 * Tiene dos estados muy distintos y lo que se comprueba es que cada
 * uno muestre lo que el cajero necesita:
 *
 *  - Sin caja abierta: el formulario de apertura y la advertencia.
 *  - Con caja abierta: las cifras del turno y —lo que evita el
 *    descuadre— la separación entre lo que está en el cajón y lo que
 *    llegó por transferencia.
 */
class CashRegisterScreenTest extends BillingTestCase
{
    private function cobrar(float $precio, string $metodo): Invoice
    {
        $contrato = $this->createBillableContract(price: $precio, taxPercent: 0);

        app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => $precio,
            'payment_method' => $metodo,
        ])->assertOk();

        return $factura;
    }

    // ==================== Sin caja abierta ====================

    public function test_sin_caja_abierta_ofrece_abrirla(): void
    {
        $this->get(route('cashRegisters.index'))
            ->assertOk()
            ->assertSee('No tienes una caja abierta')
            ->assertSee('Base inicial')
            ->assertSee('Abrir caja');
    }

    public function test_muestra_el_resultado_del_ultimo_cierre(): void
    {
        CashRegister::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'initial_amount' => 0,
            'final_amount' => 50000,
            'difference' => 0,
            'status' => 'closed',
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subHours(2),
        ]);

        $this->get(route('cashRegisters.index'))
            ->assertOk()
            ->assertSee('Tu último cierre')
            ->assertSee('Cuadró');
    }

    public function test_se_abre_la_caja_sin_notas(): void
    {
        // Las notas son opcionales: no enviarlas tumbaba la apertura
        // con un error 500 porque se leía una clave inexistente.
        $this->postJson(route('cash_register.open'), [
            'initial_amount' => 100000,
        ])->assertOk();

        $this->assertDatabaseHas('cash_registers', [
            'user_id' => $this->admin->id,
            'status' => 'open',
            'initial_amount' => 100000,
        ]);
    }

    // ==================== Con caja abierta ====================

    public function test_con_caja_abierta_muestra_las_cifras_del_turno(): void
    {
        $caja = $this->openCashRegister(initialAmount: 50000);

        $this->cobrar(80000, 'Efectivo');

        $this->get(route('cashRegisters.index'))
            ->assertOk()
            ->assertSee('Caja #' . $caja->id . ' abierta')
            ->assertSee('Base inicial')
            ->assertSee('Esperado en caja')
            // base 50.000 + ingreso 80.000
            ->assertSee('130.000,00')
            ->assertSee('Por método de pago');
    }

    public function test_separa_lo_que_esta_en_el_cajon_de_lo_que_no(): void
    {
        $this->openCashRegister(initialAmount: 0);

        $this->cobrar(100000, 'Efectivo');
        $this->cobrar(60000, 'Transferencia');

        $respuesta = $this->get(route('cashRegisters.index'))->assertOk();

        // Sin esta advertencia el cajero cuenta $100.000, ve un
        // esperado de $160.000 y reporta un faltante que no existe.
        $respuesta->assertSee('En el cajón solo debe haber', false);
        $respuesta->assertSee('100.000,00');
        $respuesta->assertSee('60.000,00');
    }

    public function test_no_advierte_nada_cuando_todo_fue_en_efectivo(): void
    {
        $this->openCashRegister(initialAmount: 0);

        $this->cobrar(70000, 'Efectivo');

        // Todo está en el cajón: la advertencia solo estorbaría
        $this->get(route('cashRegisters.index'))
            ->assertOk()
            ->assertDontSee('En el cajón solo debe haber', false);
    }

    public function test_los_movimientos_del_turno_identifican_al_cliente(): void
    {
        $this->openCashRegister();

        $factura = $this->cobrar(90000, 'Efectivo');

        $this->get(route('cashRegisters.index'))
            ->assertOk()
            ->assertSee('Pago de factura ' . $factura->displayNumber())
            ->assertSee($factura->contract->numero_visible);
    }

    public function test_el_arqueo_ofrece_el_contador_de_denominaciones(): void
    {
        $this->openCashRegister();

        $respuesta = $this->get(route('cashRegisters.index'))->assertOk();

        $respuesta->assertSee('Cuente el efectivo del cajón', false);
        $respuesta->assertSee('Billetes');
        $respuesta->assertSee('Monedas');
        $respuesta->assertSee('Total declarado al cierre');
    }

    public function test_se_cierra_la_caja_sin_notas(): void
    {
        $this->openCashRegister(initialAmount: 20000);

        $this->postJson(route('cash_register.close'), [
            'final_amount' => 20000,
        ])->assertOk();

        $this->assertDatabaseHas('cash_registers', [
            'user_id' => $this->admin->id,
            'status' => 'closed',
        ]);
    }
}
