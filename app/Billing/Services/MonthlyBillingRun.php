<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\MassActions\Enums\MassActionItemStatus;
use App\MassActions\Enums\MassActionType;
use App\MassActions\MassActionRecorder;
use App\Models\BillingRun;
use App\Models\BranchBillingSetting;
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
        private readonly MassActionRecorder $recorder,
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

        // El período que va a cobrar esta corrida. No es el mes en que
        // se corre si la sucursal cobra por adelantado o vencido, y la
        // corrida tiene que quedar rotulada con lo que realmente
        // facturó: es la clave con la que el informe la busca y con la
        // que se comprueba que no se facture dos veces lo mismo.
        $periodo = BranchBillingSetting::forBranch($branchId)->mesFacturado($today)->format('Ym');

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

        // La corrida se registra ANTES de facturar (con los totales
        // en cero) para poder sellar cada factura con su id. Así el
        // reporte sabe exactamente qué facturas salieron de aquí, en
        // lugar de deducirlo por fecha.
        $run = $contracts->isNotEmpty()
            ? BillingRun::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'billed_year_month' => $periodo,
                'contracts_count' => $contracts->count(),
                'generated_count' => 0,
                'skipped_count' => 0,
                'total_subtotal' => 0,
                'total_tax' => 0,
                'total_billed' => 0,
                'executed_at' => $today,
            ])
            : null;

        // LA ACCIÓN MASIVA. `billing_runs` ya guardaba los totales de
        // la corrida, pero no QUÉ facturas salieron de ella con el
        // detalle que hace falta para deshacerla. Deshacer aquí no es
        // borrar —una factura gasta un consecutivo autorizado y puede
        // estar en los registros de la DIAN— sino ANULAR lo anulable;
        // ver `AnularCorridaDeFacturacion`.
        $accion = $this->recorder->abrir(
            MassActionType::CorridaDeFacturacion,
            sprintf('Corrida de facturación del período %s: %d contrato(s)', $periodo, $contracts->count()),
            summary: ['periodo' => $periodo, 'contratos' => $contracts->count()],
            source: $run,
            branchId: $branchId,
            userId: $userId,
        );

        foreach ($contracts as $contract) {
            try {
                $result = $this->invoiceGenerator->generateForContract($contract, $today, $userId, $run?->id);

                if ($result['generated']) {
                    $generated++;
                    $totalSubtotal += (float) $result['invoice']->subtotal;
                    $totalTax += (float) $result['invoice']->tax;
                    $totalBilled += (float) $result['invoice']->total;

                    // El sujeto es la FACTURA, no el contrato: es lo
                    // que habría que anular al revertir.
                    $this->recorder->registrar(
                        $accion,
                        $result['invoice'],
                        $result['invoice']->displayNumber(),
                        antes: ['existia' => false],
                        despues: [
                            'contrato' => $contract->numero_visible,
                            'total' => (float) $result['invoice']->total,
                            'periodo' => $result['invoice']->billed_year_month,
                        ],
                    );
                } else {
                    $skipped++;

                    $this->recorder->registrar(
                        $accion,
                        $contract,
                        $contract->numero_visible,
                        estado: MassActionItemStatus::Omitido,
                        mensaje: $result['reason'] ?? null,
                    );
                }
            } catch (\Exception $e) {
                Log::error("Error generando factura para contrato {$contract->numero_visible}: " . $e->getMessage());
                $skipped++;

                $this->recorder->registrar(
                    $accion,
                    $contract,
                    $contract->numero_visible,
                    estado: MassActionItemStatus::Error,
                    mensaje: $e->getMessage(),
                );
            }
        }

        $this->recorder->cerrar($accion);

        // Cerrar la corrida con los totales reales
        $run?->update([
            'generated_count' => $generated,
            'skipped_count' => $skipped,
            'total_subtotal' => $totalSubtotal,
            'total_tax' => $totalTax,
            'total_billed' => $totalBilled,
        ]);

        return [
            'total_contracts' => $contracts->count(),
            'generated' => $generated,
            'skipped' => $skipped,
            'total_subtotal' => $totalSubtotal,
            'total_tax' => $totalTax,
            'total_billed' => $totalBilled,
            // Permite llevar al usuario directo al detalle de lo que
            // acaba de generar y ofrecerle el reporte descargable
            'billing_run_id' => $run?->id,
        ];
    }
}
