<?php

namespace App\MassActions\Reversiones;

use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\MassActionItem;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;

/**
 * Deshace un cobro múltiple: reversa cada pago del lote.
 *
 * ESTO ES DINERO, Y EL DINERO TIENE UN FRENO
 * ------------------------------------------
 * Un pago no vive solo: entró a una caja y cuenta en su cuadre. Por
 * eso solo se reversa mientras **esa caja siga abierta**. Si ya se
 * cerró, el arqueo de ese turno se hizo con ese dinero dentro:
 * quitarlo después deja el cierre descuadrado para siempre y sin nada
 * que lo explique. En ese caso queda en conflicto y lo resuelve una
 * persona — con una nota, un egreso o un ajuste, que son las figuras
 * que sí dejan rastro de los dos lados.
 *
 * QUÉ DESHACE
 * -----------
 * El pago se elimina (es `SoftDeletes`: no desaparece, queda
 * marcado), con él su movimiento de caja, y la factura recalcula su
 * saldo y su estado. Las retenciones que llegaron con el pago caen
 * con él: `Invoice::totalRetenciones()` ya descarta las de un pago
 * reversado.
 *
 * LO QUE NO HACE
 * --------------
 * No devuelve el contrato a «Suspendido» aunque el pago lo hubiera
 * reactivado. Reactivar fue una decisión con información de toda la
 * cartera, y la mora se recalcula sola en la siguiente corrida o en
 * la tarea diaria. Cortarle el servicio a alguien desde aquí, por un
 * cobro mal registrado, sería castigarlo por un error ajeno.
 */
class RevertirCobroMultiple implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly AuditLogger $auditoria,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $pago = $item->subject;

        if (!$pago instanceof Payment) {
            return 'El pago ya no existe o fue reversado.';
        }

        $caja = $this->cajaDe($pago);

        if ($caja && $caja->status !== 'open') {
            return sprintf(
                'La caja #%d en la que entró este dinero ya está cerrada: quitarlo descuadraría su arqueo.',
                $caja->id,
            );
        }

        return null;
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var Payment $pago */
        $pago = $item->subject;

        $factura = $pago->invoice;
        $caja = $this->cajaDe($pago);

        // El movimiento de caja primero: si queda huérfano, el cuadre
        // sigue contando un dinero que ya no tiene pago detrás.
        CashRegisterTransaction::where('payment_id', $pago->id)->delete();

        $pago->delete();

        // El saldo y el estado de la factura se recalculan con el pago
        // ya fuera. `recalcularSaldo()` cuenta pagos, retenciones y
        // saldo a favor, así que vuelve a dejar el saldo real.
        $factura?->recalcularSaldo();

        $caja?->calculateTotals();

        $this->auditoria->action(
            'payments.reversed',
            sprintf(
                'Reversó el pago de la factura %s al deshacer un cobro múltiple',
                $factura?->displayNumber() ?? 'desconocida',
            ),
            [
                'pago' => $pago->id,
                'factura' => $factura?->displayNumber(),
                'monto' => (float) $pago->amount,
                'accion' => $item->mass_action_id,
            ],
            $factura,
            'facturacion',
        );
    }

    private function cajaDe(Payment $pago): ?CashRegister
    {
        $movimiento = CashRegisterTransaction::where('payment_id', $pago->id)->first();

        return $movimiento
            ? CashRegister::find($movimiento->cash_register_id)
            : null;
    }

    public function advertencia(): string
    {
        return 'Se reversarán los pagos del lote: cada factura vuelve a quedar con su saldo '
            . 'pendiente y se quitará el movimiento de la caja. Solo se puede mientras la caja '
            . 'siga ABIERTA; si ya se cerró, el pago queda en conflicto para no descuadrar su '
            . 'arqueo. El estado de los contratos no se toca.';
    }
}
