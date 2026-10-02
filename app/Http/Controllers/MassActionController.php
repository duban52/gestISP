<?php

namespace App\Http\Controllers;

use App\MassActions\Enums\MassActionItemStatus;
use App\MassActions\Enums\MassActionStatus;
use App\MassActions\Enums\MassActionType;
use App\MassActions\MassActionRegistry;
use App\MassActions\MassActionReverter;
use App\Models\MassAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Historial de acciones masivas y su reversión.
 *
 * Reservado al superadministrador por el middleware de la ruta Y por
 * la política: una URL escrita a mano no entra. Ver `MassActionPolicy`
 * para el porqué de las dos puertas.
 *
 * LA PANTALLA PAGINA Y NO CUENTA ÍTEMS
 * ------------------------------------
 * Los contadores viven en la cabecera de cada acción, escritos al
 * vuelo mientras se ejecutaba. Contar ítems por fila sería una
 * consulta por fila, y una acción puede tener cincuenta mil.
 */
class MassActionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', MassAction::class);

        $acciones = MassAction::with(['user', 'branch'])
            ->when($request->filled('tipo'), fn ($q) => $q->where('type', $request->string('tipo')))
            ->when($request->filled('estado'), fn ($q) => $q->where('status', $request->string('estado')))
            ->when($request->filled('usuario'), fn ($q) => $q->where('user_id', $request->integer('usuario')))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('hasta')))
            ->when($request->filled('buscar'), fn ($q) => $q->where('description', 'like', '%' . $request->string('buscar') . '%'))
            // El filtro de reversibles no se puede resolver en SQL —la
            // reversibilidad depende del tipo y de la estrategia—, así
            // que se acota lo que sí se puede y el resto lo filtra la
            // colección ya paginada.
            ->when($request->input('reversible') === 'no', fn ($q) => $q->whereNotNull('reverted_by_mass_action_id'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('gestisp.mass_actions.index', [
            'acciones' => $acciones,
            'tipos' => MassActionType::paraFiltro(),
            'estados' => MassActionStatus::cases(),
            'usuarios' => \App\Models\User::whereIn(
                'id',
                MassAction::whereNotNull('user_id')->distinct()->pluck('user_id'),
            )->orderBy('name')->get(),
        ]);
    }

    public function show(MassAction $massAction, Request $request): View
    {
        $this->authorize('view', $massAction);

        $items = $massAction->items()
            ->when($request->filled('estado_item'), fn ($q) => $q->where('status', $request->string('estado_item')))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('gestisp.mass_actions.show', [
            'accion' => $massAction->load(['user', 'branch', 'company', 'revierteA', 'revertidaPor']),
            'items' => $items,
            'estadosItem' => MassActionItemStatus::cases(),
            'estrategia' => app(MassActionRegistry::class)->para($massAction->type),
        ]);
    }

    /**
     * Qué pasaría si se revirtiera. No toca nada.
     *
     * Es lo que alimenta la confirmación: nadie debería revertir
     * ochocientos registros sin saber antes cuántos no se van a poder.
     */
    public function revisar(MassAction $massAction, MassActionReverter $reverter): View
    {
        $this->authorize('revert', $massAction);

        return view('gestisp.mass_actions.confirmar', [
            'accion' => $massAction,
            'revision' => $reverter->revisar($massAction),
            'estrategia' => app(MassActionRegistry::class)->para($massAction->type),
        ]);
    }

    /**
     * Deshace la acción.
     *
     * La confirmación escrita no es adorno: desde aquí se anulan
     * facturas y se borran contratos. Escribir la palabra obliga a
     * leer lo que se va a hacer.
     */
    public function revertir(Request $request, MassAction $massAction, MassActionReverter $reverter): RedirectResponse
    {
        $this->authorize('revert', $massAction);

        $request->validate(
            ['confirmacion' => 'required|in:REVERTIR'],
            ['confirmacion.*' => 'Escriba REVERTIR para confirmar la operación.'],
        );

        try {
            $reversion = $reverter->revertir($massAction);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo revertir: ' . $e->getMessage());
        }

        return redirect()
            ->route('mass_actions.show', $reversion)
            ->with('success', sprintf(
                'Reversión terminada: %d revertido(s), %d en conflicto, %d con error.',
                $reversion->reverted_items,
                $reversion->conflict_items,
                $reversion->failed_items,
            ));
    }
}
