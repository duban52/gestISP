<?php

namespace App\Http\Controllers;

use App\Reports\Support\BranchFilter;
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
use App\Tenancy\CurrentContext;

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

        $crecimiento = new GrowthReport($filtros['period'], $filtros['branchIds']);
        $facturacion = new BillingReport($filtros['period'], $filtros['branchIds']);

        return view('gestisp.reports.index', $filtros + [
            'crecimiento' => $crecimiento->resumen(),
            'tecnicas' => (new TechnicalOrdersReport($filtros['period'], $filtros['branchIds']))->resumen(),
            'facturacion' => $facturacion->resumen(),
            'estadosContrato' => $crecimiento->distribucionEstados(),
            'seriesCrecimiento' => $crecimiento->series(),
            'seriesFacturacion' => $facturacion->series(),
        ]);
    }

    public function crecimiento(Request $request): View
    {
        $filtros = $this->filtros($request);
        $reporte = new GrowthReport($filtros['period'], $filtros['branchIds']);

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
        $reporte = new TechnicalOrdersReport($filtros['period'], $filtros['branchIds']);

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
        $reporte = new BillingReport($filtros['period'], $filtros['branchIds']);

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
        $reporte = new ProvisioningReport($filtros['period'], $filtros['branchIds']);

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
        $reporte = new ProvisioningReport($filtros['period'], $filtros['branchIds']);

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
        $crecimiento = new GrowthReport($filtros['period'], $filtros['branchIds']);

        return $this->pdf('gestisp.reports.pdf.summary', 'Resumen gerencial', $filtros, [
            'crecimiento' => $crecimiento->resumen(),
            'tecnicas' => (new TechnicalOrdersReport($filtros['period'], $filtros['branchIds']))->resumen(),
            'facturacion' => (new BillingReport($filtros['period'], $filtros['branchIds']))->resumen(),
            'estados' => $crecimiento->distribucionEstados(),
        ]);
    }

    public function crecimientoPdf(Request $request): Response
    {
        $filtros = $this->filtros($request);
        $reporte = new GrowthReport($filtros['period'], $filtros['branchIds']);

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
        $reporte = new TechnicalOrdersReport($filtros['period'], $filtros['branchIds']);

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
        $reporte = new BillingReport($filtros['period'], $filtros['branchIds']);

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

        // QUÉ SUCURSALES ENTRAN EN EL INFORME
        // -----------------------------------
        // Antes era siempre una: la de la sesión. Se leía de la sesión
        // y nunca de la petición a propósito, para que nadie pidiera
        // el informe de una sede ajena cambiando la URL.
        //
        // El panel consolidado cambia la pregunta: ahí se quiere ver
        // una sede, varias sumadas, o todas menos alguna. Así que la
        // selección SÍ viene de la petición — pero se cruza con el
        // alcance del usuario antes de usarla, así que la garantía de
        // antes se mantiene: solo se informa de sedes a las que llega.
        //
        // Sin selección se informa de TODAS las suyas, que es la suma
        // consolidada y el valor por defecto razonable al entrar.
        $contexto = app(CurrentContext::class);
        $alcance = $contexto->branchIds();

        if ($contexto->branchId() !== null) {
            // Modo independiente: manda la sucursal activa y no hay
            // nada que elegir. Lo que llegue en la URL se ignora.
            $branchIds = [$contexto->branchId()];
            $elegibles = collect();
        } else {
            $pedidas = BranchFilter::normalizar($request->query('sucursales'));

            // array_intersect es lo que impide pedir una sede ajena:
            // lo que no esté en el alcance se cae solo.
            $branchIds = array_values(array_intersect($pedidas, $alcance));

            // Ninguna marcada = todas. Marcarlas todas y no marcar
            // ninguna dan el mismo informe, que es lo que espera
            // cualquiera que vacíe las casillas por error.
            if ($branchIds === []) {
                $branchIds = $alcance;
            }

            // El selector solo tiene sentido con más de una sede.
            $elegibles = count($alcance) > 1 ? $contexto->sucursalesElegibles() : collect();
        }

        // La sucursal suelta sigue haciendo falta para el membrete de
        // los PDF, y solo existe cuando el informe es de una sola.
        $branch = count($branchIds) === 1 ? Branch::find($branchIds[0]) : null;

        return [
            'period' => $period,
            'branchIds' => $branchIds,
            'branch' => $branch,
            'ambito' => $this->ambito($branchIds, $branch, $alcance),
            'granularidades' => Granularity::cases(),
            // Para el selector de la barra de filtros. Vacía cuando no
            // hay nada que elegir: modo independiente, o una sola sede.
            'sucursales' => $elegibles,
            // Estados de contrato que no encajan en ningún grupo
            // conocido: se avisan en pantalla para que nadie lea un
            // total incompleto sin saberlo
            'estadosSinClasificar' => ContractStatusMap::estadosSinClasificar($branchIds),
        ];
    }

    /**
     * Cómo se llama lo que se está informando.
     *
     * Sale en la cabecera de cada pantalla y en el membrete de cada
     * PDF, así que tiene que dejar claro si lo que se está leyendo es
     * una sede, todas, o un recorte — un total de tres sucursales
     * leído como si fuera de cinco es un error caro.
     *
     * @param  array<int, int>  $branchIds  Las que entran
     * @param  array<int, int>  $alcance    Las que el usuario alcanza
     */
    private function ambito(array $branchIds, ?Branch $branch, array $alcance): string
    {
        if ($branch !== null) {
            return $branch->name;
        }

        if ($branchIds === []) {
            return 'Sucursal';
        }

        $cuantas = count($branchIds);

        if ($cuantas === count($alcance)) {
            return sprintf('Todas las sucursales (%d)', $cuantas);
        }

        // Con pocas se nombran: leer "Norte + Centro" evita tener que
        // abrir el filtro para saber qué se está mirando.
        $nombres = Branch::whereIn('id', $branchIds)->orderBy('name')->pluck('name');

        if ($cuantas <= 3) {
            return $nombres->implode(' + ');
        }

        return sprintf('%d de %d sucursales', $cuantas, count($alcance));
    }

}
