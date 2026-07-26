<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Branch;
use App\Models\User;
use App\Services\Audit\AuditLabels;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trazabilidad del sistema (solo superadministrador).
 *
 * Muestra quién hizo qué, cuándo y desde dónde. La tabla crece sin
 * límite —es una bitácora—, así que:
 *
 *  - Se pagina en el servidor en vez de volcar todo al navegador,
 *    que es lo que hacen los demás listados: con millones de filas,
 *    DataTables del lado del cliente colapsaría la página.
 *  - Sin filtros se muestran solo los últimos 7 días, no el histórico
 *    completo.
 */
class AuditController extends Controller
{
    /** Días que se muestran cuando no se pide un rango concreto. */
    private const DIAS_POR_DEFECTO = 7;

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('superadmin');
    }

    /**
     * Bitácora con filtros.
     */
    public function index(Request $request): View
    {
        $usandoRangoPorDefecto = !$request->filled('desde') && !$request->filled('hasta');

        $desde = $request->input('desde', now()->subDays(self::DIAS_POR_DEFECTO)->toDateString());
        $hasta = $request->input('hasta');

        $registros = Audit::query()
            ->with(['user', 'branch'])
            ->entreFechas($desde, $hasta)
            ->when($request->filled('usuario'), fn ($q) => $q->delUsuario($request->input('usuario')))
            ->when($request->filled('categoria'), fn ($q) => $q->deCategoria($request->input('categoria')))
            ->when($request->filled('accion'), function ($q) use ($request) {
                $accion = $request->input('accion');

                // "created/updated/deleted" son exactas; las acciones
                // con nombre se buscan por coincidencia parcial
                return in_array($accion, ['created', 'updated', 'deleted'], true)
                    ? $q->where('action', $accion)
                    : $q->where('action', 'like', "%{$accion}%");
            })
            ->when($request->filled('sucursal'), fn ($q) => $q->where('branch_id', $request->input('sucursal')))
            ->when($request->filled('buscar'), function ($q) use ($request) {
                $texto = $request->input('buscar');

                return $q->where(function ($sub) use ($texto) {
                    $sub->where('description', 'like', "%{$texto}%")
                        ->orWhere('user_name', 'like', "%{$texto}%")
                        ->orWhere('ip', 'like', "%{$texto}%")
                        ->orWhere('action', 'like', "%{$texto}%");
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Solo los usuarios que tienen actividad registrada
        $usuarios = User::whereIn('id', Audit::distinct()->pluck('user_id')->filter())
            ->orderBy('name')
            ->get(['id', 'name', 'last_name']);

        return view('gestisp.audits.index', [
            'registros' => $registros,
            'usuarios' => $usuarios,
            'sucursales' => Branch::orderBy('name')->get(['id', 'name']),
            'categorias' => AuditLabels::categorias(),
            'usandoRangoPorDefecto' => $usandoRangoPorDefecto,
            'diasPorDefecto' => self::DIAS_POR_DEFECTO,
        ]);
    }

    /**
     * Detalle de un registro: contexto completo y campos que cambiaron.
     */
    public function show(Audit $audit): View
    {
        $audit->load(['user', 'branch']);

        // Todo lo ocurrido en la misma petición: la acción y los
        // cambios de datos que provocó.
        $relacionados = $audit->request_id
            ? Audit::where('request_id', $audit->request_id)
                ->where('id', '!=', $audit->id)
                ->orderBy('id')
                ->get()
            : collect();

        return view('gestisp.audits.show', compact('audit', 'relacionados'));
    }
}
