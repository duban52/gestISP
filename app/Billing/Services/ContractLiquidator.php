<?php

namespace App\Billing\Services;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\InvoiceType;
use App\Billing\Events\InvoiceIssued;
use App\Models\BranchBillingSetting;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * La última factura de un contrato que se da de baja.
 *
 * EL PROBLEMA
 * -----------
 * Un contrato retirado deja de ser facturable, y con razón. Pero sus
 * cargos adicionales pendientes —las cuotas que le quedaban a un
 * equipo diferido a doce meses— se quedaban ahí: nadie los volvía a
 * incluir en ninguna factura porque ya no había facturas. El cliente
 * se iba debiendo ese dinero y el sistema no lo reclamaba.
 *
 * QUÉ HACE
 * --------
 * Antes de que el contrato pase a su estado final, emite una factura
 * con TODO lo que quedaba por cobrar de sus cargos: el saldo completo
 * de cada uno, no una cuota más.
 *
 * QUÉ NO HACE
 * -----------
 * No devuelve nada del mes en curso. Si el mes ya se facturó y el
 * cliente se va el día 10, esa factura se queda como está: devolver
 * los días no usados es una decisión comercial, y cuando se quiera
 * hacer, la vía es una nota crédito por el concepto que corresponda.
 *
 * No la llama ningún controlador: la dispara el cambio de estado, que
 * es lo único que sabe de verdad que el contrato se está dando de
 * baja, venga de una orden de retiro o de una administrativa.
 */
class ContractLiquidator
{
    public function __construct(
        private readonly InvoiceNumerator $numerator,
        private readonly ElectronicInvoicingDecider $decider,
        private readonly CreditBalanceService $creditBalance,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Emite la factura de liquidación, si hay algo que liquidar.
     *
     * Devuelve null cuando el contrato no deja nada pendiente, que es
     * el caso normal: la mayoría se va sin cargos abiertos.
     */
    public function liquidar(Contract $contrato, ?int $userId = null): ?Invoice
    {
        $cargos = $contrato->additionalCharges()
            ->where('status', 'pendiente')
            ->get();

        if ($cargos->isEmpty()) {
            return null;
        }

        $hoy = now();
        $settings = BranchBillingSetting::forBranch($contrato->branch_id);

        $factura = DB::transaction(function () use ($contrato, $cargos, $hoy, $userId, $settings) {
            $factura = Invoice::create([
                'contract_id' => $contrato->id,
                'branch_id' => $contrato->branch_id,
                // Lo fiscal se congela igual que en la mensual: lo que
                // valga hoy es lo que dira este documento para siempre.
                'affinity_group_id' => $contrato->affinity_group_id,
                'payment_means_code' => $contrato->affinityGroup?->default_payment_means_code,
                'document_kind' => $this->decider->tipoPara($contrato),
                'type' => InvoiceType::Liquidacion->value,
                'user_id' => $userId,
                'issue_date' => $hoy,
                'due_date' => $hoy->copy()->addDays($settings->due_days),
                'billed_period' => 'Liquidación del contrato',
                'billed_period_short' => 'Liquidación',
                'billed_month_name' => ucfirst($hoy->translatedFormat('F')),
                // SIN PERIODO, y no es un olvido.
                //
                // Hay un unico (contract_id, billed_year_month) en la
                // base: un contrato no puede tener dos facturas del
                // mismo mes, que es lo que impide cobrar dos veces la
                // mensualidad. Una liquidacion no es «la factura de
                // septiembre» —es el cierre de la cuenta— y ponerle el
                // mes chocaria con la mensual que ya salio.
                'billed_year_month' => null,
                'period_start' => $hoy->toDateString(),
                'period_end' => $hoy->toDateString(),
                'subtotal' => 0,
                'discount' => 0,
                'tax' => 0,
                'total' => 0,
                'pending_invoice_amount' => 0,
                'status' => InvoiceStatus::Pendiente->value,
            ]);

            $subtotal = 0.0;
            $impuestos = 0.0;

            foreach ($cargos as $cargo) {
                // TODO lo que quedaba, no una cuota mas: el contrato se
                // acaba aqui y no habra mas facturas donde cobrarlo.
                $base = round($this->saldoDelCargo($cargo), 2);

                if ($base <= 0) {
                    continue;
                }

                $tarifa = (float) $cargo->tax_percentage;
                $impuesto = $tarifa > 0 ? round($base * ($tarifa / 100), 2) : 0.0;

                InvoiceItem::create([
                    'invoice_id' => $factura->id,
                    'aditional_charge_id' => $cargo->id,
                    'description' => $this->descripcion($cargo),
                    'tax_classification' => $cargo->clasificacion()->value,
                    'quantity' => 1,
                    'unit_price' => $base,
                    'percentage_tax' => $tarifa,
                    'tax' => $impuesto,
                    'total' => $base + $impuesto,
                ]);

                $cargo->update([
                    'installments_billed' => $cargo->installments_total ?? $cargo->installments_billed,
                    'status' => 'Facturado',
                ]);

                $subtotal += $base;
                $impuestos += $impuesto;
            }

            // Todos los cargos tenian saldo cero: no se emite un
            // documento vacio (en una electronica gastaria un
            // consecutivo del rango autorizado para nada).
            if ($subtotal <= 0) {
                $factura->delete();

                return null;
            }

            $total = round($subtotal + $impuestos, 2);

            $factura->update([
                'subtotal' => round($subtotal, 2),
                'tax' => round($impuestos, 2),
                'total' => $total,
                'pending_invoice_amount' => $total,
            ]);

            $this->numerator->assign($factura);

            return $factura;
        });

        if (!$factura) {
            return null;
        }

        InvoiceIssued::dispatch($factura);

        // Si le quedaba saldo a favor, se descuenta de lo que debe.
        $this->creditBalance->aplicarAFactura($factura);

        $this->auditLogger->action(
            'invoices.liquidated',
            sprintf(
                'Emitió la factura de liquidación %s del contrato %s por $%s al darlo de baja',
                $factura->displayNumber(),
                $contrato->numero_visible ?? $contrato->id,
                number_format((float) $factura->total, 2, ',', '.'),
            ),
            ['contrato' => $contrato->id, 'factura' => $factura->id],
            $factura,
            'facturacion',
        );

        return $factura->refresh();
    }

    /** Lo que queda por cobrar de un cargo. */
    private function saldoDelCargo($cargo): float
    {
        if (!$cargo->isDeferred()) {
            return (float) $cargo->amount;
        }

        $cobrado = 0.0;

        for ($n = 1; $n <= (int) $cargo->installments_billed; $n++) {
            $cobrado += $cargo->amountForInstallment($n);
        }

        return round((float) $cargo->amount - $cobrado, 2);
    }

    /** Qué dice el renglón, para que el cliente entienda qué paga. */
    private function descripcion($cargo): string
    {
        if (!$cargo->isDeferred()) {
            return $cargo->description;
        }

        $restantes = (int) $cargo->installments_total - (int) $cargo->installments_billed;

        return sprintf(
            '%s (liquidación: %d cuota(s) pendiente(s) de %d)',
            $cargo->description,
            $restantes,
            $cargo->installments_total,
        );
    }
}
