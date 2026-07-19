<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\PaymentStatus;
use App\Billing\Events\InvoicePaid;
use App\Billing\Events\PaymentRegistered;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TechnicalOrder;
use RuntimeException;

/**
 * Registro de pagos sobre facturas.
 *
 * Encapsula las reglas de negocio que vivían en
 * PaymentController::store:
 *
 *  - TODO cobro exige caja abierta del usuario, sin importar el
 *    método de pago: cada peso recaudado queda en una caja y en
 *    el cuadre del punto de cobro. (Antes las transferencias
 *    podían cobrarse sin caja y quedaban por fuera del cuadre.)
 *  - El monto no puede exceder el saldo pendiente.
 *  - Pago total → factura Pagada + transición del contrato:
 *    Pre-suspensión → Activo (reactivación directa) o
 *    Suspendido → Por Reconexión (+ orden técnica automática).
 *  - Pago parcial → factura Pendiente Parcial.
 *  - El movimiento queda registrado en la caja abierta.
 *
 * DEBE ejecutarse dentro de una transacción (el controlador la
 * abre, porque el recibo PDF también participa del todo-o-nada).
 * La factura se bloquea con lockForUpdate: dos pagos simultáneos
 * sobre la misma factura ya no pueden validar ambos contra el
 * mismo saldo (condición de carrera de la versión anterior).
 */
class PaymentRegistrar
{

    /**
     * Registra un pago validado sobre una factura.
     *
     * @param array{invoice_id: int, amount: float|string, payment_method: string, reference_number?: ?string, notes?: ?string} $data
     */
    public function register(array $data, ?int $userId, ?int $branchId): Payment
    {
        // Bloquear la factura hasta el commit: serializa pagos
        // concurrentes sobre la misma factura
        $invoice = Invoice::whereKey($data['invoice_id'])
            ->lockForUpdate()
            ->firstOrFail();

        // Solo facturas abiertas admiten pagos (una anulada,
        // pagada o absorbida histórica no debe recibir dinero)
        if (!in_array($invoice->status, InvoiceStatus::payable())) {
            throw new RuntimeException(
                "La factura no admite pagos (estado: {$invoice->status})."
            );
        }

        $pendingAmount = $invoice->getPendingAmount();

        if ($data['amount'] > $pendingAmount) {
            throw new RuntimeException('El monto del pago excede el saldo pendiente.');
        }

        // Caja abierta OBLIGATORIA para cualquier método de pago:
        // todo recaudo debe quedar dentro del cuadre de una caja
        $activeCashRegister = CashRegister::where('status', 'open')
            ->where('user_id', $userId)
            ->first();

        if (!$activeCashRegister) {
            throw new RuntimeException('No hay una caja abierta para recibir pagos. Abra su caja antes de cobrar.');
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $userId,
            'cash_register_id' => $activeCashRegister->id,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'payment_date' => now(),
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => PaymentStatus::Completed->value,
        ]);

        PaymentRegistered::dispatch($payment);

        if ($data['amount'] >= $pendingAmount) {
            $this->settleInvoice($invoice, $branchId);
            InvoicePaid::dispatch($invoice);
        } else {
            $invoice->update(['status' => InvoiceStatus::PendienteParcial->value]);
        }

        // El movimiento de caja se registra siempre (la caja es
        // obligatoria), identificando la factura por su número
        CashRegisterTransaction::create([
            'cash_register_id' => $activeCashRegister->id,
            'payment_id' => $payment->id,
            'transaction_type' => 'Ingreso',
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'description' => "Pago de factura {$invoice->displayNumber()}",
            'created_by' => $userId,
        ]);

        $activeCashRegister->calculateTotals();

        return $payment;
    }

    /**
     * Marca la factura como pagada y aplica la transición del
     * contrato según su estado de cobranza.
     */
    private function settleInvoice(Invoice $invoice, ?int $branchId): void
    {
        $invoice->update(['status' => InvoiceStatus::Pagada->value]);

        $contract = $invoice->contract;

        if (!$contract) {
            return;
        }

        if ($contract->status === ContractStatus::PreSuspension->value) {
            // Aún no cortado: se reactiva directamente
            $contract->update([
                'status' => ContractStatus::Activo->value,
                'overdue_invoices_count' => 0,
                'suspension_warning_date' => null,
            ]);
        } elseif ($contract->status === ContractStatus::Suspendido->value) {
            // Ya cortado: requiere visita técnica de reconexión
            TechnicalOrder::create([
                'contract_id' => $contract->id,
                'branch_id' => $branchId,
                'type' => 'Servicio',
                'detail' => 'Reconexión',
                'initial_comment' => 'Orden de reconexión automática por pago',
            ]);

            $contract->update([
                'status' => ContractStatus::PorReconexion->value,
                'overdue_invoices_count' => 0,
                'suspension_warning_date' => null,
            ]);
        }
    }
}
