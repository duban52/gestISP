<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Models\BranchBillingSetting;
use App\Models\Contract;
use App\Models\Invoice;
use Carbon\Carbon;

/**
 * Procesador de mora.
 *
 * Concentra las dos operaciones de cobranza que antes estaban
 * duplicadas entre InvoiceController::index() y
 * updateOverdueInvoices():
 *
 * 1. Marcar como Vencida toda factura pendiente cuya fecha de
 *    vencimiento ya pasó (regla global: depende solo de la fecha,
 *    no de la sucursal).
 *
 * 2. Refrescar el conteo de vencidas de los contratos y suspender
 *    los que alcanzan el umbral. Esta operación SÍ se limita a una
 *    sucursal: la versión anterior recorría los contratos de TODAS
 *    las sucursales y suspendía contratos ajenos como efecto
 *    colateral de generar facturas en una.
 *
 * El umbral de facturas vencidas para suspender se lee de la
 * configuración de la sucursal (BranchBillingSetting). En fase 5
 * estas operaciones pasarán a un comando programado diario.
 */
class OverdueProcessor
{

    /**
     * Marca como Vencida toda factura pendiente con fecha de
     * vencimiento anterior a hoy.
     */
    public function markOverdueInvoices(): int
    {
        return Invoice::whereIn('status', InvoiceStatus::overdueCandidates())
            ->whereDate('due_date', '<', Carbon::today())
            ->update(['status' => InvoiceStatus::Vencida->value]);
    }

    /**
     * Actualiza el conteo de facturas vencidas de los contratos de
     * la sucursal y suspende los que alcanzan el umbral.
     *
     * Solo escribe cuando algo cambió (la versión anterior hacía
     * un UPDATE por contrato en cada corrida, cambiara o no).
     */
    public function refreshContractSuspensions(int $branchId): void
    {
        $threshold = BranchBillingSetting::forBranch($branchId)->suspension_threshold;

        // LOS TERMINADOS NO SE SUSPENDEN. Un retirado, un anulado o un
        // cedido pueden tener facturas vencidas —el cedente que no pagó
        // su cierre, el retirado que se fue debiendo— y se le siguen
        // cobrando. Pero pasarlos a «Suspendido» les borraría el estado
        // que dice que terminaron, y un cedido parecería de nuevo un
        // contrato vivo con los equipos de otro. Con la mora corriendo
        // sola cada madrugada, eso pasaría sin que nadie lo tocara.
        $contracts = Contract::where('branch_id', $branchId)
            ->whereNotIn('status', ContractStatus::finales())
            ->withCount([
                'invoices as overdue_count' => fn ($query) => $query
                    ->where('status', InvoiceStatus::Vencida->value),
            ])
            ->get();

        foreach ($contracts as $contract) {
            $shouldSuspend = $contract->overdue_count >= $threshold
                && $contract->status !== ContractStatus::Suspendido->value;

            if ($shouldSuspend) {
                $contract->update([
                    'status' => ContractStatus::Suspendido->value,
                    'overdue_invoices_count' => $contract->overdue_count,
                ]);
            } elseif ((int) $contract->overdue_invoices_count !== (int) $contract->overdue_count) {
                $contract->update([
                    'overdue_invoices_count' => $contract->overdue_count,
                ]);
            }
        }
    }
}
