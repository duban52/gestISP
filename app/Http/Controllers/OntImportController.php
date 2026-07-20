<?php

namespace App\Http\Controllers;

use App\Jobs\ImportOltOnts;
use App\Models\Olt;
use App\Models\OntImportRun;
use App\Services\OltOntDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Importación de ONTs existentes en una OLT.
 *
 * Permite incorporar a GestISP las ONTs que ya estaban trabajando
 * en la OLT (configuradas a mano o traídas de otro sistema como
 * Smart OLT o AdminOLT).
 *
 * El flujo es en tres pasos para que el usuario sepa siempre qué
 * va a pasar:
 *   1. Elegir la OLT y ver un análisis previo (cuántas ONTs hay,
 *      cuántas son nuevas, una muestra de lo que se traería).
 *   2. Confirmar: la importación se encola y corre en segundo
 *      plano.
 *   3. La pantalla consulta el avance y muestra el resultado.
 */
class OntImportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Importar ONTs equivale a darlas de alta: mismo permiso
        $this->middleware('check.permission:onts.activate');
    }

    /**
     * Pantalla de importación: OLTs de la sucursal y últimas
     * corridas realizadas.
     */
    /**
     * Sucursal activa como entero.
     *
     * En la sesión queda como TEXTO, porque se guarda desde el
     * campo del formulario de ingreso. Compararla en PHP sin
     * convertirla ('1' !== 1) rechazaba operaciones sobre la
     * sucursal correcta, así que toda comparación pasa por aquí.
     */
    private function branchId(): int
    {
        return (int) session('branch_id');
    }

    public function index(): View
    {
        $branchId = $this->branchId();

        $olts = Olt::where('branch_id', $branchId)
            ->where('active', true)
            ->withCount('onts')
            ->orderBy('name')
            ->get();

        $runs = OntImportRun::with(['olt', 'user'])
            ->where('branch_id', $branchId)
            ->latest('id')
            ->limit(10)
            ->get();

        return view('gestisp.onts.import.index', compact('olts', 'runs'));
    }

    /**
     * Análisis previo: consulta la OLT y responde qué se importaría,
     * SIN escribir nada en la base de datos.
     */
    public function preview(Request $request, OltOntDiscovery $discovery): JsonResponse
    {
        $validated = $request->validate([
            'olt_id' => 'required|exists:olts,id',
        ]);

        $olt = Olt::findOrFail($validated['olt_id']);

        if ((int) $olt->branch_id !== $this->branchId()) {
            return response()->json([
                'ok' => false,
                'message' => 'Esa OLT pertenece a otra sucursal.',
            ], 403);
        }

        try {
            $resumen = $discovery->preview($olt);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(array_merge(['ok' => true], $resumen));
    }

    /**
     * Encola la importación.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'olt_id' => 'required|exists:olts,id',
        ]);

        $olt = Olt::findOrFail($validated['olt_id']);
        $branchId = $this->branchId();

        if ((int) $olt->branch_id !== $branchId) {
            return back()->with('error', 'Esa OLT pertenece a otra sucursal.');
        }

        // Evitar dos importaciones simultáneas sobre la misma OLT:
        // duplicarían trabajo y podrían competir por los mismos
        // seriales
        $enCurso = OntImportRun::where('olt_id', $olt->id)
            ->whereIn('status', [OntImportRun::ESTADO_PENDIENTE, OntImportRun::ESTADO_EJECUTANDO])
            ->exists();

        if ($enCurso) {
            return back()->with('error', 'Ya hay una importación en curso para esta OLT. Espere a que termine.');
        }

        $run = OntImportRun::create([
            'olt_id' => $olt->id,
            'branch_id' => $branchId,
            'user_id' => auth()->id(),
            'status' => OntImportRun::ESTADO_PENDIENTE,
            'message' => 'En espera de procesamiento...',
        ]);

        ImportOltOnts::dispatch($run->id);

        return redirect()
            ->route('onts.import.index')
            ->with('success', 'La importación se está ejecutando en segundo plano. El avance se actualiza automáticamente.');
    }

    /**
     * Estado de una corrida, para la barra de progreso.
     */
    public function status(OntImportRun $run): JsonResponse
    {
        if ((int) $run->branch_id !== $this->branchId()) {
            abort(403);
        }

        return response()->json([
            'ok' => true,
            'status' => $run->status,
            'estado' => $run->estadoLegible(),
            'en_curso' => $run->enCurso(),
            'porcentaje' => $run->porcentaje(),
            'total_found' => $run->total_found,
            'processed' => $run->processed,
            'imported' => $run->imported,
            'skipped_existing' => $run->skipped_existing,
            'skipped_invalid' => $run->skipped_invalid,
            'matched_contracts' => $run->matched_contracts,
            'message' => $run->message,
        ]);
    }
}
