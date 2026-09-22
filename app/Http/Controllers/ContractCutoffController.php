<?php

namespace App\Http\Controllers;

use App\Exports\ContractCutoffExport;
use App\Models\ContractCutoff;
use App\Models\ContractCutoffItem;
use App\Services\ContractMassCutoff;
use App\Services\PppoeMassCutoff;
use App\Support\PdfBranding;
use App\Tenancy\CurrentContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Cortes masivos por mora, en Gestión técnica.
 *
 * Tres pasos: la lista (archivo o pegada) → la revisión, que no toca
 * nada → la confirmación, que crea la tanda y manda los cortes a la
 * cola. La regla vive en App\Services\ContractMassCutoff.
 *
 * Permiso propio: dejar sin servicio a media sucursal no es lo mismo
 * que ver las órdenes.
 */
class ContractCutoffController extends Controller
{
    public function __construct(private readonly ContractMassCutoff $cortes)
    {
        $this->middleware('auth');
        $this->middleware('check.permission:technicals_orders.cutoff');
    }

    /**
     * La lista y el historial de cortes, que es el reporte: cada tanda
     * queda guardada con el resultado de cada contrato.
     */
    public function index(Request $request): View
    {
        $contexto = app(CurrentContext::class);
        [$desde, $hasta] = $this->rango($request);

        $tandas = ContractCutoff::whereIn('branch_id', $contexto->branchIds())
            ->when($desde, fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->with('user')
            ->withCount([
                'items',
                'items as cortados_count' => fn ($q) => $q->where('status', ContractCutoffItem::CORTADO),
                'items as pendientes_count' => fn ($q) => $q->where('status', ContractCutoffItem::PENDIENTE),
                'items as con_problemas_count' => fn ($q) => $q->whereIn('status', [ContractCutoffItem::INCOMPLETO, ContractCutoffItem::ERROR]),
            ])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('gestisp.technicals_orders.cortes.index', [
            'tandas' => $tandas,
            'desde' => $desde,
            'hasta' => $hasta,
            'umbral' => $contexto->branchId() ? $this->cortes->umbral($contexto->branchId()) : null,
            'maximo' => PppoeMassCutoff::MAXIMO,
            'hayQueElegirSucursal' => $contexto->hayQueElegirSucursal(),
            'sucursales' => $contexto->hayQueElegirSucursal() ? $contexto->sucursalesElegibles() : collect(),
        ]);
    }

    /** Qué pasaría con cada contrato de la lista. No corta nada. */
    public function preview(Request $request, PppoeMassCutoff $lector): View|RedirectResponse
    {
        $request->validate([
            'archivo' => 'nullable|file|max:5120|mimes:txt,csv,xlsx,xls',
            'lista' => 'nullable|string|max:100000',
        ], [
            'archivo.mimes' => 'El archivo debe ser .txt, .csv, .xlsx o .xls.',
            'archivo.max' => 'El archivo no puede pesar más de 5 MB.',
        ]);

        try {
            // La lectura de archivos es la del corte de PPPoE: detecta el
            // encabezado «contrato» y no se come los ceros a la izquierda.
            if ($request->hasFile('archivo')) {
                $numeros = $lector->identificadoresDesdeArchivo($request->file('archivo'));
                $origen = 'Archivo ' . $request->file('archivo')->getClientOriginalName();
            } else {
                $numeros = $lector->identificadoresDesdeTexto($request->input('lista'));
                $origen = 'Lista manual';
            }
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($numeros === []) {
            return back()->withInput()->with('error', 'No se encontró ningún número de contrato en lo que envió.');
        }

        $branchId = app(CurrentContext::class)->branchParaEscritura($request->input('branch_id'));
        $filas = $this->cortes->resolver($numeros, $branchId);

        return view('gestisp.technicals_orders.cortes.revisar', [
            'filas' => $filas,
            'resumen' => collect($filas)->countBy('estado'),
            'numeros' => $numeros,
            'origen' => mb_substr($origen, 0, 255),
            'branchId' => $branchId,
            'umbral' => $this->cortes->umbral($branchId),
        ]);
    }

    /** Crea la tanda y manda los cortes a la cola. */
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'numeros' => 'required|array|min:1|max:' . PppoeMassCutoff::MAXIMO,
            'numeros.*' => 'required|string|max:100',
            'reason' => 'required|string|max:450',
            'origen' => 'required|string|max:255',
            'confirmar' => 'accepted',
        ], [
            'reason.required' => 'Indique el motivo del corte: es lo que quedará en el historial de cada contrato.',
            'confirmar.accepted' => 'Confirme que revisó la lista antes de cortar.',
        ]);

        $branchId = app(CurrentContext::class)->branchParaEscritura($request->input('branch_id'));

        try {
            $tanda = $this->cortes->crearTanda($datos['numeros'], $branchId, $request->user()?->id, $datos['reason'], $datos['origen']);
        } catch (RuntimeException $e) {
            return redirect()->route('technicals_orders.cutoffs')->with('error', $e->getMessage());
        }

        return redirect()
            ->route('technicals_orders.cutoffs.show', $tanda)
            ->with('success', 'Corte en marcha: los contratos se van cortando uno a uno. Esta pantalla se actualiza sola.');
    }

    /** El historial en Excel: todas las tandas del rango, contrato por contrato. */
    public function export(Request $request)
    {
        [$desde, $hasta] = $this->rango($request);

        return Excel::download(
            new ContractCutoffExport(app(CurrentContext::class)->branchIds(), null, $desde, $hasta),
            'cortes-masivos-' . ($desde ?? 'inicio') . '-a-' . ($hasta ?? now()->toDateString()) . '.xlsx',
        );
    }

    /** Una tanda en Excel. */
    public function excel(ContractCutoff $corte)
    {
        $this->exigirSucursal($corte);

        return Excel::download(
            new ContractCutoffExport([$corte->branch_id], $corte->id),
            "corte-masivo-{$corte->id}.xlsx",
        );
    }

    /** Una tanda en PDF, para imprimir o archivar. */
    public function pdf(ContractCutoff $corte)
    {
        $this->exigirSucursal($corte);

        $items = $corte->items()->with('contract.client')->orderBy('id')->get();

        return PdfBranding::make('gestisp.technicals_orders.cortes.pdf', [
            'corte' => $corte->load('user', 'branch'),
            'items' => $items,
            'conteo' => $items->countBy('status'),
            'branch' => $corte->branch,
            'pdfTitle' => 'Corte masivo por mora N.º ' . $corte->id,
            'pdfSubtitle' => $corte->created_at->format('d/m/Y H:i'),
        ], landscape: true)->stream("corte-masivo-{$corte->id}.pdf");
    }

    /** Lo que pasó con cada contrato de la tanda. */
    public function show(ContractCutoff $corte): View
    {
        $this->exigirSucursal($corte);

        $items = $corte->items()->with(['contract.client', 'technicalOrder'])->orderBy('id')->get();

        return view('gestisp.technicals_orders.cortes.show', [
            'corte' => $corte->load('user', 'branch'),
            'items' => $items,
            'conteo' => $items->countBy('status'),
            'enCurso' => $items->contains('status', ContractCutoffItem::PENDIENTE),
        ]);
    }

    private function exigirSucursal(ContractCutoff $corte): void
    {
        abort_unless(app(CurrentContext::class)->permiteSucursal($corte->branch_id), 403, 'Ese corte es de otra sucursal.');
    }

    /** @return array{0: ?string, 1: ?string} fechas Y-m-d válidas o null */
    private function rango(Request $request): array
    {
        $request->validate([
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde',
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        return [$request->input('desde'), $request->input('hasta')];
    }
}
