<?php

namespace App\MassActions\Reversiones;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\InvoiceVoider;
use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\Invoice;
use App\Models\MassActionItem;

/**
 * Deshace una corrida de facturación ANULANDO sus facturas.
 *
 * POR QUÉ NO SE BORRAN
 * --------------------
 * Porque una factura emitida existe. Gastó un consecutivo de un rango
 * que autorizó la DIAN en una resolución, y ese número no se recupera:
 * borrar la fila deja un hueco en la numeración que hay que justificar
 * ante ella. Si además se transmitió, existe en sus registros con su
 * CUFE y borrarla aquí no la borra allá.
 *
 * Anular es la figura que sí existe: la factura se queda, con su
 * número, marcada como anulada, con quién, cuándo y por qué. Es lo que
 * ya hace `InvoiceVoider`, y esta estrategia no inventa reglas nuevas
 * — se apoya en las suyas, que son las correctas.
 *
 * LO QUE InvoiceVoider NO DEJA ANULAR, Y CON RAZÓN
 * ------------------------------------------------
 *  · Una factura CON PAGOS: habría dinero recibido sin documento que
 *    lo soporte. Primero se reversan los pagos.
 *  · Una ELECTRÓNICA YA VALIDADA por la DIAN: esa se corrige con una
 *    nota crédito, que sí se transmite y queda en los dos lados.
 *
 * Esas quedan en conflicto, con su motivo, y la pantalla lo dice. Es
 * exactamente la «reversión parcial» del encargo: lo que se puede
 * deshacer se deshace, lo que no se explica.
 */
class AnularCorridaDeFacturacion implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly InvoiceVoider $anulador,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $factura = $item->subject;

        if (!$factura instanceof Invoice) {
            return 'La factura ya no existe.';
        }

        if ($factura->status === InvoiceStatus::Anulada->value) {
            return 'La factura ya está anulada.';
        }

        if ($factura->payments()->exists()) {
            return 'La factura tiene pagos: hay que reversarlos antes de anularla.';
        }

        if ($factura->totalAbonado() > 0) {
            return 'La factura tiene abonos aplicados (saldo a favor o retenciones).';
        }

        $documento = \App\Models\ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->first();

        if ($documento && $documento->status === \App\Models\ElectronicDocument::ACEPTADO) {
            return 'La DIAN ya validó esta factura electrónica: se corrige con una nota crédito, no anulándola.';
        }

        return null;
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var Invoice $factura */
        $factura = $item->subject;

        $this->anulador->void(
            $factura,
            'Reversión de la corrida de facturación (acción masiva #' . $item->mass_action_id . ').',
            auth()->id(),
        );
    }

    public function advertencia(): string
    {
        return 'Las facturas NO se borran: se ANULAN, conservando su número y dejando '
            . 'constancia de quién y por qué. Las que tengan pagos o ya hayan sido validadas '
            . 'por la DIAN no se pueden anular y quedarán en conflicto.';
    }
}
