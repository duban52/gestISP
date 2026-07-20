<?php

namespace App\Reports;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Reports\Support\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Facturación, recaudo y cartera.
 *
 * Tres reglas que se respetan en todas las consultas de esta clase,
 * porque de ellas depende que las cifras cuadren con la caja:
 *
 * - Lo FACTURADO excluye las facturas anuladas: siguen en la tabla
 *   por trazabilidad, pero no son ingreso exigible.
 * - Lo RECAUDADO suma únicamente pagos en estado "completed". Los
 *   pagos anulados usan borrado lógico y Eloquent ya los descarta.
 * - Se factura por issue_date y se recauda por payment_date. Son
 *   fechas distintas a propósito: una factura de marzo cobrada en
 *   abril debe sumar en marzo como facturación y en abril como
 *   recaudo. Por eso la tasa de recaudo de un mes puede pasar del
 *   100%: incluye cobros de meses anteriores.
 */
class BillingReport
{
    public function __construct(
        private readonly ReportPeriod $period,
        private readonly ?int $branchId = null,
    ) {
    }

    /**
     * Facturado y recaudado por período.
     *
     * @return array{labels: Collection, facturado: Collection, recaudado: Collection}
     */
    public function series(): array
    {
        $facturado = $this->period->completarSerie($this->facturadoPorPeriodo());
        $recaudado = $this->period->completarSerie($this->recaudadoPorPeriodo());

        return [
            'labels' => $this->period->etiquetas(),
            'facturado' => $facturado->map(fn ($v) => round((float) $v, 2))->values(),
            'recaudado' => $recaudado->map(fn ($v) => round((float) $v, 2))->values(),
        ];
    }

    /**
     * @return Collection<string, float>
     */
    private function facturadoPorPeriodo(): Collection
    {
        $bucket = $this->period->sqlBucket('invoices.issue_date');

        return $this->facturasQuery()
            ->whereBetween('invoices.issue_date', [$this->period->from, $this->period->to])
            ->selectRaw("{$bucket} as periodo, SUM(invoices.total) as total")
            ->groupBy('periodo')
            ->pluck('total', 'periodo');
    }

    /**
     * @return Collection<string, float>
     */
    private function recaudadoPorPeriodo(): Collection
    {
        $bucket = $this->period->sqlBucket('payments.payment_date');

        return $this->pagosQuery()
            ->whereBetween('payments.payment_date', [$this->period->from, $this->period->to])
            ->selectRaw("{$bucket} as periodo, SUM(payments.amount) as total")
            ->groupBy('periodo')
            ->pluck('total', 'periodo');
    }

    /**
     * Cartera pendiente agrupada por antigüedad del vencimiento.
     *
     * Es la lectura clásica de cobranza: cuanto más a la derecha se
     * acumula el saldo, más difícil es recuperarlo.
     *
     * @return Collection<int, array{etiqueta: string, total: float, facturas: int, color: string}>
     */
    public function carteraPorAntiguedad(): Collection
    {
        $tramos = [
            ['etiqueta' => 'Por vencer', 'desde' => null, 'hasta' => 0, 'color' => '#17a2b8'],
            ['etiqueta' => '1 a 30 días', 'desde' => 1, 'hasta' => 30, 'color' => '#ffc107'],
            ['etiqueta' => '31 a 60 días', 'desde' => 31, 'hasta' => 60, 'color' => '#fd7e14'],
            ['etiqueta' => '61 a 90 días', 'desde' => 61, 'hasta' => 90, 'color' => '#dc3545'],
            ['etiqueta' => 'Más de 90 días', 'desde' => 91, 'hasta' => null, 'color' => '#6f42c1'],
        ];

        // Un solo recorrido de la tabla: se clasifica en SQL y se
        // agrupa, en lugar de lanzar una consulta por tramo
        $filas = $this->carteraQuery()
            ->selectRaw($this->sqlTramo() . ' as tramo')
            ->selectRaw('SUM(invoices.pending_invoice_amount) as total, COUNT(*) as facturas')
            ->groupBy('tramo')
            ->get()
            ->keyBy('tramo');

        return collect($tramos)->map(fn ($t) => [
            'etiqueta' => $t['etiqueta'],
            'color' => $t['color'],
            'total' => round((float) ($filas[$t['etiqueta']]->total ?? 0), 2),
            'facturas' => (int) ($filas[$t['etiqueta']]->facturas ?? 0),
        ]);
    }

    /**
     * Clasifica cada factura en su tramo según los días vencidos.
     */
    private function sqlTramo(): string
    {
        $dias = 'DATEDIFF(CURDATE(), invoices.due_date)';

        return "CASE
            WHEN {$dias} <= 0 THEN 'Por vencer'
            WHEN {$dias} <= 30 THEN '1 a 30 días'
            WHEN {$dias} <= 60 THEN '31 a 60 días'
            WHEN {$dias} <= 90 THEN '61 a 90 días'
            ELSE 'Más de 90 días'
        END";
    }

    /**
     * Recaudo por método de pago.
     *
     * @return Collection<int, array{etiqueta: string, total: float, operaciones: int}>
     */
    public function porMetodoPago(): Collection
    {
        return $this->pagosQuery()
            ->whereBetween('payments.payment_date', [$this->period->from, $this->period->to])
            ->selectRaw('payments.payment_method as etiqueta, SUM(payments.amount) as total, COUNT(*) as operaciones')
            ->groupBy('payments.payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [
                'etiqueta' => (string) ($f->etiqueta ?: 'Sin definir'),
                'total' => round((float) $f->total, 2),
                'operaciones' => (int) $f->operaciones,
            ]);
    }

    /**
     * Facturas emitidas en el período, por estado.
     *
     * @return Collection<int, array{etiqueta: string, total: int, monto: float}>
     */
    public function facturasPorEstado(): Collection
    {
        return Invoice::query()
            ->when($this->branchId, fn ($q) => $q->where('invoices.branch_id', $this->branchId))
            ->whereBetween('invoices.issue_date', [$this->period->from, $this->period->to])
            ->selectRaw('invoices.status as etiqueta, COUNT(*) as total, SUM(invoices.total) as monto')
            ->groupBy('invoices.status')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [
                'etiqueta' => (string) $f->etiqueta,
                'total' => (int) $f->total,
                'monto' => round((float) $f->monto, 2),
            ]);
    }

    /**
     * Contratos con mayor saldo pendiente.
     *
     * Es la lista con la que se prioriza la gestión de cobro.
     *
     * @return Collection<int, array>
     */
    public function mayoresDeudores(int $limite = 10): Collection
    {
        return $this->carteraQuery()
            ->join('contracts', 'contracts.id', '=', 'invoices.contract_id')
            ->join('clients', 'clients.id', '=', 'contracts.client_id')
            ->selectRaw("CONCAT(clients.name, ' ', COALESCE(clients.last_name, '')) as cliente")
            ->selectRaw('clients.identity_number as documento')
            ->selectRaw('contracts.id as contrato')
            ->selectRaw('contracts.status as estado')
            ->selectRaw('SUM(invoices.pending_invoice_amount) as saldo, COUNT(*) as facturas')
            ->selectRaw('MAX(DATEDIFF(CURDATE(), invoices.due_date)) as dias')
            ->groupBy('contracts.id', 'contracts.status', 'clients.name', 'clients.last_name', 'clients.identity_number')
            ->orderByDesc('saldo')
            ->limit($limite)
            ->get()
            ->map(fn ($f) => [
                'cliente' => trim($f->cliente),
                'documento' => $f->documento,
                'contrato' => (int) $f->contrato,
                'estado' => $f->estado,
                'saldo' => round((float) $f->saldo, 2),
                'facturas' => (int) $f->facturas,
                'dias' => max(0, (int) $f->dias),
            ]);
    }

    /**
     * Indicadores del período con comparación contra el anterior.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $facturado = $this->totalFacturado($this->period);
        $recaudado = $this->totalRecaudado($this->period);

        $anterior = $this->period->anterior();

        $cartera = (float) $this->carteraQuery()->sum('invoices.pending_invoice_amount');
        $vencida = (float) $this->carteraQuery()
            ->whereRaw('invoices.due_date < CURDATE()')
            ->sum('invoices.pending_invoice_amount');

        return [
            'facturado' => $facturado,
            'recaudado' => $recaudado,
            'cartera' => round($cartera, 2),
            'cartera_vencida' => round($vencida, 2),
            'tasa_recaudo' => $facturado > 0 ? round($recaudado / $facturado * 100, 1) : 0.0,
            'ticket_promedio' => $this->ticketPromedio(),
            'facturado_previo' => $this->totalFacturado($anterior),
            'recaudado_previo' => $this->totalRecaudado($anterior),
        ];
    }

    private function ticketPromedio(): float
    {
        $promedio = $this->facturasQuery()
            ->whereBetween('invoices.issue_date', [$this->period->from, $this->period->to])
            ->avg('invoices.total');

        return round((float) $promedio, 2);
    }

    private function totalFacturado(ReportPeriod $periodo): float
    {
        return round((float) $this->facturasQuery()
            ->whereBetween('invoices.issue_date', [$periodo->from, $periodo->to])
            ->sum('invoices.total'), 2);
    }

    private function totalRecaudado(ReportPeriod $periodo): float
    {
        return round((float) $this->pagosQuery()
            ->whereBetween('payments.payment_date', [$periodo->from, $periodo->to])
            ->sum('payments.amount'), 2);
    }

    /**
     * Facturas que representan ingreso real: se excluyen las
     * anuladas y los borradores, que no son exigibles.
     */
    private function facturasQuery()
    {
        return Invoice::query()
            ->when($this->branchId, fn ($q) => $q->where('invoices.branch_id', $this->branchId))
            ->whereNotIn('invoices.status', [
                InvoiceStatus::Anulada->value,
                InvoiceStatus::Borrador->value,
            ]);
    }

    /**
     * Saldo pendiente vivo: facturas que todavía admiten pago y
     * conservan saldo.
     */
    private function carteraQuery()
    {
        return Invoice::query()
            ->when($this->branchId, fn ($q) => $q->where('invoices.branch_id', $this->branchId))
            ->whereIn('invoices.status', InvoiceStatus::payable())
            ->where('invoices.pending_invoice_amount', '>', 0);
    }

    /**
     * Pagos efectivos. El borrado lógico de Payment ya excluye los
     * anulados; el filtro de estado descarta los no completados.
     */
    private function pagosQuery()
    {
        return Payment::query()
            ->where('payments.status', PaymentStatus::Completed->value)
            ->when($this->branchId, function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('invoices')
                        ->whereColumn('invoices.id', 'payments.invoice_id')
                        ->where('invoices.branch_id', $this->branchId);
                });
            });
    }
}
