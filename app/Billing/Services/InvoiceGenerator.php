<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\InvoiceType;
use App\Billing\Events\InvoiceIssued;
use App\Models\BranchBillingSetting;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generador de la factura mensual de UN contrato.
 *
 * Cada factura cobra ÚNICAMENTE su período (servicios del plan
 * prorrateados según la configuración de la sucursal + cargos
 * adicionales pendientes). Las facturas vencidas anteriores NO se
 * absorben: quedan abiertas y cobrables de forma independiente, y
 * la deuda total del cliente se lee en
 * Contract::outstandingBalance(). (El patrón histórico de
 * absorción re-facturaba ingresos ya facturados — incompatible
 * con facturación electrónica DIAN — y se eliminó en fase 4.)
 *
 * Si el contrato tiene vencidas, la factura nueva nace con estado
 * "Pendiente con riesgo de corte" y el contrato pasa a
 * pre-suspensión con su fecha de aviso.
 *
 * Cada factura se crea en transacción y recibe su número formal
 * de la secuencia de la sucursal (InvoiceNumerator, con la fila
 * bloqueada). Al emitirse se dispara InvoiceIssued — punto de
 * enganche de la facturación electrónica (fase 6).
 *
 * Las reglas (plazo, corte, prorrateo) se leen de la
 * configuración de la sucursal (BranchBillingSetting::forBranch),
 * editable sin tocar código.
 */
class InvoiceGenerator
{
    public function __construct(
        private readonly InvoiceNumerator $numerator,
    ) {
    }

    /**
     * Genera la factura del período actual para un contrato.
     *
     * @return array{generated: bool, reason?: string, invoice_id?: int}
     */
    public function generateForContract(Contract $contract, CarbonInterface $today, ?int $userId): array
    {
        if ($contract->status === ContractStatus::Suspendido->value) {
            return ['generated' => false, 'reason' => 'Contract suspended'];
        }

        $yearMonth = $today->format('Ym');

        $alreadyBilled = Invoice::where('contract_id', $contract->id)
            ->where('billed_year_month', $yearMonth)
            ->exists();

        if ($alreadyBilled) {
            return ['generated' => false, 'reason' => 'Invoice already exists for this period'];
        }

        $settings = BranchBillingSetting::forBranch($contract->branch_id);

        $invoice = DB::transaction(function () use ($contract, $today, $userId, $yearMonth, $settings) {
            $startOfMonth = $today->copy()->startOfMonth();
            $endOfMonth = $today->copy()->endOfMonth();

            // Vencidas abiertas del contrato: definen el estado de
            // riesgo de la factura nueva, pero NO se absorben
            $hasOverdue = Invoice::where('contract_id', $contract->id)
                ->where('status', InvoiceStatus::Vencida->value)
                ->exists();

            $suspensionDate = $hasOverdue ? $today->copy()->addDays($settings->suspension_days) : null;

            $period = $this->calculateBillingPeriod($contract, $startOfMonth, $endOfMonth, $settings->prorates());

            $invoice = Invoice::create([
                'contract_id' => $contract->id,
                'branch_id' => $contract->branch_id,
                'type' => InvoiceType::Mensualidad->value,
                'user_id' => $userId,
                'issue_date' => $today,
                'due_date' => $today->copy()->addDays($settings->due_days),
                'billed_period' => $period['period_full'],
                'billed_period_short' => $period['period_short'],
                'billed_month_name' => ucfirst($today->translatedFormat('F')),
                'billed_year_month' => $yearMonth,
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'suspension_date' => $suspensionDate,
                'subtotal' => 0,
                'discount' => 0,
                'tax' => 0,
                'total' => 0,
                'pending_invoice_amount' => 0,
                'status' => $hasOverdue
                    ? InvoiceStatus::PendienteRiesgoCorte->value
                    : InvoiceStatus::Pendiente->value,
                'service_suspension_warning' => $hasOverdue,
            ]);

            $totals = $this->addItems($invoice, $contract, $period['prorate_multiplier']);

            $invoice->update([
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                // Semántica única: saldo = total − pagado (recién
                // emitida, nada pagado)
                'pending_invoice_amount' => $totals['total'],
            ]);

            // Número formal de la secuencia de la sucursal
            $this->numerator->assign($invoice);

            // Contrato con vencidas: pasa a pre-suspensión con su
            // fecha de aviso
            if ($hasOverdue && $contract->status !== ContractStatus::PreSuspension->value) {
                $contract->update([
                    'status' => ContractStatus::PreSuspension->value,
                    'suspension_warning_date' => $suspensionDate,
                ]);
            }

            return $invoice;
        });

        InvoiceIssued::dispatch($invoice);

        return ['generated' => true, 'invoice_id' => $invoice->id, 'invoice' => $invoice];
    }

    /**
     * Calcula el período facturado y el multiplicador de prorrateo.
     *
     * Con prorrateo activo (opción B), un contrato activado a mitad
     * de mes factura solo los días restantes (multiplicador
     * días_restantes / días_del_mes). Con mes completo (opción A)
     * el multiplicador es siempre 1 y el período cubre todo el mes,
     * sin importar el día de activación.
     *
     * @return array{period_full: string, period_short: string, period_start: string, period_end: string, prorate_multiplier: float|int}
     */
    private function calculateBillingPeriod(Contract $contract, CarbonInterface $startOfMonth, CarbonInterface $endOfMonth, bool $prorates): array
    {
        $daysInMonth = $startOfMonth->diffInDays($endOfMonth) + 1;
        $prorateMultiplier = 1;
        $periodStart = $startOfMonth->copy();

        if ($prorates && $contract->activation_date && $contract->activation_date > $startOfMonth) {
            $activationDate = Carbon::parse($contract->activation_date);

            if ($activationDate->isSameMonth($startOfMonth)) {
                $remainingDays = $activationDate->diffInDays($endOfMonth) + 1;
                $prorateMultiplier = $remainingDays / $daysInMonth;
                $periodStart = $activationDate->copy();
            }
        }

        return [
            'period_full' => $periodStart->format('d M') . ' al ' . $endOfMonth->format('d M Y'),
            'period_short' => $periodStart->format('d') . ' al ' . $endOfMonth->format('d'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $endOfMonth->toDateString(),
            'prorate_multiplier' => $prorateMultiplier,
        ];
    }

    /**
     * Crea los ítems de la factura y devuelve los totales:
     * servicios del plan (prorrateados, con IVA) y cargos
     * adicionales pendientes del contrato.
     *
     * @return array{subtotal: float, tax: float, total: float}
     */
    private function addItems(Invoice $invoice, Contract $contract, float|int $prorateMultiplier): array
    {
        $subtotal = 0;
        $tax = 0;
        $total = 0;

        // Servicios del plan
        if ($contract->plan && $contract->plan->services) {
            foreach ($contract->plan->services as $service) {
                $basePrice = $service->base_price * $prorateMultiplier;
                $taxAmount = $service->tax_percentage > 0
                    ? $basePrice * ($service->tax_percentage / 100)
                    : 0;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $service->name,
                    'quantity' => 1,
                    'unit_price' => $service->base_price,
                    'percentage_tax' => $service->tax_percentage,
                    'tax' => $taxAmount,
                    'total' => $basePrice + $taxAmount,
                ]);

                $subtotal += $basePrice;
                $tax += $taxAmount;
                $total += $basePrice + $taxAmount;
            }
        }

        // Cargos adicionales pendientes del contrato.
        // De contado: monto completo y el cargo queda Facturado.
        // Diferido a cuotas: entra UNA cuota por mes ("cuota X/N",
        // la última ajusta el redondeo); el cargo sigue pendiente
        // hasta completar las cuotas.
        $pendingCharges = $contract->additionalCharges()
            ->where('status', 'pendiente')
            ->get();

        foreach ($pendingCharges as $charge) {
            if ($charge->isDeferred()) {
                $n = $charge->installments_billed + 1;
                $installment = $charge->amountForInstallment($n);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => "{$charge->description} (cuota {$n}/{$charge->installments_total})",
                    'quantity' => 1,
                    'unit_price' => $installment,
                    'percentage_tax' => 0,
                    'tax' => 0,
                    'total' => $installment,
                ]);

                $charge->update([
                    'installments_billed' => $n,
                    'status' => $n >= $charge->installments_total ? 'Facturado' : 'pendiente',
                ]);

                $subtotal += $installment;
                $total += $installment;
            } else {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $charge->description,
                    'quantity' => 1,
                    'unit_price' => $charge->amount,
                    'percentage_tax' => 0,
                    'tax' => 0,
                    'total' => $charge->amount,
                ]);

                $charge->update(['status' => 'Facturado']);
                $subtotal += $charge->amount;
                $total += $charge->amount;
            }
        }

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ];
    }
}
