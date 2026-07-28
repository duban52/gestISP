<?php

namespace App\Support;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\CreditBalanceService;
use App\Models\Invoice;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Armado del recibo de caja en formato de tirilla térmica.
 *
 * UN RECIBO POR CONTRATO, NO POR FACTURA
 * --------------------------------------
 * Si alguien paga tres meses atrasados de un mismo contrato, eso es
 * UN recibo con tres líneas —así lo espera el cliente y así se
 * imprime en cualquier punto de pago—. Si en cambio paga el servicio
 * de su mamá, su hermana y su abuela, son TRES contratos y por tanto
 * tres recibos: cada titular tiene derecho a su comprobante.
 *
 * Por eso el armado agrupa los pagos por contrato y no por pago.
 *
 * FORMATO
 * -------
 * Rollo de 80 mm, que es el estándar de las impresoras térmicas de
 * mostrador. El alto no es fijo: la tirilla se corta donde termina el
 * contenido, así que se estima a partir de las líneas que va a
 * ocupar. Estimar de menos corta el recibo; estimar de más desperdicia
 * papel en cada cobro, así que el margen se deja corto a propósito.
 */
class PaymentReceipt
{
    /** Ancho del rollo en puntos (80 mm). */
    private const ANCHO_PT = 226.77;

    /**
     * Arma los recibos correspondientes a un conjunto de pagos.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, array<string, mixed>> Un elemento por contrato
     */
    public static function build(Collection $payments): array
    {
        // Se normaliza a colección de Eloquent: quien llama puede
        // pasar un collect([$pago]) suelto, que no sabe precargar
        // relaciones y dispararía una consulta por cada dato del
        // recibo.
        $payments = EloquentCollection::make($payments->all());

        $payments->loadMissing([
            'invoice.contract.client',
            'invoice.contract.branch',
            'invoice.invoice_items',
            'retentions',
            'user',
            'cashRegister',
            'batch',
        ]);

        $saldos = app(CreditBalanceService::class);

        $recibos = [];

        // Se agrupa por contrato; los anticipos (sin factura) traen el
        // contrato directamente en el pago.
        foreach ($payments->groupBy(fn (Payment $p) => $p->invoice?->contract_id ?? $p->contract_id) as $contratoId => $delContrato) {
            /** @var Collection<int, Payment> $delContrato */
            $primero = $delContrato->first();
            $contrato = $primero->invoice?->contract ?? $primero->contract;

            $lineas = [];
            $retenciones = [];
            $totalEfectivo = 0.0;

            foreach ($delContrato->sortBy('id') as $pago) {
                $lineas = array_merge($lineas, self::lineasDelPago($pago));
                $totalEfectivo += (float) $pago->amount;

                foreach ($pago->retentions as $retencion) {
                    $retenciones[] = [
                        'descripcion' => $retencion->descripcion,
                        'monto' => (float) $retencion->amount,
                        'certificado' => $retencion->certificate_number,
                    ];
                }
            }

            $totalRetenido = array_sum(array_column($retenciones, 'monto'));

            $recibos[] = [
                // El número del recibo es el del primer pago del
                // grupo: es un id real y no un consecutivo paralelo
                // que después nadie sepa reconstruir.
                'numero' => str_pad((string) $primero->id, 6, '0', STR_PAD_LEFT),
                'fecha' => $primero->payment_date ?? $primero->created_at,
                'hora' => $primero->created_at,
                'branch' => $contrato?->branch ?? PdfBranding::branch(),
                'contrato' => $contrato,
                'cliente' => $contrato?->client,
                'cajero' => $primero->user,
                'caja' => $primero->cashRegister,
                'metodo' => $primero->payment_method,
                'referencia' => $primero->reference_number,
                'notas' => $primero->notes,
                'lote' => $primero->batch,
                'lineas' => $lineas,
                'retenciones' => $retenciones,
                'total_efectivo' => round($totalEfectivo, 2),
                'total_retenido' => round((float) $totalRetenido, 2),
                'total_cancelado' => round($totalEfectivo + (float) $totalRetenido, 2),
                // Saldo del contrato DESPUÉS del pago. Negativo
                // significa saldo a favor del cliente.
                'saldo_actual' => $contrato ? self::saldoDelContrato($contrato, $saldos) : 0.0,
            ];
        }

        return $recibos;
    }

    /**
     * Líneas de detalle de un pago.
     *
     * Se imprime el desglose de lo facturado (los conceptos de la
     * factura), no solo el total: el cliente tiene que poder ver por
     * qué le cobraron lo que le cobraron.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lineasDelPago(Payment $pago): array
    {
        $factura = $pago->invoice;

        // Anticipo: no hay factura que desglosar
        if (!$factura) {
            return [[
                'tipo' => 'documento',
                'titulo' => 'ABONO A CUENTA (anticipo)',
                'periodo' => null,
                'conceptos' => [],
                'aplicado' => (float) $pago->amount,
                'etiqueta_aplicado' => 'Abono',
                'saldo_documento' => 0.0,
            ]];
        }

        $conceptos = $factura->invoice_items
            ->map(fn ($item) => [
                'descripcion' => $item->description,
                'cantidad' => (float) $item->quantity,
                'valor' => (float) $item->total,
            ])
            ->all();

        // Facturas antiguas o migradas pueden no tener ítems: en ese
        // caso se muestra al menos el concepto general.
        if (empty($conceptos)) {
            $conceptos = [[
                'descripcion' => $factura->type ?: 'Servicio',
                'cantidad' => 1.0,
                'valor' => (float) $factura->total,
            ]];
        }

        $pendiente = (float) $factura->pending_invoice_amount;

        return [[
            'tipo' => 'documento',
            'titulo' => 'FACT. ' . $factura->displayNumber(),
            'periodo' => trim(($factura->billed_month_name ?? '') . ' ' . ($factura->billed_period_short ?? '')) ?: $factura->billed_period,
            'conceptos' => $conceptos,
            'aplicado' => (float) $pago->amount,
            'etiqueta_aplicado' => $pendiente > 0 ? 'Abono' : 'Pagado',
            'saldo_documento' => round($pendiente, 2),
        ]];
    }

    /**
     * Saldo global del contrato: lo que debe menos lo que tiene a
     * favor. Negativo = el cliente tiene saldo a favor.
     */
    private static function saldoDelContrato($contrato, CreditBalanceService $saldos): float
    {
        $debe = (float) Invoice::where('contract_id', $contrato->id)
            ->whereIn('status', InvoiceStatus::payable())
            ->sum('pending_invoice_amount');

        return round($debe - $saldos->saldo($contrato), 2);
    }

    /**
     * PDF de la tirilla con todos los recibos, uno tras otro.
     *
     * @param  array<int, array<string, mixed>>  $recibos
     */
    public static function pdf(array $recibos): PdfWrapper
    {
        $pdf = Pdf::loadView('gestisp.payments.receipt-thermal-pdf', ['recibos' => $recibos]);

        // Página de 80 mm de ancho y el alto estimado del contenido:
        // dompdf exige un tamaño fijo, pero una tirilla no lo tiene.
        $pdf->setPaper([0, 0, self::ANCHO_PT, self::altoEstimado($recibos)]);

        // Se renderiza aquí: hasta que no se renderiza, el documento
        // conserva el tamaño por defecto (A4) y cualquiera que
        // consulte el canvas vería un papel que no es el que sale.
        $pdf->render();

        return $pdf;
    }

    /**
     * Alto de página en puntos.
     *
     * Cada recibo es su propia página (page-break-after), pero dompdf
     * usa UN solo tamaño de papel para todo el documento. Por eso se
     * toma el alto del recibo MÁS LARGO y no la suma: sumarlos le
     * daría a cada tirilla el alto de todas juntas y saldría media
     * hoja en blanco por cada cobro.
     *
     * @param  array<int, array<string, mixed>>  $recibos
     */
    private static function altoEstimado(array $recibos): float
    {
        $alto = 0.0;

        foreach ($recibos as $recibo) {
            $alto = max($alto, self::altoDeRecibo($recibo));
        }

        // Nunca menos de una tirilla mínima razonable
        return max($alto, 400);
    }

    /**
     * Alto que ocupa un recibo.
     *
     * Se cuenta lo que sabemos que ocupa cada bloque. Los valores
     * salieron de medir el render real; si se cambia el tamaño de la
     * letra o se agregan bloques a la vista, hay que ajustarlos o el
     * recibo saldrá cortado (de menos) o con papel en blanco (de más).
     *
     * @param  array<string, mixed>  $recibo
     */
    private static function altoDeRecibo(array $recibo): float
    {
        // Encabezado (logo, empresa, NIT, dirección) + datos del
        // cliente + totales + pie
        $alto = 340.0;

        foreach ($recibo['lineas'] as $linea) {
            // Título del documento + período + línea de aplicado
            $alto += 34;
            $alto += 12 * count($linea['conceptos'] ?? []);
        }

        if (!empty($recibo['retenciones'])) {
            // Título del bloque + una línea por retención (las
            // descripciones son largas y suelen ocupar dos renglones)
            $alto += 20 + 24 * count($recibo['retenciones']);
        }

        // Margen de corte
        return $alto + 20;
    }
}
