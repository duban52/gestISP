<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Models\BillingRun;
use App\Models\Contract;
use Illuminate\Support\Facades\Log;

/**
 * Corrida de facturación mensual de una sucursal.
 *
 * Orquesta el proceso completo que dispara el botón "Generar
 * facturas" (y que en fase 5 ejecutará también un comando
 * programado):
 *
 *  1. Marcar facturas vencidas (global, regla de fecha).
 *  2. Refrescar suspensiones de contratos DE LA SUCURSAL (la
 *     versión anterior suspendía contratos de otras sucursales).
 *  3. Generar la factura del período para cada contrato facturable.
 *  4. Registrar la corrida en billing_runs con sus totales
 *     (facturas generadas/omitidas, subtotal, IVA y total
 *     facturado) — la base del reporte gerencial de facturación.
 *
 * Un contrato que falle no aborta la corrida: se registra en el
 * log y se continúa con el resto.
 */
class MonthlyBillingRun
{
    public function __construct(
        private readonly OverdueProcessor $overdueProcessor,
        private readonly InvoiceGenerator $invoiceGenerator,
    ) {
    }

    /**
     * Ejecuta la corrida para una sucursal.
     *
     * @return array{total_contracts: int, generated: int, skipped: int, total_billed: float, total_tax: float, total_subtotal: float}
     */
    public function runForBranch(int $branchId, ?int $userId): array
    {
        $today = now();

        $this->overdueProcessor->markOverdueInvoices();
        $this->overdueProcessor->refreshContractSuspensions($branchId);

        // Se mantiene el criterio histórico de sucursal vía cliente
        // (los contratos también tienen branch_id propio; unificar
        // ambas fuentes es trabajo de una fase posterior)
        $contracts = Contract::with(['client', 'plan.services', 'additionalCharges'])
            ->whereIn('status', ContractStatus::billable())
            ->whereHas('client', fn ($query) => $query->where('branch_id', $branchId))
            ->get();

        $generated = 0;
        $skipped = 0;
        $totalSubtotal = 0.0;
        $totalTax = 0.0;
        $totalBilled = 0.0;

        foreach ($contracts as $contract) {
            try {
                $result = $this->invoiceGenerator->generateForContract($contract, $today, $userId);

                if ($result['generated']) {
                    $generated++;
                    $totalSubtotal += (float) $result['invoice']->subtotal;
                    $totalTax += (float) $result['invoice']->tax;
                    $totalBilled += (float) $result['invoice']->total;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                Log::error("Error generando factura para contrato {$contract->id}: " . $e->getMessage());
                $skipped++;
            }
        }

        // Registrar la corrida para el reporte de facturación
        if ($contracts->isNotEmpty()) {
            BillingRun::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'billed_year_month' => $today->format('Ym'),
                'contracts_count' => $contracts->count(),
                'generated_count' => $generated,
                'skipped_count' => $skipped,
                'total_subtotal' => $totalSubtotal,
                'total_tax' => $totalTax,
                'total_billed' => $totalBilled,
                'executed_at' => $today,
            ]);
        }

        return [
            'total_contracts' => $contracts->count(),
            'generated' => $generated,
            'skipped' => $skipped,
            'total_subtotal' => $totalSubtotal,
            'total_tax' => $totalTax,
            'total_billed' => $totalBilled,
        ];
    }
}
