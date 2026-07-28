<?php

namespace App\Http\Controllers;

use App\Exports\BillingRunExport;
use App\Models\BillingRun;
use App\Support\PdfBranding;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as FormatoExcel;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    public function show(BillingRun $billingRun): View
    {
        $this->verificarSucursal($billingRun);

        $facturas = $billingRun->facturasDelReporte();

        return view('gestisp.invoices.billing_run_show', [
            'run' => $billingRun->load('user', 'branch'),
            'facturas' => $facturas,
            'resumen' => $this->resumen($facturas),
        ]);
    }

    /** Descarga en Excel. */
    public function excel(BillingRun $billingRun): BinaryFileResponse
    {
        $this->verificarSucursal($billingRun);

        return Excel::download(
            new BillingRunExport($billingRun),
            $this->nombreArchivo($billingRun, 'xlsx'),
        );
    }

    /** Descarga en CSV. */
    public function csv(BillingRun $billingRun): BinaryFileResponse
    {
        $this->verificarSucursal($billingRun);

        return Excel::download(
            new BillingRunExport($billingRun),
            $this->nombreArchivo($billingRun, 'csv'),
            FormatoExcel::CSV,
            ['Content-Type' => 'text/csv'],
        );
    }

    /** Descarga en PDF. */
    public function pdf(BillingRun $billingRun): Response
    {
        $this->verificarSucursal($billingRun);

        $facturas = $billingRun->facturasDelReporte();

        $pdf = PdfBranding::make(
            'gestisp.invoices.pdf.billing_run',
            [
                'run' => $billingRun->load('user'),
                'facturas' => $facturas,
                'resumen' => $this->resumen($facturas),
                'branch' => $billingRun->branch,
                'pdfTitle' => 'Reporte de facturación',
                'pdfSubtitle' => 'Período ' . $billingRun->periodo_legible,
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
            (int) $billingRun->branch_id === (int) session('branch_id'),
            403,
            'Esta corrida de facturación pertenece a otra sucursal.',
        );
    }

    private function nombreArchivo(BillingRun $run, string $extension): string
    {
        return 'facturacion-' . $run->billed_year_month . '-corrida-' . $run->id . '.' . $extension;
    }
}
