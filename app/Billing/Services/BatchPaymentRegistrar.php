<?php

namespace App\Billing\Services;

use App\Models\CashRegister;
use App\Models\Invoice;
use App\Models\PaymentBatch;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cobro de varias facturas en una sola operación.
 *
 * El caso que resuelve es cotidiano en un punto de atención: alguien
 * llega y paga el servicio de su mamá, su hermana y su abuela. Son
 * contratos distintos y cada uno tiene derecho a SU recibo, pero es
 * una sola entrega de dinero y debe cuadrarse como tal.
 *
 * Reglas que impone:
 *
 *  - TODO o NADA. Las facturas se cobran dentro de una sola
 *    transacción: si la tercera falla (por ejemplo, porque otro
 *    cajero acaba de cobrarla), no queda cobrada ninguna. Lo
 *    contrario dejaría al cliente con dinero entregado y facturas a
 *    medio pagar, que es imposible de deshacer en el mostrador.
 *  - Un pago por factura. No se fusionan importes: cada factura
 *    conserva su propio pago, su propio recibo y su propia
 *    retención si la hubo.
 *  - Todos los pagos comparten método de pago y referencia, porque
 *    el dinero llegó junto.
 *
 * Lo que NO hace: cobrar dos veces la misma factura en el mismo lote
 * (se rechaza) ni mezclar sucursales (cada factura se valida contra
 * la sucursal activa en el cobro).
 */
class BatchPaymentRegistrar
{
    public function __construct(
        private readonly PaymentRegistrar $registrar,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Cobra un lote de facturas.
     *
     * @param  array{
     *     items: array<int, array{invoice_id: int, amount: float|string, retentions?: array<int, array<string, mixed>>}>,
     *     payment_method: string,
     *     reference_number?: ?string,
     *     notes?: ?string,
     *     payer_name?: ?string,
     *     payer_document?: ?string,
     *     payer_phone?: ?string,
     * }  $data
     * @return PaymentBatch Lote con sus pagos ya cargados
     */
    public function register(array $data, ?int $userId, ?int $branchId): PaymentBatch
    {
        $items = $data['items'] ?? [];

        if (empty($items)) {
            throw new RuntimeException('No se seleccionó ninguna factura para cobrar.');
        }

        $ids = array_map(fn ($item) => (int) $item['invoice_id'], $items);

        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Hay una factura repetida en el cobro. Revise la selección.');
        }

        // Se valida la caja aquí también, y no solo dentro de cada
        // pago, para no abrir la transacción si de entrada no va a
        // poder cobrarse nada.
        $caja = CashRegister::where('status', 'open')
            ->where('user_id', $userId)
            ->first();

        if (!$caja) {
            throw new RuntimeException('No hay una caja abierta para recibir pagos. Abra su caja antes de cobrar.');
        }

        return DB::transaction(function () use ($items, $data, $userId, $branchId, $caja) {
            $lote = PaymentBatch::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'cash_register_id' => $caja->id,
                'payer_name' => $data['payer_name'] ?? null,
                'payer_document' => $data['payer_document'] ?? null,
                'payer_phone' => $data['payer_phone'] ?? null,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $totalEfectivo = 0.0;
            $totalRetenido = 0.0;
            $contratos = [];
            $resumen = [];

            foreach ($items as $item) {
                // El número de factura se resuelve antes de cobrar
                // para poder nombrarla en un mensaje de error legible.
                $factura = Invoice::with('contract')->find((int) $item['invoice_id']);

                if (!$factura) {
                    throw new RuntimeException('Una de las facturas seleccionadas ya no existe.');
                }

                try {
                    $pago = $this->registrar->register([
                        'invoice_id' => $factura->id,
                        'amount' => $item['amount'],
                        'payment_method' => $data['payment_method'],
                        'reference_number' => $data['reference_number'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'retentions' => $item['retentions'] ?? [],
                        'payment_batch_id' => $lote->id,
                    ], $userId, $branchId);
                } catch (RuntimeException $e) {
                    // Se identifica CUÁL factura falló: en un lote de
                    // cinco, "el monto excede el saldo" a secas no le
                    // sirve de nada al cajero.
                    throw new RuntimeException(sprintf(
                        'Factura %s (%s): %s',
                        $factura->displayNumber(),
                        $factura->contract?->client
                            ? trim($factura->contract->client->name . ' ' . $factura->contract->client->last_name)
                            : 'sin cliente',
                        $e->getMessage(),
                    ), 0, $e);
                }

                $retenido = $pago->totalRetenciones();

                $totalEfectivo += (float) $pago->amount;
                $totalRetenido += $retenido;
                $contratos[$factura->contract_id] = true;

                $resumen[] = [
                    'factura' => $factura->displayNumber(),
                    'contrato' => $factura->contract?->numero_visible,
                    'recibido' => (float) $pago->amount,
                    'retenido' => $retenido,
                ];
            }

            $lote->update([
                'total_amount' => round($totalEfectivo, 2),
                'total_retentions' => round($totalRetenido, 2),
                'payments_count' => count($items),
                'contracts_count' => count($contratos),
            ]);

            $this->auditLogger->action(
                'payments.batch_registered',
                sprintf(
                    'Cobró %d factura(s) de %d contrato(s) en un solo lote por $%s',
                    count($items),
                    count($contratos),
                    number_format($totalEfectivo, 2, ',', '.'),
                ),
                [
                    'lote' => $lote->numero_visible,
                    'pagador' => $lote->payer_name,
                    'metodo' => $data['payment_method'],
                    'total_recibido' => round($totalEfectivo, 2),
                    'total_retenido' => round($totalRetenido, 2),
                    'facturas' => $resumen,
                ],
                $lote,
                'facturacion',
            );

            return $lote->load([
                'payments.invoice.contract.client',
                'payments.invoice.invoice_items',
                'payments.retentions',
            ]);
        });
    }
}
