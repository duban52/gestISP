<?php

namespace App\Billing\Services;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\PaymentStatus;
use App\Billing\Events\InvoiceVoided;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Anulación formal de facturas.
 *
 * Nunca se elimina una factura: cambia a estado Anulada
 * registrando quién, cuándo y por qué. El trait Auditable de
 * Invoice deja además el registro con los valores antes/después.
 *
 * Reglas:
 *  - Motivo obligatorio.
 *  - No se anula una factura con pagos completados: primero deben
 *    reversarse los pagos (una factura pagada que se anulara
 *    dejaría dinero recibido sin documento que lo soporte).
 *  - No se anula lo ya anulado ni las absorbidas históricas.
 *
 * Tras anular se refrescan las suspensiones de la sucursal: si la
 * factura anulada era una vencida que sumaba para el corte, el
 * contrato recupera su estado real de inmediato.
 */
class InvoiceVoider
{
    public function __construct(
        private readonly OverdueProcessor $overdueProcessor,
    ) {
    }

    public function void(Invoice $invoice, string $reason, ?int $userId): Invoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Debe indicar el motivo de la anulación.');
        }

        if ($invoice->status === InvoiceStatus::Anulada->value) {
            throw new RuntimeException('La factura ya está anulada.');
        }

        if ($invoice->status === InvoiceStatus::CargadaANuevaFactura->value) {
            throw new RuntimeException('Las facturas absorbidas históricas no pueden anularse.');
        }

        $hasCompletedPayments = $invoice->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->exists();

        if ($hasCompletedPayments) {
            throw new RuntimeException(
                'No se puede anular una factura con pagos registrados. Reverse primero los pagos.'
            );
        }

        DB::transaction(function () use ($invoice, $reason, $userId) {
            $invoice->update([
                'status' => InvoiceStatus::Anulada->value,
                'pending_invoice_amount' => 0,
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => $reason,
            ]);
        });

        // Recalcular suspensiones: la anulada puede haber sido una
        // vencida que sumaba para el corte del contrato
        if ($invoice->branch_id) {
            $this->overdueProcessor->refreshContractSuspensions($invoice->branch_id);
        }

        InvoiceVoided::dispatch($invoice);

        return $invoice;
    }
}
