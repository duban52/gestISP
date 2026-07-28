<?php

namespace App\Http\Controllers;

use App\Billing\Enums\RetentionType;
use App\Exports\RetentionsExport;
use App\Models\PaymentRetention;
use App\Services\Audit\AuditLogger;
use App\Support\PdfBranding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte de retenciones practicadas por los clientes.
 *
 * PARA QUÉ SIRVE ESTA PANTALLA
 * ----------------------------
 * Cada retención es dinero que no entró a la caja porque el cliente
 * lo consignó al Estado a nombre nuestro. Ese dinero NO está perdido:
 * es un anticipo de nuestros propios impuestos y se descuenta al
 * declarar renta, IVA o ICA.
 *
 * Para descontarlo hay que poder demostrarlo, y para demostrarlo hay
 * que tenerlo listado: cuánto, de quién, sobre qué factura y con qué
 * certificado. Eso es exactamente lo que arma este reporte, y por eso
 * se exporta: es lo que se le entrega al contador.
 *
 * El período por defecto es el mes en curso, igual que en el resto de
 * los listados de cobranza.
 */
class RetentionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:retentions.index')->only('index');
        $this->middleware('check.permission:retentions.export')->only('excel', 'pdf');
    }

    /** Listado con filtros de período y tipo. */
    public function index(Request $request): View
    {
        $retenciones = $this->consulta($request)->get();

        return view('gestisp.retentions.index', [
            'retenciones' => $retenciones,
            'totalesPorTipo' => $this->totalesPorTipo($retenciones),
            'total' => round((float) $retenciones->sum('amount'), 2),
            'tipos' => RetentionType::opciones(),
            'desde' => $this->desde($request),
            'hasta' => $this->hasta($request),
        ]);
    }

    /** Mismo listado, en Excel. */
    public function excel(Request $request)
    {
        $retenciones = $this->consulta($request)->get();

        $this->auditLogger->action(
            'retentions.exported',
            sprintf('Exportó a Excel %d retención(es)', $retenciones->count()),
            ['formato' => 'xlsx', 'registros' => $retenciones->count()],
            null,
            'facturacion',
        );

        return Excel::download(
            new RetentionsExport($retenciones),
            'retenciones-' . now()->format('Y-m-d') . '.xlsx',
        );
    }

    /** Mismo listado, en PDF. */
    public function pdf(Request $request)
    {
        $retenciones = $this->consulta($request)->get();

        $this->auditLogger->action(
            'retentions.exported',
            sprintf('Exportó a PDF %d retención(es)', $retenciones->count()),
            ['formato' => 'pdf', 'registros' => $retenciones->count()],
            null,
            'facturacion',
        );

        // Horizontal: son once columnas con nombres y documentos
        return PdfBranding::make('gestisp.retentions.pdf', [
            'retenciones' => $retenciones,
            'totalesPorTipo' => $this->totalesPorTipo($retenciones),
            'total' => round((float) $retenciones->sum('amount'), 2),
            'desde' => $this->desde($request),
            'hasta' => $this->hasta($request),
        ], landscape: true)->download('retenciones.pdf');
    }

    /**
     * Consulta filtrada. Es la única fuente de verdad de los filtros:
     * pantalla y exportaciones la comparten, así lo que se descarga
     * es exactamente lo que se ve.
     */
    private function consulta(Request $request): Builder
    {
        $query = PaymentRetention::query()
            ->with(['contract.client', 'invoice', 'user'])
            ->whereBetween('created_at', [
                $this->desde($request) . ' 00:00:00',
                $this->hasta($request) . ' 23:59:59',
            ]);

        if (session()->has('branch_id')) {
            $query->where('branch_id', session('branch_id'));
        }

        if ($request->filled('type') && RetentionType::tryFrom($request->type)) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $like = '%' . trim($request->search) . '%';

            $query->where(function ($q) use ($like) {
                $q->where('certificate_number', 'like', $like)
                    ->orWhereHas('contract', fn ($c) => $c->where('contract_number', 'like', $like))
                    ->orWhereHas('contract.client', function ($c) use ($like) {
                        $c->where('identity_number', 'like', $like)
                            ->orWhereRaw("CONCAT(name, ' ', last_name) LIKE ?", [$like]);
                    });
            });
        }

        return $query->orderByDesc('created_at');
    }

    /**
     * Totales por tipo de retención.
     *
     * Es lo primero que mira el contador: cuánto se retuvo de renta,
     * cuánto de IVA y cuánto de ICA, que van a declaraciones
     * distintas y en períodos distintos.
     *
     * @return array<string, array{label: string, total: float, count: int}>
     */
    private function totalesPorTipo($retenciones): array
    {
        $totales = [];

        foreach ($retenciones->groupBy('type') as $tipo => $grupo) {
            $totales[$tipo] = [
                'label' => RetentionType::tryFrom($tipo)?->etiqueta() ?? $tipo,
                'total' => round((float) $grupo->sum('amount'), 2),
                'count' => $grupo->count(),
            ];
        }

        return $totales;
    }

    private function desde(Request $request): string
    {
        return $request->filled('from')
            ? $request->from
            : now()->startOfMonth()->toDateString();
    }

    private function hasta(Request $request): string
    {
        return $request->filled('to')
            ? $request->to
            : now()->endOfMonth()->toDateString();
    }
}
