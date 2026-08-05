<?php

namespace App\Http\Controllers;

use App\Models\CableStrand;
use App\Models\FiberCable;
use App\Models\OpticalNetwork;
use App\Models\Splice;
use App\Models\SpliceClosure;
use App\Models\Splitter;
use App\Models\SplitterOutput;
use App\Services\Audit\AuditLogger;
use App\Services\FiberPathTracer;
use App\Services\FiberPlantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Muflas: el inventario y lo que se hace dentro de ellas.
 *
 * La ficha de una mufla es la pantalla que se abre antes de subir al
 * poste: qué cables llegan, qué está fusionado con qué, qué splitter
 * hay dentro y —lo que de verdad importa— a quién se deja sin servicio
 * mientras esté abierta.
 */
class SpliceClosureController extends Controller
{
    public function __construct(
        private readonly FiberPlantManager $planta,
        private readonly AuditLogger $auditLogger,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:closures.index')->only('index', 'show', 'map', 'impact');
        $this->middleware('check.permission:closures.create')->only('create', 'store');
        $this->middleware('check.permission:closures.edit')
            ->only(
                'edit', 'update',
                'storeSplice', 'destroySplice',
                'storeSplitter', 'destroySplitter', 'connectOutput',
            );
        $this->middleware('check.permission:closures.destroy')->only('destroy');
    }

    /** Listado con ocupación y filtros. */
    public function index(Request $request): View
    {
        $muflas = SpliceClosure::deSucursal()
            ->with(['network', 'zone', 'splices', 'splitters'])
            ->when($request->filled('network_id'), fn ($q) => $q->where('optical_network_id', $request->network_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%' . trim($request->q) . '%';
                $q->where(fn ($s) => $s->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('address', 'like', $like));
            })
            ->orderBy('code')
            ->get();

        return view('gestisp.networks.closures.index', [
            'muflas' => $muflas,
            'redes' => OpticalNetwork::deSucursal()->orderBy('name')->get(),
            'filtros' => $request->all(),
            'resumen' => [
                'total' => $muflas->count(),
                'fusiones' => $muflas->sum(fn (SpliceClosure $m) => $m->splices->count()),
                'splitters' => $muflas->sum(fn (SpliceClosure $m) => $m->splitters->count()),
                'capacidad' => $muflas->sum(fn (SpliceClosure $m) => $m->capacidadFusiones()),
            ],
        ]);
    }

    /** Ficha: cables que llegan, fusiones, splitters y a quién afecta. */
    public function show(SpliceClosure $closure, FiberPathTracer $trazador): View
    {
        $this->exigirSucursal($closure);

        $closure->load([
            'network', 'zone', 'user',
            'splices.strandA.cable', 'splices.strandB.cable',
            'splitters.inputStrand.cable', 'splitters.outputs.strand.cable',
        ]);

        return view('gestisp.networks.closures.show', [
            'mufla' => $closure,
            'ocupacion' => $closure->ocupacion(),
            'cables' => $closure->cables()->with('strands')->orderBy('code')->get(),
            // Los hilos que se pueden fusionar aquí: los de los cables
            // que llegan a esta mufla y todavía tienen un extremo libre.
            'hilosDisponibles' => $this->hilosConectables($closure),
            'impacto' => $trazador->impactoDeMufla($closure),
        ]);
    }

    public function create(Request $request): View
    {
        return view('gestisp.networks.closures.create', [
            'redes' => OpticalNetwork::deSucursal()->with('zones')->orderBy('name')->get(),
            'redPreseleccionada' => $request->query('network_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $red = OpticalNetwork::findOrFail($datos['optical_network_id']);

        $this->exigirSucursalDeRed($red);

        $mufla = $this->planta->crearMufla($red, $datos);

        return redirect()
            ->route('closures.show', $mufla)
            ->with('success', "Mufla {$mufla->code} registrada.");
    }

    public function edit(SpliceClosure $closure): View
    {
        $this->exigirSucursal($closure);

        return view('gestisp.networks.closures.edit', [
            'mufla' => $closure,
            'redes' => OpticalNetwork::deSucursal()->with('zones')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, SpliceClosure $closure): RedirectResponse
    {
        $this->exigirSucursal($closure);

        $datos = $this->validar($request, $closure);
        $closure->update($datos);

        $this->auditLogger->action(
            'splice_closures.updated',
            "Actualizó la mufla {$closure->code}",
            ['mufla' => $closure->code],
            $closure,
            'red',
        );

        return redirect()->route('closures.show', $closure)->with('success', 'Mufla actualizada.');
    }

    public function destroy(SpliceClosure $closure): RedirectResponse
    {
        $this->exigirSucursal($closure);

        // Una mufla con fusiones dentro es red viva: borrarla se
        // llevaría por delante el rastro de por dónde va cada cliente.
        if ($closure->splices()->exists()) {
            return back()->with('error', sprintf(
                'No se puede eliminar: la mufla %s tiene %d fusión(es) registradas. Deshágalas primero.',
                $closure->code,
                $closure->splices()->count(),
            ));
        }

        $codigo = $closure->code;
        $closure->delete();

        $this->auditLogger->action(
            'splice_closures.deleted',
            "Eliminó la mufla {$codigo}",
            ['mufla' => $codigo],
            null,
            'red',
        );

        return redirect()->route('closures.index')->with('success', "Mufla {$codigo} eliminada.");
    }

    // ==================== Fusiones ====================

    public function storeSplice(Request $request, SpliceClosure $closure): RedirectResponse
    {
        $this->exigirSucursal($closure);

        $datos = $request->validate([
            'strand_a_id' => ['required', 'different:strand_b_id', Rule::exists('cable_strands', 'id')],
            'strand_b_id' => ['required', Rule::exists('cable_strands', 'id')],
            'tray' => 'nullable|integer|min:1',
            'position' => 'nullable|integer|min:1',
            'type' => ['nullable', Rule::in([Splice::FUSION, Splice::MECANICO])],
            'loss_db' => 'nullable|numeric|min:0|max:20',
            'notes' => 'nullable|string|max:255',
        ], [
            'strand_a_id.different' => 'No se puede fusionar un hilo consigo mismo.',
        ]);

        $a = CableStrand::with('cable')->findOrFail($datos['strand_a_id']);
        $b = CableStrand::with('cable')->findOrFail($datos['strand_b_id']);

        // Los ids llegan del navegador: los dos hilos tienen que ser de
        // cables de la misma red que la mufla.
        foreach ([$a, $b] as $hilo) {
            abort_unless(
                (int) $hilo->cable->optical_network_id === (int) $closure->optical_network_id,
                403,
                'Ese hilo pertenece a otra red.',
            );
        }

        try {
            $this->planta->fusionar($closure, $a, $b, collect($datos)->only([
                'tray', 'position', 'type', 'loss_db', 'notes',
            ])->filter(fn ($v) => $v !== null)->all());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Fusión registrada.');
    }

    public function destroySplice(Splice $splice): RedirectResponse
    {
        $splice->loadMissing('closure');
        $this->exigirSucursal($splice->closure);

        $this->planta->deshacerFusion($splice);

        return back()->with('success', 'Fusión deshecha.');
    }

    // ==================== Splitters ====================

    public function storeSplitter(Request $request, SpliceClosure $closure): RedirectResponse
    {
        $this->exigirSucursal($closure);

        $datos = $request->validate([
            'code' => 'nullable|string|max:30',
            'ratio' => ['required', Rule::in(array_keys(Splitter::RATIOS))],
            'input_strand_id' => ['nullable', Rule::exists('cable_strands', 'id')],
            'insertion_loss_db' => 'nullable|numeric|min:0|max:40',
            'tray' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            $this->planta->montarSplitter($closure, $datos);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Splitter montado.');
    }

    public function destroySplitter(Splitter $splitter): RedirectResponse
    {
        $splitter->loadMissing('closure');
        $this->exigirSucursal($splitter->closure);

        $this->planta->desmontarSplitter($splitter);

        return back()->with('success', 'Splitter desmontado. Los hilos que colgaban quedaron sueltos.');
    }

    public function connectOutput(Request $request, SplitterOutput $output): RedirectResponse
    {
        $output->loadMissing('splitter.closure');
        $this->exigirSucursal($output->splitter->closure);

        $datos = $request->validate([
            'strand_id' => ['nullable', Rule::exists('cable_strands', 'id')],
        ]);

        $hilo = !empty($datos['strand_id'])
            ? CableStrand::with('cable')->findOrFail($datos['strand_id'])
            : null;

        if ($hilo) {
            abort_unless(
                (int) $hilo->cable->optical_network_id === (int) $output->splitter->closure->optical_network_id,
                403,
                'Ese hilo pertenece a otra red.',
            );
        }

        try {
            $this->planta->conectarSalida($output, $hilo);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $hilo ? 'Salida conectada.' : 'Salida liberada.');
    }

    // ==================== Consultas ====================

    /**
     * A quién deja sin servicio abrir esta mufla (JSON).
     *
     * Va por AJAX porque recorrer el grafo de una red grande cuesta
     * unos milisegundos que no tienen por qué retrasar la ficha.
     */
    public function impact(SpliceClosure $closure, FiberPathTracer $trazador): JsonResponse
    {
        $this->exigirSucursal($closure);

        return response()->json($trazador->impactoDeMufla($closure));
    }

    /** Muflas para pintarlas en el mapa junto a las cajas. */
    public function mapData(Request $request): JsonResponse
    {
        $muflas = SpliceClosure::deSucursal()
            ->when($request->filled('network_id'), fn ($q) => $q->where('optical_network_id', $request->network_id))
            ->with(['zone', 'splices'])
            ->get();

        return response()->json($muflas->map(function (SpliceClosure $mufla) {
            $ocupacion = $mufla->ocupacion();

            return [
                'id' => $mufla->id,
                'codigo' => $mufla->code,
                'nombre' => $mufla->name,
                'direccion' => $mufla->address,
                'tipo' => $mufla->tipo_legible,
                'zona' => $mufla->zone?->name,
                'lat' => (float) $mufla->latitude,
                'lng' => (float) $mufla->longitude,
                'fusiones' => $ocupacion['usadas'],
                'capacidad' => $ocupacion['capacidad'],
                'porcentaje' => $ocupacion['porcentaje'],
                'url' => route('closures.show', $mufla),
            ];
        })->values());
    }

    // ==================== Apoyo ====================

    /**
     * Hilos que se pueden fusionar en esta mufla.
     *
     * Son los de los cables que llegan a ella y que aún tienen un
     * extremo suelto. Ofrecer todos los de la red haría el desplegable
     * inmanejable y permitiría documentar fusiones imposibles.
     *
     * @return \Illuminate\Support\Collection<int, CableStrand>
     */
    private function hilosConectables(SpliceClosure $closure)
    {
        $cables = $closure->cables()->pluck('id');

        if ($cables->isEmpty()) {
            return collect();
        }

        return CableStrand::whereIn('fiber_cable_id', $cables)
            ->with('cable')
            ->orderBy('fiber_cable_id')
            ->orderBy('number')
            ->get()
            ->filter(fn (CableStrand $h) => $h->estaDisponible())
            ->values();
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?SpliceClosure $mufla = null): array
    {
        $branchId = session('branch_id');

        return $request->validate([
            'optical_network_id' => [
                'required',
                Rule::exists('optical_networks', 'id')->where('branch_id', $branchId),
            ],
            'network_zone_id' => [
                'nullable',
                Rule::exists('network_zones', 'id')
                    ->where('optical_network_id', $request->input('optical_network_id')),
            ],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('splice_closures', 'code')
                    ->where('optical_network_id', $request->input('optical_network_id'))
                    ->ignore($mufla?->id),
            ],
            'name' => 'nullable|string|max:255',
            'type' => ['required', Rule::in(array_keys(SpliceClosure::TIPOS))],
            'tray_count' => 'required|integer|min:1|max:48',
            'splices_per_tray' => 'required|integer|min:1|max:96',
            // Igual que en las cajas NAP: sin ubicación no se encuentra
            // en campo y el registro no sirve para nada.
            'address' => 'required|string|max:255',
            'reference' => 'nullable|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'status' => ['required', Rule::in(array_keys(SpliceClosure::estados()))],
            'notes' => 'nullable|string|max:1000',
        ], [
            'code.unique' => 'Ya hay una mufla con ese código en esta red.',
            'address.required' => 'La dirección es obligatoria: sin ella nadie encuentra la mufla en campo.',
            'latitude.required' => 'Falta el punto en el mapa: haga clic sobre la ubicación de la mufla.',
            'longitude.required' => 'Falta el punto en el mapa: haga clic sobre la ubicación de la mufla.',
        ]);
    }

    private function exigirSucursal(?SpliceClosure $mufla): void
    {
        abort_if(
            !$mufla || (int) $mufla->network?->branch_id !== (int) session('branch_id'),
            403,
            'Esa mufla pertenece a otra sucursal.',
        );
    }

    private function exigirSucursalDeRed(OpticalNetwork $red): void
    {
        abort_if((int) $red->branch_id !== (int) session('branch_id'), 403);
    }
}
