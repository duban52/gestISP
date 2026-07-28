<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Models\AccountCredit;
use App\Models\Contract;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Saldo a favor del cliente.
 *
 * Un contrato puede tener dinero a su favor por dos motivos:
 *
 *  - Pagó por adelantado (por ejemplo, seis meses de una vez).
 *  - Una nota crédito superó lo que debía esa factura.
 *
 * Ese dinero se lleva como un libro de movimientos y se consume solo
 * a medida que llegan facturas: se aplica primero a las más antiguas,
 * que es el orden en que se cobra una cartera.
 *
 * El saldo NUNCA se guarda como un número suelto: se calcula sumando
 * el libro. Así no puede quedar un saldo que no se pueda explicar
 * con sus movimientos.
 */
class CreditBalanceService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Saldo a favor disponible del contrato.
     */
    public function saldo(Contract $contract): float
    {
        $entradas = AccountCredit::where('contract_id', $contract->id)
            ->where('movement', AccountCredit::ENTRADA)
            ->sum('amount');

        $aplicaciones = AccountCredit::where('contract_id', $contract->id)
            ->where('movement', AccountCredit::APLICACION)
            ->sum('amount');

        return round((float) $entradas - (float) $aplicaciones, 2);
    }

    /**
     * Registra dinero a favor del contrato.
     *
     * @param  array{invoice_id?: int, payment_id?: int, credit_debit_note_id?: int}  $origenes
     */
    public function abonar(
        Contract $contract,
        float $monto,
        string $origen,
        string $descripcion,
        array $origenes = [],
    ): AccountCredit {
        $credito = AccountCredit::create(array_merge([
            'branch_id' => $contract->branch_id,
            'contract_id' => $contract->id,
            'user_id' => Auth::id(),
            'movement' => AccountCredit::ENTRADA,
            'origin' => $origen,
            'amount' => round($monto, 2),
            'description' => $descripcion,
        ], $origenes));

        $this->auditLogger->action(
            'invoices.credit_added',
            sprintf(
                'Registró $%s a favor del contrato %s (%s)',
                number_format($monto, 2, ',', '.'),
                $contract->numero_visible,
                $descripcion,
            ),
            [
                'contrato' => $contract->numero_visible,
                'monto' => $monto,
                'origen' => $origen,
                'saldo_a_favor' => $this->saldo($contract),
            ],
            $credito,
            'facturacion',
        );

        return $credito;
    }

    /**
     * Usa el saldo a favor para pagar una factura.
     *
     * Aplica lo que alcance: si el saldo cubre toda la factura, queda
     * saldada; si no, se abona lo disponible y el resto sigue
     * pendiente de cobro.
     *
     * @return float Monto realmente aplicado
     */
    public function aplicarAFactura(Invoice $invoice): float
    {
        $contract = $invoice->contract;

        if (!$contract) {
            return 0.0;
        }

        return DB::transaction(function () use ($invoice, $contract) {
            $disponible = $this->saldo($contract);

            $factura = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $pendiente = (float) $factura->pending_invoice_amount;

            // Solo se aplica a facturas que realmente deben algo
            if ($disponible <= 0 || $pendiente <= 0
                || !in_array($factura->status, InvoiceStatus::payable(), true)) {
                return 0.0;
            }

            $aplicado = round(min($disponible, $pendiente), 2);
            $nuevoSaldo = round($pendiente - $aplicado, 2);

            AccountCredit::create([
                'branch_id' => $contract->branch_id,
                'contract_id' => $contract->id,
                'user_id' => Auth::id(),
                'movement' => AccountCredit::APLICACION,
                'origin' => AccountCredit::ORIGEN_ANTICIPO,
                'amount' => $aplicado,
                'invoice_id' => $factura->id,
                'description' => 'Aplicado a la factura ' . $factura->displayNumber(),
            ]);

            $factura->update([
                'pending_invoice_amount' => $nuevoSaldo,
                'status' => $nuevoSaldo <= 0
                    // El cliente sí puso el dinero (por adelantado):
                    // la factura queda pagada, no "saldada con nota".
                    ? InvoiceStatus::Pagada->value
                    : InvoiceStatus::PendienteParcial->value,
            ]);

            if ($nuevoSaldo <= 0) {
                $this->reactivarContratoSiCorresponde($contract);
            }

            $this->auditLogger->action(
                'invoices.credit_applied',
                sprintf(
                    'Aplicó $%s del saldo a favor a la factura %s',
                    number_format($aplicado, 2, ',', '.'),
                    $factura->displayNumber(),
                ),
                [
                    'factura' => $factura->displayNumber(),
                    'aplicado' => $aplicado,
                    'saldo_factura' => $nuevoSaldo,
                    'saldo_a_favor_restante' => $this->saldo($contract),
                ],
                $factura,
                'facturacion',
            );

            $invoice->refresh();

            return $aplicado;
        });
    }

    /**
     * Aplica el saldo a favor a todas las facturas abiertas del
     * contrato, de la más antigua a la más reciente.
     *
     * @return float Total aplicado
     */
    public function aplicarAFacturasAbiertas(Contract $contract): float
    {
        $aplicadoTotal = 0.0;

        $facturas = Invoice::where('contract_id', $contract->id)
            ->whereIn('status', InvoiceStatus::payable())
            ->where('pending_invoice_amount', '>', 0)
            // Las más viejas primero: es como se abona una cartera
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        foreach ($facturas as $factura) {
            if ($this->saldo($contract) <= 0) {
                break;
            }

            $aplicadoTotal += $this->aplicarAFactura($factura);
        }

        return round($aplicadoTotal, 2);
    }

    /**
     * Un contrato en pre-suspensión que queda al día se reactiva.
     *
     * Se limita a ese caso a propósito: reconectar un servicio ya
     * cortado exige una orden técnica, y eso lo maneja el flujo de
     * pagos, no este servicio.
     */
    private function reactivarContratoSiCorresponde(Contract $contract): void
    {
        if ($contract->status !== ContractStatus::PreSuspension->value) {
            return;
        }

        $tienePendientes = Invoice::where('contract_id', $contract->id)
            ->whereIn('status', InvoiceStatus::payable())
            ->where('pending_invoice_amount', '>', 0)
            ->exists();

        if (!$tienePendientes) {
            $contract->update([
                'status' => ContractStatus::Activo->value,
                'suspension_warning_date' => null,
            ]);
        }
    }
}
