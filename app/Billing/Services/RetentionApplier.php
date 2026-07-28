<?php

namespace App\Billing\Services;

use App\Billing\Enums\RetentionType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRetention;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Registro de las retenciones que un cliente practica al pagar.
 *
 * El fundamento contable está explicado en
 * App\Billing\Enums\RetentionType. Lo que este servicio garantiza:
 *
 *  - Las retenciones ABONAN la factura (por eso Invoice::getPendingAmount
 *    las suma junto con los pagos), pero NO entran a la caja: el
 *    movimiento de caja solo registra el efectivo recibido.
 *  - Efectivo + retenciones nunca puede superar el saldo de la
 *    factura. Si lo superara, estaríamos dando por cobrado más de lo
 *    que se facturó.
 *  - Cada retención queda con base, tarifa y concepto, que es lo que
 *    permitirá cruzarla después contra el certificado del cliente.
 *
 * Se ejecuta DENTRO de la transacción que abre el cobro.
 */
class RetentionApplier
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Valida y registra las retenciones de un pago.
     *
     * @param  array<int, array{type: string, concept_code?: ?string, base: float|string, rate: float|string, amount?: float|string|null, certificate_number?: ?string, notes?: ?string}>  $lineas
     * @return array<int, PaymentRetention>
     */
    public function aplicar(Payment $payment, Invoice $invoice, array $lineas): array
    {
        if (empty($lineas)) {
            return [];
        }

        $registradas = [];

        foreach ($lineas as $linea) {
            $registradas[] = $this->registrarLinea($payment, $invoice, $linea);
        }

        // El saldo se recalcula al final: el hook de Payment ya corrió
        // cuando se creó el pago y no alcanzó a ver estas filas.
        $invoice->recalcularSaldo();

        $total = array_sum(array_map(fn (PaymentRetention $r) => (float) $r->amount, $registradas));

        $this->auditLogger->action(
            'payments.retention_applied',
            sprintf(
                'Aplicó %d retención(es) por $%s sobre la factura %s',
                count($registradas),
                number_format($total, 2, ',', '.'),
                $invoice->displayNumber(),
            ),
            [
                'factura' => $invoice->displayNumber(),
                'contrato' => $invoice->contract?->numero_visible,
                'total_retenido' => $total,
                'efectivo_recibido' => (float) $payment->amount,
                'detalle' => array_map(fn (PaymentRetention $r) => [
                    'tipo' => $r->type,
                    'concepto' => $r->concept_label,
                    'base' => (float) $r->base,
                    'tarifa' => (float) $r->rate,
                    'valor' => (float) $r->amount,
                    'certificado' => $r->certificate_number,
                ], $registradas),
            ],
            $invoice,
            'facturacion',
        );

        return $registradas;
    }

    /**
     * Valor total que suman unas líneas de retención, sin registrarlas.
     *
     * Lo usa el cobro para validar el monto ANTES de tocar nada.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function totalDe(array $lineas): float
    {
        $total = 0.0;

        foreach ($lineas as $linea) {
            $total += $this->valorDeLinea($linea);
        }

        return round($total, 2);
    }

    /**
     * Crea una línea de retención ya validada.
     *
     * @param  array<string, mixed>  $linea
     */
    private function registrarLinea(Payment $payment, Invoice $invoice, array $linea): PaymentRetention
    {
        $tipo = RetentionType::tryFrom((string) ($linea['type'] ?? ''));

        if (!$tipo) {
            throw new RuntimeException('Tipo de retención no reconocido: ' . ($linea['type'] ?? '(vacío)') . '.');
        }

        $base = round((float) ($linea['base'] ?? 0), 2);
        $tarifa = round((float) ($linea['rate'] ?? 0), 3);
        $valor = $this->valorDeLinea($linea);

        if ($base <= 0) {
            throw new RuntimeException('La base de la retención debe ser mayor que cero.');
        }

        if ($valor <= 0) {
            throw new RuntimeException('El valor de la retención debe ser mayor que cero.');
        }

        // Una retención es un porcentaje de su base: no puede salir
        // más grande que la base sobre la que se calcula.
        if ($valor > $base + 0.001) {
            throw new RuntimeException(sprintf(
                'La retención ($%s) no puede superar su base ($%s).',
                number_format($valor, 2, ',', '.'),
                number_format($base, 2, ',', '.'),
            ));
        }

        $codigo = $linea['concept_code'] ?? null;

        return PaymentRetention::create([
            'branch_id' => $invoice->branch_id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'contract_id' => $invoice->contract_id,
            'user_id' => Auth::id(),
            'type' => $tipo->value,
            'concept_code' => $codigo,
            // Se guarda el TEXTO del concepto, no solo su código: si
            // mañana cambia el catálogo, el documento emitido conserva
            // lo que decía cuando se practicó la retención.
            'concept_label' => $tipo->concepto($codigo) ?? ($linea['concept_label'] ?? null),
            'base' => $base,
            'rate' => $tarifa,
            'amount' => $valor,
            'certificate_number' => $linea['certificate_number'] ?? null,
            'notes' => $linea['notes'] ?? null,
        ]);
    }

    /**
     * Valor de una línea.
     *
     * Se calcula desde la base y la tarifa, PERO se respeta el valor
     * explícito si viene: el documento que manda es el certificado
     * de retención del cliente, y sus centavos pueden no coincidir
     * exactamente con base × tarifa por redondeos o por bases
     * depuradas. Guardar lo que dice el certificado es lo que permite
     * cruzarlo después sin diferencias.
     *
     * @param  array<string, mixed>  $linea
     */
    private function valorDeLinea(array $linea): float
    {
        $explicito = $linea['amount'] ?? null;

        if ($explicito !== null && $explicito !== '' && (float) $explicito > 0) {
            return round((float) $explicito, 2);
        }

        $base = (float) ($linea['base'] ?? 0);
        $tarifa = (float) ($linea['rate'] ?? 0);

        return round($base * $tarifa / 100, 2);
    }
}
