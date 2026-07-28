<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\PaymentStatus;
use App\Billing\Events\InvoicePaid;
use App\Billing\Events\PaymentRegistered;
use App\Models\AccountCredit;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TechnicalOrder;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly CreditBalanceService $creditBalance,
        private readonly RetentionApplier $retentions,
    ) {
    }

    /**
     * Registra un pago validado sobre una factura.
     *
     * El pago puede venir acompañado de RETENCIONES: impuestos que el
     * cliente descuenta y consigna al Estado a nombre nuestro. Suman
     * para saldar la factura pero no entran a la caja (ver
     * App\Billing\Enums\RetentionType). Por eso aquí se distinguen
     * dos cifras que antes eran una sola:
     *
     *   - $efectivo:       lo que el cajero recibe y va al cuadre
     *   - $totalCancelado: efectivo + retenciones, que es lo que de
     *                      verdad abona la factura
     *
     * @param array{invoice_id: int, amount: float|string, payment_method: string, reference_number?: ?string, notes?: ?string, retentions?: array<int, array<string, mixed>>, payment_batch_id?: ?int} $data
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

        $lineasRetencion = $data['retentions'] ?? [];
        $totalRetenido = $this->retentions->totalDe($lineasRetencion);
        $efectivo = round((float) $data['amount'], 2);
        $totalCancelado = round($efectivo + $totalRetenido, 2);

        if ($efectivo < 0) {
            throw new RuntimeException('El valor recibido no puede ser negativo.');
        }

        if ($totalCancelado <= 0) {
            throw new RuntimeException('El pago debe tener un valor mayor que cero.');
        }

        // La comparación es contra el TOTAL cancelado: si solo se
        // mirara el efectivo, un cobro con retención podría dejar la
        // factura sobrepagada.
        if ($totalCancelado > $pendingAmount + 0.001) {
            throw new RuntimeException($totalRetenido > 0
                ? sprintf(
                    'El pago ($%s recibidos + $%s retenidos = $%s) excede el saldo pendiente de $%s.',
                    number_format($efectivo, 2, ',', '.'),
                    number_format($totalRetenido, 2, ',', '.'),
                    number_format($totalCancelado, 2, ',', '.'),
                    number_format($pendingAmount, 2, ',', '.'),
                )
                : 'El monto del pago excede el saldo pendiente.');
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
            'contract_id' => $invoice->contract_id,
            'payment_batch_id' => $data['payment_batch_id'] ?? null,
            'user_id' => $userId,
            'cash_register_id' => $activeCashRegister->id,
            // El pago guarda SOLO el efectivo: es lo que entró.
            'amount' => $efectivo,
            'payment_method' => $data['payment_method'],
            'payment_date' => now(),
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => PaymentStatus::Completed->value,
        ]);

        // Las retenciones se registran DESPUÉS del pago (necesitan su
        // id) y recalculan el saldo de la factura al terminar.
        if ($lineasRetencion) {
            $this->retentions->aplicar($payment, $invoice, $lineasRetencion);
            $invoice->refresh();
        }

        PaymentRegistered::dispatch($payment);

        if ($totalCancelado >= $pendingAmount - 0.001) {
            $this->settleInvoice($invoice, $branchId);
            InvoicePaid::dispatch($invoice);
        } else {
            $invoice->update(['status' => InvoiceStatus::PendienteParcial->value]);
        }

        // El movimiento de caja registra ÚNICAMENTE el efectivo: la
        // retención no es plata en el cajón y no puede aparecer en el
        // cuadre. Si el cobro fue todo retención, no hay movimiento.
        if ($efectivo > 0) {
            CashRegisterTransaction::create([
                'cash_register_id' => $activeCashRegister->id,
                'payment_id' => $payment->id,
                'transaction_type' => 'Ingreso',
                'amount' => $efectivo,
                'payment_method' => $data['payment_method'],
                'description' => "Pago de factura {$invoice->displayNumber()}",
                'created_by' => $userId,
            ]);

            $activeCashRegister->calculateTotals();
        }

        return $payment;
    }

    /**
     * Registra un ANTICIPO: dinero que el cliente entrega sin que
     * exista una factura, para adelantar meses de servicio.
     *
     * El dinero entra igual que cualquier cobro (caja abierta
     * obligatoria y movimiento en el cuadre) pero, en lugar de saldar
     * una factura concreta, queda a favor del contrato. Acto seguido
     * se aplica a las facturas abiertas que haya —de la más antigua a
     * la más nueva— y lo que sobre queda disponible para las que se
     * generen los meses siguientes.
     *
     * @param  array{contract_id: int, amount: float|string, payment_method: string, reference_number?: ?string, notes?: ?string}  $data
     * @return array{payment: Payment, aplicado: float, saldo_a_favor: float}
     */
    public function registerAdvance(array $data, ?int $userId): array
    {
        $contract = Contract::findOrFail($data['contract_id']);

        $monto = round((float) $data['amount'], 2);

        if ($monto <= 0) {
            throw new RuntimeException('El valor del anticipo debe ser mayor que cero.');
        }

        // Misma exigencia que cualquier cobro: sin caja abierta no
        // entra dinero al sistema.
        $activeCashRegister = CashRegister::where('status', 'open')
            ->where('user_id', $userId)
            ->first();

        if (!$activeCashRegister) {
            throw new RuntimeException('No hay una caja abierta para recibir pagos. Abra su caja antes de cobrar.');
        }

        return DB::transaction(function () use ($contract, $monto, $data, $userId, $activeCashRegister) {
            $payment = Payment::create([
                'invoice_id' => null,
                'contract_id' => $contract->id,
                'type' => 'anticipo',
                'user_id' => $userId,
                'cash_register_id' => $activeCashRegister->id,
                'amount' => $monto,
                'payment_method' => $data['payment_method'],
                'payment_date' => now(),
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => PaymentStatus::Completed->value,
            ]);

            CashRegisterTransaction::create([
                'cash_register_id' => $activeCashRegister->id,
                'payment_id' => $payment->id,
                'transaction_type' => 'Ingreso',
                'amount' => $monto,
                'payment_method' => $data['payment_method'],
                'description' => "Anticipo del contrato {$contract->numero_visible}",
                'created_by' => $userId,
            ]);

            $activeCashRegister->calculateTotals();

            $this->creditBalance->abonar(
                $contract,
                $monto,
                AccountCredit::ORIGEN_ANTICIPO,
                'Pago por adelantado recibido en caja',
                ['payment_id' => $payment->id],
            );

            // Se abona de inmediato a lo que ya deba
            $aplicado = $this->creditBalance->aplicarAFacturasAbiertas($contract);

            PaymentRegistered::dispatch($payment);

            return [
                'payment' => $payment,
                'aplicado' => $aplicado,
                'saldo_a_favor' => $this->creditBalance->saldo($contract),
            ];
        });
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
