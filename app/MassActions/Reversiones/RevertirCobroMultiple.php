<?php

namespace App\MassActions\Reversiones;

use App\Billing\Services\PaymentReverser;
use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\MassActionItem;
use App\Models\Payment;

/**
 * Deshace un cobro múltiple: reversa cada pago del lote.
 *
 * La aritmética no vive aquí. Reversar un pago es lo mismo se haga de
 * a uno desde el historial o de a cien deshaciendo un lote, así que
 * los dos caminos llaman a App\Billing\Services\PaymentReverser:
 * mismos frenos (la caja abierta), mismos efectos (movimiento de
 * caja, saldo a favor del anticipo, saldo y estado de la factura) y
 * misma huella en la auditoría. Ahí está documentado qué deshace y
 * qué no.
 *
 * Lo único propio de aquí es el envoltorio de la acción masiva: un
 * ítem por pago, y el motivo del conflicto cuando uno no se puede.
 */
class RevertirCobroMultiple implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly PaymentReverser $reversor,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $pago = $item->subject;

        if (!$pago instanceof Payment) {
            return 'El pago ya no existe o fue reversado.';
        }

        return $this->reversor->motivoParaNoReversar($pago);
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var Payment $pago */
        $pago = $item->subject;

        $this->reversor->reversar(
            $pago,
            'se deshizo el cobro múltiple #' . $item->mass_action_id,
            origen: 'cobro_multiple',
        );
    }

    public function advertencia(): string
    {
        return 'Se reversarán los pagos del lote: cada factura vuelve a quedar con su saldo '
            . 'pendiente y se quitará el movimiento de la caja. Solo se puede mientras la caja '
            . 'siga ABIERTA; si ya se cerró, el pago queda en conflicto para no descuadrar su '
            . 'arqueo. El estado de los contratos no se toca.';
    }
}
