<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Models\AccountCredit;
use App\Models\CashRegister;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRetention;

/**
 * Reversión de un pago suelto.
 *
 * Un cobro mal registrado —el contrato equivocado, el cliente que
 * anuló la transferencia, el doble cobro— no se arregla editando el
 * pago: se deshace entero. Y deshacerlo tiene que llegar hasta el
 * final, porque el dinero aparece en cuatro sitios a la vez:
 *
 *   · la factura, que vuelve a deber y a dejarse cobrar;
 *   · la caja, que vuelve a cuadrar;
 *   · las retenciones, que dejan de ir a la declaración;
 *   · la trazabilidad, que tiene que decir quién y por qué.
 *
 * Y un freno: con la CAJA CERRADA no se reversa. El arqueo de ese
 * turno se hizo con ese dinero dentro.
 */
class PaymentReversalTest extends BillingTestCase
{
    private function facturaDe(float $precio = 100000): Invoice
    {
        $contrato = $this->createBillableContract($precio);
        $this->post(route('invoices.generate'));

        return Invoice::where('contract_id', $contrato->id)->firstOrFail();
    }

    /** Cobra la factura por el flujo real y devuelve el pago. */
    private function cobrar(Invoice $factura, float $monto, array $extra = []): Payment
    {
        $this->postJson(route('payments.store'), array_merge([
            'invoice_id' => $factura->id,
            'amount' => $monto,
            'payment_method' => 'Efectivo',
        ], $extra))->assertOk();

        return Payment::where('invoice_id', $factura->id)->latest('id')->firstOrFail();
    }

    private function reversar(Payment $pago, string $motivo = 'se cobró al contrato equivocado')
    {
        return $this->deleteJson(route('payments.destroy', $pago), ['motivo' => $motivo]);
    }

    public function test_reversar_devuelve_el_saldo_la_caja_y_el_estado_de_la_factura(): void
    {
        $factura = $this->facturaDe(100000);
        $caja = $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        $factura->refresh();
        $this->assertSame(InvoiceStatus::Pagada->value, $factura->status);
        $this->assertEqualsWithDelta(100000, (float) $caja->fresh()->total_income, 0.01);

        $this->reversar($pago)->assertOk()->assertJson(['success' => true]);

        // El pago no desaparece: queda marcado (SoftDeletes)
        $this->assertSoftDeleted('payments', ['id' => $pago->id]);

        // La factura vuelve a deber Y vuelve a dejarse cobrar: dejarla
        // en «Pagada» con saldo la sacaba de InvoiceStatus::payable()
        $factura->refresh();
        $this->assertEqualsWithDelta(100000, (float) $factura->getPendingAmount(), 0.01);
        $this->assertEqualsWithDelta(100000, (float) $factura->pending_invoice_amount, 0.01);
        $this->assertContains($factura->status, InvoiceStatus::payable());

        // La caja vuelve a cuadrar
        $this->assertEqualsWithDelta(0, (float) $caja->fresh()->total_income, 0.01);
        $this->assertSoftDeleted('cash_register_transactions', ['payment_id' => $pago->id]);
    }

    public function test_la_factura_vuelve_a_admitir_cobro_despues_de_la_reversion(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        $this->reversar($pago)->assertOk();

        // Es el caso real: se cobró mal, se reversa y se vuelve a
        // cobrar bien. Si la factura quedara «Pagada», aquí daría 422.
        $this->postJson(route('payments.store'), [
            'invoice_id' => $factura->id,
            'amount' => 100000,
            'payment_method' => 'Efectivo',
        ])->assertOk();

        $this->assertSame(InvoiceStatus::Pagada->value, $factura->fresh()->status);
    }

    public function test_un_abono_parcial_reversado_deja_la_factura_pendiente_parcial(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();

        $primero = $this->cobrar($factura, 40000);
        $segundo = $this->cobrar($factura, 60000);

        $this->assertSame(InvoiceStatus::Pagada->value, $factura->fresh()->status);

        $this->reversar($segundo)->assertOk();

        $factura->refresh();
        $this->assertSame(InvoiceStatus::PendienteParcial->value, $factura->status);
        $this->assertEqualsWithDelta(60000, (float) $factura->getPendingAmount(), 0.01);
        $this->assertNotNull($primero->fresh(), 'el otro abono no se toca');
    }

    public function test_con_la_caja_cerrada_no_se_reversa(): void
    {
        $factura = $this->facturaDe(100000);
        $caja = $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        $caja->update(['status' => 'closed', 'closed_at' => now()]);

        $this->reversar($pago)->assertStatus(422);

        // Nada se movió: ni el pago, ni la factura, ni el movimiento
        $this->assertNotNull(Payment::find($pago->id));
        $this->assertSame(InvoiceStatus::Pagada->value, $factura->fresh()->status);
        $this->assertDatabaseHas('cash_register_transactions', [
            'payment_id' => $pago->id,
            'deleted_at' => null,
        ]);
    }

    public function test_no_se_reversa_dos_veces(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        $this->reversar($pago)->assertOk();

        // El segundo intento no encuentra el pago: el binding de ruta
        // descarta los que ya están marcados.
        $this->reversar($pago)->assertStatus(404);

        $this->assertSame(1, Payment::onlyTrashed()->where('id', $pago->id)->count());
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        $this->deleteJson(route('payments.destroy', $pago), ['motivo' => ''])
            ->assertStatus(422);

        $this->deleteJson(route('payments.destroy', $pago), ['motivo' => 'no'])
            ->assertStatus(422);

        $this->assertNotNull(Payment::find($pago->id));
    }

    public function test_la_reversion_queda_en_la_trazabilidad_con_su_motivo(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        $this->reversar($pago, 'el cliente anuló la transferencia')->assertOk();

        $this->assertDatabaseHas('audits', ['action' => 'payments.reversed']);

        $registro = \DB::table('audits')->where('action', 'payments.reversed')->latest('id')->first();
        $this->assertStringContainsString('el cliente anuló la transferencia', $registro->description);

        // Y la auditoría propia del pago, con la foto del antes
        $this->assertDatabaseHas('payment_audits', [
            'payment_id' => $pago->id,
            'action' => 'deleted',
        ]);
    }

    public function test_la_retencion_del_pago_reversado_deja_de_contar(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();

        // $96.000 en efectivo + $4.000 de retefuente saldan la factura
        $pago = $this->cobrar($factura, 96000, [
            'retentions' => [[
                'type' => \App\Billing\Enums\RetentionType::Renta->value,
                'base' => 100000,
                'rate' => 4,
                'amount' => 4000,
            ]],
        ]);

        $this->assertSame(1, PaymentRetention::where('payment_id', $pago->id)->count());
        $this->assertEqualsWithDelta(4000, $factura->fresh()->totalRetenciones(), 0.01);

        $this->reversar($pago)->assertOk();

        // La retención llegó con un pago que ya no existe: no puede
        // seguir dando por saldada la factura ni salir en el informe
        // que alimenta la declaración.
        $factura->refresh();
        $this->assertEqualsWithDelta(0, $factura->totalRetenciones(), 0.01);
        $this->assertEqualsWithDelta(100000, (float) $factura->getPendingAmount(), 0.01);

        $this->get(route('retentions.index'))
            ->assertOk()
            ->assertDontSee('4.000,00');
    }

    public function test_un_anticipo_intacto_se_reversa_con_su_saldo_a_favor(): void
    {
        $contrato = $this->createBillableContract(100000);
        $caja = $this->openCashRegister();

        $this->post(route('advance.store', $contrato), [
            'amount' => 50000,
            'payment_method' => 'Efectivo',
        ])->assertRedirect();

        $pago = Payment::where('contract_id', $contrato->id)->where('type', 'anticipo')->firstOrFail();

        $this->assertEqualsWithDelta(50000, (float) $caja->fresh()->total_income, 0.01);

        $this->reversar($pago, 'el cliente se arrepintió')->assertOk();

        // El dinero sale de la caja Y del saldo a favor: dejarlo a
        // favor sería regalarle al contrato un mes que nadie pagó.
        $this->assertEqualsWithDelta(0, (float) $caja->fresh()->total_income, 0.01);
        $this->assertSame(0, AccountCredit::where('payment_id', $pago->id)->count());
        $this->assertEqualsWithDelta(
            0,
            app(\App\Billing\Services\CreditBalanceService::class)->saldo($contrato->fresh()),
            0.01,
        );
    }

    public function test_un_anticipo_ya_aplicado_a_facturas_no_se_reversa(): void
    {
        $contrato = $this->createBillableContract(100000);
        $this->post(route('invoices.generate'));
        $this->openCashRegister();

        // El anticipo entra y se aplica solo a la factura abierta
        $this->post(route('advance.store', $contrato), [
            'amount' => 50000,
            'payment_method' => 'Efectivo',
        ])->assertRedirect();

        $pago = Payment::where('contract_id', $contrato->id)->where('type', 'anticipo')->firstOrFail();

        $this->reversar($pago)->assertStatus(422);

        $this->assertNotNull(Payment::find($pago->id));
        $this->assertSame(
            1,
            AccountCredit::where('contract_id', $contrato->id)
                ->where('movement', AccountCredit::APLICACION)
                ->count(),
        );
    }

    public function test_sin_el_permiso_no_se_reversa(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        // Un administrador normal cobra, pero no deshace cobros
        $this->actuarComoAdministrador();

        $this->reversar($pago)->assertForbidden();
        $this->get(route('payments.reversionInfo', $pago))->assertForbidden();

        $this->assertNotNull(Payment::find($pago->id));
    }

    public function test_la_pantalla_de_confirmacion_dice_que_no_se_deshace(): void
    {
        $factura = $this->facturaDe(100000);
        $this->openCashRegister();
        $pago = $this->cobrar($factura, 100000);

        $datos = $this->getJson(route('payments.reversionInfo', $pago))
            ->assertOk()
            ->json();

        $this->assertNull($datos['impedimento']);
        $this->assertNotEmpty($datos['avisos']);
        $this->assertSame($factura->displayNumber(), $datos['factura']);

        // Y con la caja cerrada, el motivo en claro ANTES de pedir nada
        CashRegister::query()->update(['status' => 'closed']);

        $this->assertStringContainsString(
            'cerrada',
            $this->getJson(route('payments.reversionInfo', $pago))->json('impedimento'),
        );
    }

    /** Deja autenticado a un administrador (sin payments.destroy). */
    private function actuarComoAdministrador(): void
    {
        $rol = \Spatie\Permission\Models\Role::where('name', 'administrador')->firstOrFail();

        $usuario = \App\Models\User::factory()->create(['number_phone' => '3000000001']);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);
    }
}
