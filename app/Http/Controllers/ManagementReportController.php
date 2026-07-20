<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Reports\BillingReport;
use App\Reports\Enums\Granularity;
use App\Reports\GrowthReport;
use App\Reports\ProvisioningReport;
use App\Reports\Support\ContractStatusMap;
use App\Reports\Support\ReportPeriod;
use App\Reports\TechnicalOrdersReport;
use App\Support\PdfBranding;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Informes gerenciales.
 *
 * Reúne la lectura estadística de la operación: crecimiento de la
 * base de clientes, rendimiento del equipo técnico y comportamiento
 * de la facturación y el recaudo.
 *
 * Todas las pantallas comparten los mismos filtros (rango de fechas
 * y granularidad) y cada una puede descargarse en PDF con
 * exactamente los mismos parámetros que se ven en pantalla.
 *
 * Sobre la SUCURSAL: cada una se informa de forma independiente. La
 * sucursal sale siempre de la sesión y no se puede cambiar desde la
 * petición, de modo que no existe una vista consolidada de varias
 * sedes.
 */
class ManagementReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:reports.index')->only('index', 'resumenPdf');
        $this->middleware('check.permission:reports.growth')->only('crecimiento', 'crecimientoPdf');
        $this->middleware('check.permission:reports.technical')->only('tecnicas', 'tecnicasPdf');
        $this->middleware('check.permission:reports.billing')->only('facturacion', 'facturacionPdf');
        $this->middleware('check.permission:reports.provisioning')->only('aprovisionamiento', 'aprovisionamientoPdf');
    }

    /**
     * Tablero ejecutivo: los indicadores de los tres informes en
     * una sola pantalla.
     */
    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);

        $crecimiento = new GrowthReport($filtros['period'], $filtros['branchId']);
        $facturacion = new BillingReport($filtros['period'], $filtros['branchId']);

        return view('gestisp.reports.index', $filtros + [
            'crecimiento' => $crecimiento->resumen(),
            'tecnicas' => (new TechnicalOrdersReport($filtros['period'], $filtros['branchId']))->resumen(),
            'facturacion' => $facturacion->resumen(),
            'estadosContrato' => $crecimiento->distribucionEstados(),
            'seriesCrecimiento' => $crecimiento->series(),
            'seriesFacturacion' => $facturacion->series(),
        ]);
    }

    public function crecimiento(Request $request): View
    {
        $filtros = $this->filtros($request);
        $reporte = new GrowthReport($filtros['period'], $filtros['branchId']);

        return view('gestisp.reports.growth', $filtros + [
            'resumen' => $reporte->resumen(),
            'series' => $reporte->series(),
            'estados' => $reporte->distribucionEstados(),
            'planes' => $reporte->porPlan(),
        ]);
    }

    public function tecnicas(Request $request): View
    {
        $filtros = $this->filtros($request);
        $reporte = new TechnicalOrdersReport($filtros['period'], $filtros['branchId']);

        return view('gestisp.reports.technical', $filtros + [
            'resumen' => $reporte->resumen(),
            'series' => $reporte->series(),
            'tipos' => $reporte->porTipo(),
            'detalles' => $reporte->porDetalle(),
            'detalleEstado' => $reporte->detallePorEstado(),
            'estados' => $reporte->porEstado(),
            'tecnicos' => $reporte->porTecnico(),
            'verificaciones' => $reporte->verificaciones(),
        ]);
    }

    public function facturacion(Request $request): View
    {
        $filtros = $this->filtros($request);
        $reporte = new BillingReport($filtros['period'], $filtros['branchId']);

        return view('gestisp.reports.billing', $filtros + [
            'resumen' => $reporte->resumen(),
            'series' => $reporte->series(),
            'cartera' => $reporte->carteraPorAntiguedad(),
            'metodos' => $reporte->porMetodoPago(),
            'estados' => $reporte->facturasPorEstado(),
            'deudores' => $reporte->mayoresDeudores(),
        ]);
    }

    public function aprovisionamiento(Request $request): View
    {
        $filtros = $this->filtros($request);
        $reporte = new ProvisioningReport($filtros['period'], $filtros['branchId']);

        return view('gestisp.reports.provisioning', $filtros + [
            'resumen' => $reporte->resumen(),
            'cobertura' => $reporte->cobertura(),
            'series' => $reporte->series(),
            'olts' => $reporte->porOlt(),
            'routers' => $reporte->porRouter(),
            'perfiles' => $reporte->porPerfil(),
            'optica' => $reporte->calidadOptica(),
            'huerfanas' => $reporte->ontsHuerfanas(),
        ]);
    }

    public function aprovisionamientoPdf(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $reporte = new ProvisioningReport($filtros['period'], $filtros['branchId']);

        return $this->pdf('gestisp.reports.pdf.provisioning', 'Aprovisionamiento de red', $filtros, [
            'resumen' => $reporte->resumen(),
            'cobertura' => $reporte->cobertura(),
            'olts' => $reporte->porOlt(),
            'routers' => $reporte->porRouter(),
            'perfiles' => $reporte->porPerfil(),
            'optica' => $reporte->calidadOptica(),
            'huerfanas' => $reporte->ontsHuerfanas(40),
        ], landscape: true);
    }

    public function resumenPdf(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $crecimiento = new GrowthReport($filtros['period'], $filtros['branchId']);

        return $this->pdf('gestisp.reports.pdf.summary', 'Resumen gerencial', $filtros, [
            'crecimiento' => $crecimiento->resumen(),
            'tecnicas' => (new TechnicalOrdersReport($filtros['period'], $filtros['branchId']))->resumen(),
            'facturacion' => (new BillingReport($filtros['period'], $filtros['branchId']))->resumen(),
            'estados' => $crecimiento->distribucionEstados(),
        ]);
    }

    public function crecimientoPdf(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $reporte = new GrowthReport($filtros['period'], $filtros['branchId']);

        return $this->pdf('gestisp.reports.pdf.growth', 'Crecimiento de contratos', $filtros, [
            'resumen' => $reporte->resumen(),
            'series' => $reporte->series(),
            'estados' => $reporte->distribucionEstados(),
            'planes' => $reporte->porPlan(),
        ]);
    }

    public function tecnicasPdf(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $reporte = new TechnicalOrdersReport($filtros['period'], $filtros['branchId']);

        return $this->pdf('gestisp.reports.pdf.technical', 'Órdenes técnicas', $filtros, [
            'resumen' => $reporte->resumen(),
            'series' => $reporte->series(),
            'tipos' => $reporte->porTipo(),
            'detalles' => $reporte->porDetalle(),
            'detalleEstado' => $reporte->detallePorEstado(),
            'estados' => $reporte->porEstado(),
            'tecnicos' => $reporte->porTecnico(),
            'verificaciones' => $reporte->verificaciones(),
        ], landscape: true);
    }

    public function facturacionPdf(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $reporte = new BillingReport($filtros['period'], $filtros['branchId']);

        return $this->pdf('gestisp.reports.pdf.billing', 'Facturación y recaudo', $filtros, [
            'resumen' => $reporte->resumen(),
            'series' => $reporte->series(),
            'cartera' => $reporte->carteraPorAntiguedad(),
            'metodos' => $reporte->porMetodoPago(),
            'estados' => $reporte->facturasPorEstado(),
            'deudores' => $reporte->mayoresDeudores(25),
        ], landscape: true);
    }

    /**
     * Genera el PDF con la plantilla estándar del sistema.
     *
     * El nombre del archivo lleva el rango informado, para que
     * varias descargas no se pisen en la carpeta de descargas.
     */
    private function pdf(string $vista, string $titulo, array $filtros, array $datos, bool $landscape = false): Response
    {
        $period = $filtros['period'];

        $pdf = PdfBranding::make($vista, $datos + [
            'pdfTitle' => $titulo,
            'pdfSubtitle' => $period->etiquetaRango() . ' · ' . $period->granularity->label(),
            'branch' => $filtros['branch'],
            'period' => $period,
            'ambito' => $filtros['ambito'],
            'orientation' => $landscape ? 'landscape' : 'portrait',
        ], $landscape);

        $nombre = sprintf(
            '%s_%s_%s.pdf',
            str($titulo)->slug('_'),
            $period->from->format('Ymd'),
            $period->to->format('Ymd'),
        );

        return $pdf->download($nombre);
    }

    /**
     * Filtros comunes a todas las pantallas del módulo.
     *
     * @return array<string, mixed>
     */
    private function filtros(Request $request): array
    {
        $period = ReportPeriod::fromRequest(
            $request->query('desde'),
            $request->query('hasta'),
            $request->query('granularidad'),
        );

        // Cada sucursal se informa de forma independiente: no hay
        // vista consolidada. La sucursal sale siempre de la sesión,
        // nunca de la petición.
        $branchId = (int) session('branch_id');
        $branch = Branch::find($branchId);

        return [
            'period' => $period,
            'branchId' => $branchId,
            'branch' => $branch,
            'ambito' => $branch?->name ?? 'Sucursal',
            'granularidades' => Granularity::cases(),
            // Estados de contrato que no encajan en ningún grupo
            // conocido: se avisan en pantalla para que nadie lea un
            // total incompleto sin saberlo
            'estadosSinClasificar' => ContractStatusMap::estadosSinClasificar($branchId),
        ];
    }

}
