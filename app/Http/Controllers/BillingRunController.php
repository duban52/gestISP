<?php

namespace App\Http\Controllers;

use App\Exports\BillingRunExport;
use App\Models\AffinityGroup;
use App\Models\BillingRun;
use App\Support\BranchFilter;
use Illuminate\Http\Request;
use App\Support\PdfBranding;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as FormatoExcel;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Tenancy\CurrentContext;

/**
 * Detalle de una corrida de facturación.
 *
 * El listado de corridas resume cuánto se facturó; aquí se ve QUÉ se
 * facturó: cada factura con su cliente, su contrato y el desglose de
 * lo cobrado (servicios del plan y cargos adicionales).
 *
 * Es el soporte de la generación: se puede descargar en Excel, CSV o
 * PDF para archivarlo o entregarlo a contabilidad.
 */
class BillingRunController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Mismo permiso que el resto del reporte de facturación
        $this->middleware('check.permission:invoices.index');
    }

    /**
     * Detalle en pantalla.
     */
    public function show(Request $request, BillingRun $billingRun): View
    {
        $this->verificarSucursal($billingRun);

        $gruposPedidos = BranchFilter::normalizar($request->query('affinity_group_id'));
        $facturas = $this->facturasFiltradas($billingRun, $gruposPedidos);

        return view('gestisp.invoices.billing_run_show', [
            'run' => $billingRun->load('user', 'branch'),
            'facturas' => $facturas,
            'resumen' => $this->resumen($facturas),
            'porGrupo' => $this->porGrupo($facturas),
            'filtros' => $request->query(),
            'gruposFiltrados' => $gruposPedidos !== []
                ? AffinityGroup::whereIn('id', $gruposPedidos)->get()
                : collect(),
        ]);
    }

    /**
     * Las facturas del reporte, acotadas al grupo pedido.
     *
     * EL FILTRO VIAJA A LAS DESCARGAS
     * -------------------------------
     * Excel, CSV y PDF pasan por aquí con los mismos parámetros. Si la
     * pantalla enseñara un grupo y la descarga trajera todo, el archivo
     * que se le entrega a contabilidad no sería el que se revisó.
     *
     * Se filtra en memoria y no en la consulta porque `facturasDelReporte()`
     * tiene dos caminos —las facturas enlazadas a la corrida, o las
     * deducidas por período en las corridas antiguas— y duplicar el
     * filtro en los dos invita a que se separen.
     *
     * @param  int[]  $gruposPedidos
     */
    private function facturasFiltradas(BillingRun $billingRun, array $gruposPedidos)
    {
        $facturas = $billingRun->facturasDelReporte();

        if ($gruposPedidos === []) {
            return $facturas;
        }

        return $facturas->filter(
            fn ($factura) => in_array((int) $factura->contract?->affinity_group_id, $gruposPedidos, true),
        )->values();
    }

    /**
     * Los totales DESGLOSADOS POR GRUPO DE AFINIDAD.
     *
     * Es lo que permite leer una corrida por lo que significa para el
     * negocio: cuánto se le facturó a cada segmento. El total general no
     * lo dice, y sumarlo a mano desde el listado es lo que se estaba
     * haciendo.
     *
     * Los contratos SIN grupo entran como «Sin grupo» en vez de
     * desaparecer: si se omitieran, la suma de los grupos no daría el
     * total y el reporte se contradiría consigo mismo.
     *
     * @param  \Illuminate\Support\Collection  $facturas
     */
    private function porGrupo($facturas)
    {
        return $facturas
            ->groupBy(fn ($factura) => $factura->contract?->affinityGroup?->name ?: 'Sin grupo')
            ->map(fn ($delGrupo, $nombre) => [
                'grupo' => $nombre,
                'facturas' => $delGrupo->count(),
                'subtotal' => (float) $delGrupo->sum('subtotal'),
                'impuestos' => (float) $delGrupo->sum('tax'),
                'total' => (float) $delGrupo->sum('total'),
                'saldo_pendiente' => (float) $delGrupo->sum('pending_invoice_amount'),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /** Descarga en Excel. */
    public function excel(Request $request, BillingRun $billingRun): BinaryFileResponse
    {
        $this->verificarSucursal($billingRun);

        return Excel::download(
            new BillingRunExport($billingRun, BranchFilter::normalizar($request->query('affinity_group_id'))),
            $this->nombreArchivo($billingRun, 'xlsx'),
        );
    }

    /** Descarga en CSV. */
    public function csv(Request $request, BillingRun $billingRun): BinaryFileResponse
    {
        $this->verificarSucursal($billingRun);

        return Excel::download(
            new BillingRunExport($billingRun, BranchFilter::normalizar($request->query('affinity_group_id'))),
            $this->nombreArchivo($billingRun, 'csv'),
            FormatoExcel::CSV,
            ['Content-Type' => 'text/csv'],
        );
    }

    /** Descarga en PDF. */
    public function pdf(Request $request, BillingRun $billingRun): Response
    {
        $this->verificarSucursal($billingRun);

        $gruposPedidos = BranchFilter::normalizar($request->query('affinity_group_id'));
        $facturas = $this->facturasFiltradas($billingRun, $gruposPedidos);

        $gruposFiltrados = $gruposPedidos !== []
            ? AffinityGroup::whereIn('id', $gruposPedidos)->pluck('name')->implode(', ')
            : null;

        $pdf = PdfBranding::make(
            'gestisp.invoices.pdf.billing_run',
            [
                'run' => $billingRun->load('user'),
                'facturas' => $facturas,
                'resumen' => $this->resumen($facturas),
                'porGrupo' => $this->porGrupo($facturas),
                // En el encabezado, para que no se confunda un reporte
                // acotado con el de la corrida entera.
                'gruposFiltrados' => $gruposFiltrados,
                'branch' => $billingRun->branch,
                'pdfTitle' => 'Reporte de facturación',
                'pdfSubtitle' => 'Período ' . $billingRun->periodo_legible
                    . ($gruposFiltrados ? ' — solo ' . $gruposFiltrados : ''),
            ],
            landscape: true,
        );

        return $pdf->download($this->nombreArchivo($billingRun, 'pdf'));
    }

    /**
     * Totales del reporte, calculados sobre las facturas reales y no
     * sobre los conteos guardados: si una factura se anuló después de
     * la corrida, el detalle debe reflejarlo.
     *
     * @param  \Illuminate\Support\Collection  $facturas
     * @return array<string, float|int>
     */
    private function resumen($facturas): array
    {
        return [
            'facturas' => $facturas->count(),
            'subtotal' => (float) $facturas->sum('subtotal'),
            'impuestos' => (float) $facturas->sum('tax'),
            'total' => (float) $facturas->sum('total'),
            'saldo_pendiente' => (float) $facturas->sum('pending_invoice_amount'),
            'anuladas' => $facturas->where('status', 'Anulada')->count(),
        ];
    }

    /**
     * Una corrida solo puede consultarse desde su propia sucursal.
     */
    private function verificarSucursal(BillingRun $billingRun): void
    {
        abort_unless(
            app(CurrentContext::class)->permiteSucursal($billingRun->branch_id),
            403,
            'Esta corrida de facturación pertenece a otra sucursal.',
        );
    }

    private function nombreArchivo(BillingRun $run, string $extension): string
    {
        return 'facturacion-' . $run->billed_year_month . '-corrida-' . $run->id . '.' . $extension;
    }
}
