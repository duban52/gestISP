<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Services\Audit\AuditLogger;
use App\Support\PaymentReceipt;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Recibo de caja en formato de tirilla térmica.
 *
 * El recibo se genera SIEMPRE al vuelo, no se guarda en disco. Antes
 * se escribía un PDF en storage/temp por cada cobro: los archivos se
 * acumulaban sin que nadie los borrara y, si la transacción del pago
 * se revertía, quedaba en disco el recibo de un cobro que nunca
 * ocurrió. Regenerarlo cuesta milisegundos y siempre refleja el
 * estado real.
 *
 * Dos formatos, un mismo contenido:
 *
 *  - HTML (recibo / recibo de lote): es lo que se ve en el modal
 *    dentro de un iframe y lo que se manda a la impresora térmica.
 *    Se imprime el HTML y no el PDF porque el navegador le entrega a
 *    la impresora justo el alto del contenido; el PDF tiene página
 *    de alto fijo y sacaría papel en blanco.
 *  - PDF: para archivar o enviar. Papel de 80 mm con alto estimado.
 */
class PaymentReceiptController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:payments.receipt');
    }

    /** Recibo de un pago suelto, en HTML (para el modal y la térmica). */
    public function show(Payment $payment): View
    {
        return view('gestisp.payments.receipt-thermal', [
            'recibos' => PaymentReceipt::build($this->conHermanos($payment)),
        ]);
    }

    /** Recibo de un pago suelto, en PDF. */
    public function pdf(Payment $payment): Response
    {
        $recibos = PaymentReceipt::build($this->conHermanos($payment));

        $this->auditLogger->action(
            'payments.receipt_downloaded',
            'Descargó el recibo de caja N.° ' . $recibos[0]['numero'],
            ['pago_id' => $payment->id],
            $payment,
            'facturacion',
        );

        return PaymentReceipt::pdf($recibos)
            ->download('recibo-' . $recibos[0]['numero'] . '.pdf');
    }

    /** Todos los recibos de un cobro múltiple, en HTML. */
    public function batch(PaymentBatch $batch): View
    {
        return view('gestisp.payments.receipt-thermal', [
            'recibos' => PaymentReceipt::build($batch->payments()->get()),
        ]);
    }

    /** Todos los recibos de un cobro múltiple, en un solo PDF. */
    public function batchPdf(PaymentBatch $batch): Response
    {
        $recibos = PaymentReceipt::build($batch->payments()->get());

        $this->auditLogger->action(
            'payments.receipt_downloaded',
            sprintf('Descargó los %d recibos del cobro múltiple %s', count($recibos), $batch->numero_visible),
            ['lote_id' => $batch->id, 'recibos' => count($recibos)],
            $batch,
            'facturacion',
        );

        return PaymentReceipt::pdf($recibos)
            ->download('recibos-' . $batch->numero_visible . '.pdf');
    }

    /**
     * Pagos que deben salir en el mismo recibo que el indicado.
     *
     * Si el pago se hizo dentro de un cobro múltiple, el recibo del
     * contrato debe incluir los demás pagos de ESE MISMO contrato que
     * entraron en la misma operación: quien pagó tres meses de su
     * mamá espera una tirilla con los tres, no tres tirillas.
     *
     * @return Collection<int, Payment>
     */
    private function conHermanos(Payment $payment): Collection
    {
        if (!$payment->payment_batch_id) {
            return collect([$payment]);
        }

        $contratoId = $payment->contract_id ?? $payment->invoice?->contract_id;

        return Payment::where('payment_batch_id', $payment->payment_batch_id)
            ->when(
                $contratoId,
                fn ($q) => $q->where('contract_id', $contratoId),
                fn ($q) => $q->whereKey($payment->id),
            )
            ->orderBy('id')
            ->get();
    }
}
