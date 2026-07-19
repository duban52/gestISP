<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
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
     * @return array{total_contracts: int, generated: int, skipped: int}
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

        foreach ($contracts as $contract) {
            try {
                $result = $this->invoiceGenerator->generateForContract($contract, $today, $userId);

                $result['generated'] ? $generated++ : $skipped++;
            } catch (\Exception $e) {
                Log::error("Error generando factura para contrato {$contract->id}: " . $e->getMessage());
                $skipped++;
            }
        }

        return [
            'total_contracts' => $contracts->count(),
            'generated' => $generated,
            'skipped' => $skipped,
        ];
    }
}
