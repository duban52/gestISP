<?php

namespace App\Billing\Services;

use App\Billing\Enums\PaymentStatus;
use App\Models\AccountCredit;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use RuntimeException;

/**
 * Reversión de un pago.
 *
 * Único lugar donde un pago se deshace: lo usan la reversión suelta
 * (PaymentController::destroy) y la de un cobro múltiple
 * (App\MassActions\Reversiones\RevertirCobroMultiple). Dos copias de
 * esta aritmética es como acaban diciendo cosas distintas del mismo
 * dinero.
 *
 * EL DINERO TIENE UN FRENO: LA CAJA
 * ---------------------------------
 * Un pago no vive solo: entró a una caja y cuenta en su cuadre. Por
 * eso solo se reversa mientras **esa caja siga abierta**. Si ya se
 * cerró, el arqueo de ese turno se hizo con ese dinero dentro:
 * quitarlo después deja el cierre descuadrado para siempre y sin
 * nada que lo explique. Con la caja cerrada se corrige con las
 * figuras que sí dejan rastro de los dos lados — una nota, un egreso
 * o un ajuste.
 *
 * QUÉ DESHACE, EN ORDEN
 * ---------------------
 *  1. El movimiento de caja. Primero, porque si queda huérfano el
 *     cuadre sigue contando un dinero que ya no tiene pago detrás.
 *  2. El saldo a favor, si el pago era un ANTICIPO. Solo si sigue
 *     entero: lo que ya se aplicó a facturas no se desaplica desde
 *     aquí (ver motivoParaNoReversar()).
 *  3. El pago. Es SoftDeletes: no desaparece, queda marcado con su
 *     fecha, y PaymentAudit guarda la foto del antes.
 *  4. La factura: recalcula su saldo y, si vuelve a deber, deja de
 *     estar «Pagada» (lo hace Invoice::recalcularSaldo()).
 *  5. Los totales de la caja.
 *
 * Las retenciones que llegaron con el pago caen con él sin tocarlas:
 * Invoice::totalRetenciones() y el informe de retenciones ya
 * descartan las de un pago reversado.
 *
 * LO QUE NO HACE
 * --------------
 * No devuelve el contrato a «Suspendido» aunque el pago lo hubiera
 * reactivado, ni le quita el servicio. Cortarle el internet a alguien
 * por un cobro mal registrado sería castigarlo por un error ajeno, y
 * la mora se recalcula sola en la siguiente corrida. Si el pago
 * además disparó una reconexión, el aviso lo dice: queda un cargo de
 * reconexión pendiente que decide una persona.
 */
class PaymentReverser
{
    public function __construct(
        private readonly AuditLogger $auditoria,
        private readonly CreditBalanceService $saldoAFavor,
    ) {
    }

    /**
     * ¿Se puede reversar? `null` si sí; si no, el motivo en claro.
     *
     * Se llama antes de tocar nada —desde la pantalla de confirmación
     * y otra vez dentro de la transacción— y no modifica nada.
     */
    public function motivoParaNoReversar(Payment $pago): ?string
    {
        if ($pago->trashed() || $pago->status === PaymentStatus::Voided->value) {
            return 'Este pago ya fue reversado.';
        }

        $caja = $this->cajaDe($pago);

        if ($caja && $caja->status !== 'open') {
            return sprintf(
                'La caja #%d en la que entró este dinero ya está cerrada: quitarlo descuadraría su arqueo. '
                . 'Con la caja cerrada se corrige con una nota, un egreso o un ajuste.',
                $caja->id,
            );
        }

        if ($this->esAnticipo($pago) && !$this->elAnticipoSigueEntero($pago)) {
            return 'El dinero de este anticipo ya se aplicó a facturas del contrato: '
                . 'reversarlo dejaría facturas saldadas con un dinero que ya no existe.';
        }

        return null;
    }

    /**
     * Deshace el pago. Debe ejecutarse dentro de una transacción.
     *
     * @throws RuntimeException si el pago no se puede reversar
     */
    public function reversar(Payment $pago, ?string $motivo = null, string $origen = 'suelta'): void
    {
        if ($impedimento = $this->motivoParaNoReversar($pago)) {
            throw new RuntimeException($impedimento);
        }

        $factura = $pago->invoice;
        $caja = $this->cajaDe($pago);
        $retenido = $pago->totalRetenciones();

        CashRegisterTransaction::where('payment_id', $pago->id)->delete();

        if ($this->esAnticipo($pago)) {
            AccountCredit::where('payment_id', $pago->id)
                ->where('movement', AccountCredit::ENTRADA)
                ->delete();
        }

        $pago->delete();

        $factura?->recalcularSaldo();

        $caja?->calculateTotals();

        $this->auditoria->action(
            'payments.reversed',
            sprintf(
                'Reversó el pago #%d de $%s%s%s',
                $pago->id,
                number_format((float) $pago->amount, 2, ',', '.'),
                $factura ? ' de la factura ' . $factura->displayNumber() : ' (anticipo)',
                $motivo ? ': ' . $motivo : '',
            ),
            [
                'pago' => $pago->id,
                'factura' => $factura?->displayNumber(),
                'contrato' => ($factura?->contract ?? $pago->contract)?->numero_visible,
                'monto' => (float) $pago->amount,
                'retenciones' => $retenido,
                'caja' => $caja?->id,
                'motivo' => $motivo,
                'origen' => $origen,
            ],
            $factura ?? $pago,
            'facturacion',
        );
    }

    /**
     * Lo que hay que advertirle a quien va a reversar: efectos que la
     * reversión NO deshace y tiene que decidir una persona.
     *
     * @return array<int, string>
     */
    public function advertencias(Payment $pago): array
    {
        $avisos = [];

        $contrato = $pago->invoice?->contract ?? $pago->contract;

        if ($contrato && $contrato->additionalCharges()
            ->where('status', 'pendiente')
            ->where('description', 'like', ServiceReconnection::DESCRIPCION . '%')
            ->exists()
        ) {
            // ponytail: el cargo NO se borra — el contrato sí quedó
            // reconectado y nadie le va a quitar el servicio desde
            // aquí. Si el cobro fue un error de identificación, el
            // cargo se anula a mano en Cargos adicionales.
            $avisos[] = 'Este contrato tiene un cargo de RECONEXIÓN pendiente de facturar. '
                . 'El servicio sigue activo: si el cobro fue un error, anule el cargo a mano.';
        }

        if ($pago->totalRetenciones() > 0) {
            $avisos[] = sprintf(
                'El pago traía $%s en retenciones: dejarán de contar en la factura y en el informe de retenciones.',
                number_format($pago->totalRetenciones(), 2, ',', '.'),
            );
        }

        if ($pago->payment_batch_id) {
            $avisos[] = 'El pago entró en un COBRO MÚLTIPLE: se reversa solo este; los demás del lote quedan como están.';
        }

        $avisos[] = 'El contrato no se corta ni cambia de estado: la mora se recalcula sola en la siguiente corrida.';

        return $avisos;
    }

    /** La caja por la que entró el dinero, si hubo movimiento. */
    private function cajaDe(Payment $pago): ?CashRegister
    {
        $movimiento = CashRegisterTransaction::where('payment_id', $pago->id)->first();

        return $movimiento
            ? CashRegister::find($movimiento->cash_register_id)
            : null;
    }

    private function esAnticipo(Payment $pago): bool
    {
        return $pago->type === 'anticipo';
    }

    /**
     * ¿El anticipo sigue intacto?
     *
     * Sí cuando el contrato todavía tiene a su favor, por lo menos,
     * lo que entró con este pago. Es una condición SUFICIENTE, no
     * exacta: si hubo varios anticipos y se consumió parte, bloquea
     * de más. Preferible a desaplicar movimientos a ciegas.
     *
     * ponytail: si algún día hace falta reversar un anticipo ya
     * consumido, el camino es desaplicar sus APLICACION factura por
     * factura y reabrirlas — bastante más código que esto.
     */
    private function elAnticipoSigueEntero(Payment $pago): bool
    {
        $contrato = $pago->contract;

        if (!$contrato) {
            return true;
        }

        return $this->saldoAFavor->saldo($contrato) + 0.001
            >= round((float) $pago->amount, 2);
    }
}
