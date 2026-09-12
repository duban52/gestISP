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
        // El UNICO sitio que decide si una factura es electronica.
        private readonly ElectronicInvoicingDecider $decider,
        private readonly CreditBalanceService $creditBalance,
    ) {
    }

    /**
     * Genera la factura del período actual para un contrato.
     *
     * @return array{generated: bool, reason?: string, invoice_id?: int}
     */
    public function generateForContract(
        Contract $contract,
        CarbonInterface $today,
        ?int $userId,
        ?int $billingRunId = null,
    ): array {
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

        // ---- No se emite una factura sin nada dentro ----
        //
        // Pasa cuando el contrato se quedó sin plan: `plan_id` es nulo
        // con ON DELETE SET NULL, así que basta con que alguien borre
        // un plan. La factura salía igual, con cero renglones y total
        // cero.
        //
        // En una interna es un documento absurdo. En una ELECTRÓNICA es
        // caro: gasta un consecutivo del rango autorizado —que no se
        // recupera— en un XML que además el XSD de la DIAN rechaza,
        // porque un `Invoice` sin `InvoiceLine` no es válido. Queda el
        // hueco en la numeración y ningún documento que enseñar.
        if (!$this->hayAlgoQueFacturar($contract)) {
            return ['generated' => false, 'reason' => 'Nothing to bill'];
        }

        $settings = BranchBillingSetting::forBranch($contract->branch_id);

        $invoice = DB::transaction(function () use ($contract, $today, $userId, $yearMonth, $settings, $billingRunId) {
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
                // La decision se toma UNA vez, aqui, y se congela.
                // Despues lo que vale es lo que quedo guardado: si el
                // contrato cambia de grupo, esta factura no se entera.
                'affinity_group_id' => $contract->affinity_group_id,
                // EL MEDIO DE PAGO DEL GRUPO, CONGELADO AQUÍ.
                //
                // El grupo lo deja configurar desde hace tiempo, pero
                // nadie lo copiaba a la factura: el XML acababa
                // declarando siempre «10» (efectivo) aunque en el grupo
                // dijera otra cosa. Se congela como el resto de lo
                // fiscal — si mañana cambia el grupo, esta factura
                // sigue diciendo lo que dijo.
                'payment_means_code' => $contract->affinityGroup?->default_payment_means_code,
                'document_kind' => $this->decider->tipoPara($contract),
                // Corrida que la generó (null si se creó por otra vía)
                'billing_run_id' => $billingRunId,
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

        // Si el cliente tiene saldo a favor (pagó por adelantado o le
        // quedó a favor una nota crédito), la factura se abona sola.
        // Es lo que hace que "pagar seis meses" funcione: cada mes la
        // factura nueva se salda con ese dinero hasta agotarlo.
        $this->creditBalance->aplicarAFactura($invoice);

        return ['generated' => true, 'invoice_id' => $invoice->id, 'invoice' => $invoice->refresh()];
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
     * ¿Hay algo que facturarle a este contrato?
     *
     * Las dos fuentes son las mismas que usa `addItems()`, y tienen que
     * seguir siéndolo: si un día se factura algo más, hay que añadirlo
     * también aquí o volverán las facturas vacías.
     */
    private function hayAlgoQueFacturar(Contract $contract): bool
    {
        if ($contract->plan?->services()->exists()) {
            return true;
        }

        // Misma consulta que abajo, literalmente: la relación y el
        // mismo estado en minúscula. Escribirla de otra forma es como
        // se separan con el tiempo.
        return $contract->additionalCharges()
            ->where('status', 'pendiente')
            ->exists();
    }

    /**
     * EL REDONDEO VA EN EL RENGLÓN, NO EN EL TOTAL.
     *
     * Las columnas de dinero son `decimal(15,2)`, así que la base de
     * datos redondea SÍ O SÍ. La única decisión es si redondeamos
     * nosotros, una vez y de forma consistente, o si deja que MySQL
     * redondee cada columna por su cuenta.
     *
     * Dejándoselo a MySQL, `subtotal`, `tax` y `total` se redondean por
     * separado a partir de valores con todos sus decimales, y la suma de
     * redondeos no es el redondeo de la suma. Con un mes prorrateado y
     * IVA —único caso donde la base deja de ser un número redondo— eso
     * desajusta un centavo y la DIAN rechaza con FAU14: «Valor a Pagar
     * de Factura es distinto de la Suma de Valor Bruto más tributos».
     *
     * Medido sobre las combinaciones reales de precio y días
     * prorrateados: fallaba en torno al 8% de los casos. Por eso solo
     * aparecía de vez en cuando, y solo en facturas prorrateadas con
     * IVA.
     *
     * Redondeando el renglón y construyendo los totales a partir de los
     * renglones ya redondeados, se cumplen a la vez las tres cosas que
     * mira la DIAN, y por construcción y no por suerte:
     *
     *   · suma de LineExtensionAmount de las líneas = Valor Bruto
     *   · suma de los IVA de las líneas             = tributos
     *   · Valor Bruto + tributos                    = Valor a Pagar
     *
     * Y de paso el IVA de cada línea es exactamente su base declarada
     * por la tarifa, que es lo que exige FAS07.
     *
     * Crea los ítems de la factura y devuelve los totales:
     * servicios del plan (prorrateados, con IVA) y cargos
     * adicionales pendientes del contrato.
     *
     * @return array{subtotal: float, tax: float, total: float}
     */
    private function addItems(Invoice $invoice, Contract $contract, float|int $prorateMultiplier): array
    {
        $subtotal = 0.0;
        $tax = 0.0;
        $total = 0.0;

        // Servicios del plan
        if ($contract->plan && $contract->plan->services) {
            foreach ($contract->plan->services as $service) {
                // SE REDONDEA AQUÍ, EN EL RENGLÓN. Ver el comentario de
                // `addItems()`: acumular con todos los decimales y dejar
                // que la base de datos redondee cada columna por su
                // cuenta es lo que rompía FAU14.
                $basePrice = round($service->base_price * $prorateMultiplier, 2);
                $taxAmount = $service->tax_percentage > 0
                    ? round($basePrice * ($service->tax_percentage / 100), 2)
                    : 0.0;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $service->name,
                    // Los datos fiscales se COPIAN aqui, no se leen
                    // despues del servicio: si manana se corrige el
                    // UNSPSC, esta factura tiene que seguir diciendo lo
                    // que decia cuando se emitio. Ademas su XML ya se
                    // habra transmitido a la DIAN.
                    'product_code' => $service->product_code,
                    'product_code_type' => $service->product_code_type,
                    'unit_measure_code' => $service->unit_measure_code,
                    // Como se trato el IVA, congelado igual que el
                    // codigo de producto: la factura emitida tiene que
                    // seguir diciendolo aunque manana se reclasifique el
                    // servicio. Su XML ya se transmitio.
                    'tax_classification' => $service->clasificacion()->value,
                    'quantity' => 1,
                    // EL PRECIO PRORRATEADO, NO EL COMPLETO.
                    //
                    // Aqui iba `base_price` a secas mientras el IVA se
                    // calculaba sobre la base PRORRATEADA. En un mes
                    // partido eso deja la factura contradiciendose: el
                    // XML declara base = precio completo e IVA = el de
                    // media base, y la DIAN lo rechaza con FAS07 —«el
                    // valor del tributo informado no corresponde al
                    // producto de la base gravable por la tarifa»—.
                    //
                    // Paso de verdad, con una factura de la corrida
                    // mensual. Las individuales no fallaban porque solo
                    // se prorratea el PRIMER mes de un contrato.
                    'unit_price' => $basePrice,
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
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            // EL TOTAL SE DERIVA, no se acumula aparte.
            //
            // Es la ecuación que comprueba la DIAN en FAU14 —«Valor a
            // Pagar = Valor Bruto + tributos − descuentos + cargos»— y
            // derivándola no puede dejar de cumplirse. Acumularla por
            // separado la hacía depender de que dos caminos distintos
            // redondearan igual, y el 8% de las veces no lo hacían.
            //
            // `round()` final contra el error de representación del
            // float: 0.1 + 0.2 no es 0.3 ni en PHP ni en ningún sitio.
            //
            // Si algún día hay descuentos, es AQUÍ donde tienen que
            // restarse, o la ecuación deja de cerrar.
            'total' => round($subtotal + $tax, 2),
        ];
    }
}
