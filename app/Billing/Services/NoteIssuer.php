<?php

namespace App\Billing\Services;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\NoteType;
use App\Models\CreditDebitNote;
use App\Models\Invoice;
use App\Models\NoteNumberingSequence;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emisión de notas crédito y débito sobre una factura.
 *
 * Regla de fondo: una factura emitida NO se toca. Si hay que
 * corregirla —porque se devolvió un servicio, se concedió un
 * descuento, se cobró de más o hay que cobrar intereses— se emite un
 * documento aparte que ajusta el saldo y deja constancia del motivo.
 *
 * Qué hace este servicio:
 *
 *  1. Valida que la corrección sea posible (factura anulada no admite
 *     notas; una nota crédito no puede superar el saldo pendiente).
 *  2. Asigna el consecutivo de la sucursal con la fila BLOQUEADA, para
 *     que dos emisiones simultáneas nunca repitan número.
 *  3. Ajusta el saldo de la factura y su estado.
 *  4. Deja el rastro en la trazabilidad del sistema.
 *
 * Todo ocurre dentro de una transacción: o queda la nota con su efecto
 * aplicado, o no queda nada.
 */
class NoteIssuer
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CreditBalanceService $creditBalance,
    ) {
    }

    /**
     * Emite una nota sobre una factura.
     *
     * @param  array{type: string, concept_code: string, reason: string, subtotal: float, tax: float, issue_date?: string}  $datos
     */
    public function emitir(Invoice $invoice, array $datos): CreditDebitNote
    {
        $tipo = NoteType::from($datos['type']);

        $subtotal = round((float) $datos['subtotal'], 2);
        $impuesto = round((float) ($datos['tax'] ?? 0), 2);
        $total = round($subtotal + $impuesto, 2);

        $this->validar($invoice, $tipo, $total, $datos['concept_code']);

        return DB::transaction(function () use ($invoice, $tipo, $datos, $subtotal, $impuesto, $total) {
            $secuencia = $this->secuenciaBloqueada($invoice->branch_id, $tipo);
            $consecutivo = $secuencia->current_number + 1;

            $secuencia->update(['current_number' => $consecutivo]);

            $nota = CreditDebitNote::create([
                'branch_id' => $invoice->branch_id,
                'invoice_id' => $invoice->id,
                'contract_id' => $invoice->contract_id,
                'user_id' => Auth::id(),
                'type' => $tipo->value,
                'prefix' => $secuencia->prefix,
                'number' => $consecutivo,
                'full_number' => $secuencia->prefix . '-' . $consecutivo,
                'concept_code' => $datos['concept_code'],
                // Se guarda también el texto del concepto: si mañana
                // cambia la tabla de la DIAN, el documento emitido
                // sigue diciendo lo que decía cuando se emitió.
                'concept_label' => $tipo->concepto($datos['concept_code']),
                'reason' => trim($datos['reason']),
                'issue_date' => $datos['issue_date'] ?? Carbon::today(),
                'subtotal' => $subtotal,
                'tax' => $impuesto,
                'total' => $total,
                'status' => CreditDebitNote::EMITIDA,
            ]);

            $this->aplicarEfecto($invoice, $nota);

            $this->auditLogger->action(
                'invoices.note_issued',
                sprintf(
                    'Emitió la %s %s por $%s sobre la factura %s (%s)',
                    $tipo->etiqueta(),
                    $nota->full_number,
                    number_format($total, 2, ',', '.'),
                    $invoice->displayNumber(),
                    $nota->concept_label,
                ),
                [
                    'factura' => $invoice->displayNumber(),
                    'nota' => $nota->full_number,
                    'concepto' => $nota->concept_code . ' - ' . $nota->concept_label,
                    'motivo' => $nota->reason,
                    'total' => $total,
                    'saldo_resultante' => (float) $invoice->fresh()->pending_invoice_amount,
                ],
                $nota,
                'facturacion',
            );

            return $nota;
        });
    }

    /**
     * Anula una nota ya emitida y revierte su efecto.
     */
    public function anular(CreditDebitNote $nota, string $motivo): CreditDebitNote
    {
        if (!$nota->vigente) {
            throw new RuntimeException('Esta nota ya está anulada.');
        }

        return DB::transaction(function () use ($nota, $motivo) {
            $factura = $nota->invoice()->lockForUpdate()->first();

            // Se revierte el efecto: lo que restó vuelve a sumar y
            // viceversa.
            $saldo = (float) $factura->pending_invoice_amount - $nota->efecto;

            $factura->update([
                'pending_invoice_amount' => max(round($saldo, 2), 0),
                'status' => $this->estadoSegunSaldo($factura, max(round($saldo, 2), 0)),
            ]);

            $nota->update([
                'status' => CreditDebitNote::ANULADA,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => trim($motivo),
            ]);

            $this->auditLogger->action(
                'invoices.note_voided',
                sprintf('Anuló la %s %s', $nota->etiqueta_tipo, $nota->full_number),
                ['motivo' => $motivo, 'factura' => $factura->displayNumber()],
                $nota,
                'facturacion',
            );

            return $nota;
        });
    }

    /**
     * Comprueba que la nota se pueda emitir.
     */
    private function validar(Invoice $invoice, NoteType $tipo, float $total, string $conceptoCodigo): void
    {
        if ($total <= 0) {
            throw new RuntimeException('El valor de la nota debe ser mayor que cero.');
        }

        if ($invoice->status === InvoiceStatus::Anulada->value) {
            throw new RuntimeException(
                'La factura está anulada: una factura sin efecto no admite notas.'
            );
        }

        if (!$tipo->concepto($conceptoCodigo)) {
            throw new RuntimeException('El concepto indicado no corresponde a este tipo de nota.');
        }

        // Una nota crédito PUEDE superar el saldo de la factura: pasa
        // al anular una factura que el cliente ya pagó. En ese caso el
        // excedente no se pierde ni deja la factura en negativo: queda
        // como saldo a favor del contrato (ver aplicarEfecto).
        if ($tipo->disminuye() && $total > (float) $invoice->total + 0.001) {
            throw new RuntimeException(sprintf(
                'La nota crédito ($%s) supera el valor total de la factura ($%s). '
                . 'No se puede devolver más de lo que se facturó.',
                number_format($total, 2, ',', '.'),
                number_format((float) $invoice->total, 2, ',', '.'),
            ));
        }
    }

    /**
     * Ajusta el saldo y el estado de la factura.
     */
    private function aplicarEfecto(Invoice $invoice, CreditDebitNote $nota): void
    {
        $factura = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

        $pendiente = (float) $factura->pending_invoice_amount;
        $saldo = round($pendiente + $nota->efecto, 2);

        // Excedente: la nota crédito devolvió MÁS de lo que esa
        // factura debía (caso típico: se anula una factura que el
        // cliente ya había pagado). Ese dinero no se pierde ni deja la
        // factura en negativo: queda a favor del contrato para las
        // próximas facturas.
        $excedente = $saldo < 0 ? abs($saldo) : 0.0;
        $saldo = max($saldo, 0);

        $factura->update([
            'pending_invoice_amount' => $saldo,
            'status' => $this->estadoSegunSaldo($factura, $saldo, $nota),
        ]);

        if ($excedente > 0 && $factura->contract) {
            $this->creditBalance->abonar(
                $factura->contract,
                $excedente,
                \App\Models\AccountCredit::ORIGEN_NOTA_CREDITO,
                sprintf(
                    'Excedente de la %s %s sobre la factura %s',
                    $nota->etiqueta_tipo,
                    $nota->full_number,
                    $factura->displayNumber(),
                ),
                [
                    'invoice_id' => $factura->id,
                    'credit_debit_note_id' => $nota->id,
                ],
            );
        }

        // Se refresca el modelo recibido para que quien llamó vea el
        // saldo ya ajustado.
        $invoice->refresh();
    }

    /**
     * Estado que corresponde a la factura según su saldo.
     *
     * Si la nota crédito dejó el saldo en cero, la factura queda
     * saldada. Si una nota débito reabre saldo sobre una factura que
     * estaba pagada, vuelve a quedar pendiente.
     */
    private function estadoSegunSaldo(Invoice $factura, float $saldo, ?CreditDebitNote $nota = null): string
    {
        if ($saldo <= 0) {
            // Si lo que dejó la factura en cero fue una NOTA CRÉDITO,
            // no se marca como pagada: el dinero nunca se recaudó, se
            // ajustó. Un informe de recaudo no debe contarlo como
            // cobrado. Salvo que ya estuviera pagada por el cliente.
            if ($nota?->tipo()->disminuye() && $factura->status !== InvoiceStatus::Pagada->value) {
                return InvoiceStatus::SaldadaConNota->value;
            }

            return InvoiceStatus::Pagada->value;
        }

        // Con saldo, se conserva el estado si ya era exigible; si
        // estaba pagada, vuelve a pendiente.
        return in_array($factura->status, InvoiceStatus::payable(), true)
            ? $factura->status
            : InvoiceStatus::Pendiente->value;
    }

    /**
     * Secuencia de numeración de la sucursal, con la fila bloqueada.
     */
    private function secuenciaBloqueada(int $branchId, NoteType $tipo): NoteNumberingSequence
    {
        $secuencia = NoteNumberingSequence::where('branch_id', $branchId)
            ->where('type', $tipo->value)
            ->lockForUpdate()
            ->first();

        if ($secuencia) {
            return $secuencia;
        }

        NoteNumberingSequence::firstOrCreate(
            ['branch_id' => $branchId, 'type' => $tipo->value],
            ['prefix' => $tipo->prefijo(), 'current_number' => 0],
        );

        // Se relee con bloqueo: firstOrCreate no bloquea la fila
        return NoteNumberingSequence::where('branch_id', $branchId)
            ->where('type', $tipo->value)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
