<?php

namespace App\Http\Controllers;

use App\Models\CableStrand;
use App\Models\FiberCable;
use App\Models\NapBox;
use App\Models\Olt;
use App\Models\OpticalNetwork;
use App\Models\SpliceClosure;
use App\Services\Audit\AuditLogger;
use App\Services\FiberPathTracer;
use App\Services\FiberPlantManager;
use App\Support\FiberColors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Cables de fibra y sus hilos.
 *
 * La ficha de un cable es donde se ve la rejilla de hilos con sus
 * colores y qué hay conectado a cada uno. Es lo que se mira antes de
 * prometerle una derivación a alguien: si no quedan hilos vírgenes en
 * el troncal, hay que tirar cable, y eso no se hace en una tarde.
 */
class FiberCableController extends Controller
{
    public function __construct(
        private readonly FiberPlantManager $planta,
        private readonly AuditLogger $auditLogger,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:cables.index')->only('index', 'show', 'impact');
        // Los hilos se listan con el permiso de EDITAR CAJAS, no con el
        // de cables: quien documenta una caja tiene que poder decir de
        // qué hilo se alimenta aunque no administre la planta de fibra.
        $this->middleware('check.permission:naps.edit')->only('strands');
        $this->middleware('check.permission:cables.create')->only('create', 'store');
        $this->middleware('check.permission:cables.edit')->only('edit', 'update', 'updateStrand');
        $this->middleware('check.permission:cables.destroy')->only('destroy');
    }

    public function index(Request $request): View
    {
        $cables = FiberCable::deSucursal()
            ->with(['network', 'zone', 'strands', 'from', 'to'])
            ->when($request->filled('network_id'), fn ($q) => $q->where('optical_network_id', $request->network_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%' . trim($request->q) . '%';
                $q->where(fn ($s) => $s->where('code', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->orderBy('code')
            ->get();

        return view('gestisp.networks.cables.index', [
            'cables' => $cables,
            'redes' => OpticalNetwork::deSucursal()->orderBy('name')->get(),
            'filtros' => $request->all(),
            'resumen' => [
                'total' => $cables->count(),
                'hilos' => (int) $cables->sum('fiber_count'),
                'en_uso' => $cables->sum(fn (FiberCable $c) => $c->hilosEnUso()),
                'libres' => $cables->sum(fn (FiberCable $c) => $c->hilosLibres()),
                'metros' => (int) $cables->sum('length_m'),
            ],
        ]);
    }

    public function show(FiberCable $cable): View
    {
        $this->exigirSucursal($cable);

        $cable->load([
            'network', 'zone', 'from', 'to', 'user',
            'strands.napBox',
            'strands.splitterEntrada.closure',
            'strands.splitterSalida.splitter.closure',
        ]);

        // Las fusiones de cada hilo se resuelven de una vez: pedirlas
        // hilo por hilo en la vista serían 48 consultas por cable.
        $fusiones = \App\Models\Splice::whereIn('strand_a_id', $cable->strands->pluck('id'))
            ->orWhereIn('strand_b_id', $cable->strands->pluck('id'))
            ->with(['closure', 'strandA.cable', 'strandB.cable'])
            ->get();

        $porHilo = [];

        foreach ($fusiones as $fusion) {
            $porHilo[$fusion->strand_a_id][] = $fusion;
            $porHilo[$fusion->strand_b_id][] = $fusion;
        }

        return view('gestisp.networks.cables.show', [
            'cable' => $cable,
            'ocupacion' => $cable->ocupacion(),
            'fusionesPorHilo' => $porHilo,
            // Agrupados por buffer: es como está el cable de verdad y
            // como lo abre el técnico.
            'porBuffer' => $cable->strands->groupBy('buffer_number'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('gestisp.networks.cables.create', [
            'redes' => OpticalNetwork::deSucursal()->with('zones')->orderBy('name')->get(),
            'redPreseleccionada' => $request->query('network_id'),
            'capacidades' => FiberColors::capacidadesHabituales(),
            'extremos' => $this->extremosDisponibles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $red = OpticalNetwork::findOrFail($datos['optical_network_id']);

        abort_if((int) $red->branch_id !== (int) session('branch_id'), 403);

        $datos = array_merge($datos, $this->resolverExtremos($request, $red));

        try {
            $cable = $this->planta->crearCable($red, $datos);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('cables.show', $cable)
            ->with('success', "Cable {$cable->code} registrado con sus {$cable->fiber_count} hilos.");
    }

    public function edit(FiberCable $cable): View
    {
        $this->exigirSucursal($cable);

        return view('gestisp.networks.cables.edit', [
            'cable' => $cable,
            'redes' => OpticalNetwork::deSucursal()->with('zones')->orderBy('name')->get(),
            'capacidades' => FiberColors::capacidadesHabituales(),
            'extremos' => $this->extremosDisponibles(),
        ]);
    }

    /**
     * Actualiza el cable.
     *
     * La capacidad NO se toca aquí: cambiarla obligaría a borrar o
     * crear hilos, y los que ya están fusionados se llevarían por
     * delante la documentación de por dónde va cada cliente. Un cable
     * de otra capacidad es otro cable.
     */
    public function update(Request $request, FiberCable $cable): RedirectResponse
    {
        $this->exigirSucursal($cable);

        $datos = $this->validar($request, $cable);

        unset($datos['fiber_count'], $datos['buffer_count'], $datos['fibers_per_buffer']);

        $cable->update(array_merge($datos, $this->resolverExtremos($request, $cable->network)));

        $this->auditLogger->action(
            'fiber_cables.updated',
            "Actualizó el cable {$cable->code}",
            ['cable' => $cable->code, 'desde' => $cable->desde_legible, 'hasta' => $cable->hasta_legible],
            $cable,
            'red',
        );

        return redirect()->route('cables.show', $cable)->with('success', 'Cable actualizado.');
    }

    public function destroy(FiberCable $cable): RedirectResponse
    {
        $this->exigirSucursal($cable);

        $enUso = $cable->strands->filter(fn (CableStrand $h) => $h->estaEnUso())->count();

        if ($enUso > 0) {
            return back()->with('error', sprintf(
                'No se puede eliminar: %d hilo(s) del cable %s están conectados. Deshaga sus fusiones primero.',
                $enUso,
                $cable->code,
            ));
        }

        $codigo = $cable->code;
        $cable->delete();

        $this->auditLogger->action(
            'fiber_cables.deleted',
            "Eliminó el cable {$codigo}",
            ['cable' => $codigo],
            null,
            'red',
        );

        return redirect()->route('cables.index')->with('success', "Cable {$codigo} eliminado.");
    }

    /** Marca un hilo como dañado o reservado. */
    public function updateStrand(Request $request, CableStrand $strand): RedirectResponse
    {
        $strand->loadMissing('cable.network');
        $this->exigirSucursal($strand->cable);

        $datos = $request->validate([
            'status' => ['required', Rule::in(array_keys(CableStrand::estadosEditables()))],
            'notes' => 'nullable|string|max:255',
        ]);

        $anterior = $strand->status;
        $strand->update($datos);

        $this->auditLogger->action(
            'cable_strands.updated',
            sprintf(
                'Marcó el hilo %s como %s (antes %s)',
                $strand->etiqueta_completa,
                CableStrand::estadosEditables()[$datos['status']],
                CableStrand::estadosEditables()[$anterior] ?? $anterior,
            ),
            [
                'cable' => $strand->cable->code,
                'hilo' => $strand->posicion_legible,
                'antes' => $anterior,
                'ahora' => $datos['status'],
            ],
            $strand,
            'red',
        );

        return back()->with('success', 'Hilo actualizado.');
    }

    /**
     * Hilos de un cable para elegir en otro formulario (JSON).
     *
     * Solo los que tienen un extremo suelto, más el que ya estuviera
     * elegido: si no se incluyera, al editar una caja su propio hilo
     * desaparecería del desplegable y se perdería al guardar.
     *
     * Va por AJAX y no incrustado en la página porque una red con
     * cincuenta cables de 48 son miles de hilos: cargarlos todos para
     * elegir uno haría pesada cada visita al formulario.
     */
    public function strands(Request $request, FiberCable $cable): JsonResponse
    {
        $this->exigirSucursal($cable);

        $actual = $request->integer('actual');

        $hilos = $cable->strands()
            ->with(['napBox', 'splitterSalida'])
            ->get()
            ->filter(fn (CableStrand $h) => $h->estaDisponible() || (int) $h->id === $actual)
            ->map(fn (CableStrand $h) => [
                'id' => $h->id,
                'texto' => $h->posicion_legible,
                'numero' => $h->number,
                // Se dice si ya viene de un splitter: es la señal de que
                // ese hilo es el bueno para colgar una caja, y ayuda a
                // no elegir uno cualquiera.
                'origen' => $h->splitterSalida
                    ? 'salida ' . $h->splitterSalida->number . ' de un splitter'
                    : null,
                'conexiones' => $h->conexiones(),
            ])
            ->values();

        return response()->json($hilos);
    }

    /** A quién deja sin servicio cortar este cable (JSON). */
    public function impact(FiberCable $cable, FiberPathTracer $trazador): JsonResponse
    {
        $this->exigirSucursal($cable);

        return response()->json($trazador->impactoDeCable($cable));
    }

    // ==================== Apoyo ====================

    /**
     * Qué se puede poner en cada extremo de un cable.
     *
     * @return array<string, array<int, array{id: int, texto: string}>>
     */
    private function extremosDisponibles(): array
    {
        $branchId = session('branch_id');

        return [
            'olts' => Olt::where('branch_id', $branchId)->orderBy('name')->get()
                ->map(fn (Olt $o) => ['id' => $o->id, 'texto' => 'OLT ' . $o->name])->all(),
            'muflas' => SpliceClosure::deSucursal()->orderBy('code')->get()
                ->map(fn (SpliceClosure $m) => [
                    'id' => $m->id,
                    'texto' => 'Mufla ' . $m->code . ($m->name ? ' — ' . $m->name : ''),
                ])->all(),
            'cajas' => NapBox::deSucursal()->orderBy('code')->get()
                ->map(fn (NapBox $c) => [
                    'id' => $c->id,
                    'texto' => 'Caja ' . $c->code . ($c->name ? ' — ' . $c->name : ''),
                ])->all(),
        ];
    }

    /**
     * Traduce los selectores del formulario a la relación polimórfica.
     *
     * El formulario manda "mufla:12" en vez del nombre de la clase:
     * meter nombres de clase de PHP en un formulario invitaría a que
     * alguien mande cualquier otra cosa.
     *
     * @return array<string, mixed>
     */
    private function resolverExtremos(Request $request, OpticalNetwork $red): array
    {
        $resultado = [];

        foreach (['from', 'to'] as $extremo) {
            $valor = $request->input($extremo);

            if (blank($valor)) {
                $resultado["{$extremo}_type"] = null;
                $resultado["{$extremo}_id"] = null;

                continue;
            }

            [$tipo, $id] = array_pad(explode(':', $valor, 2), 2, null);

            $modelo = match ($tipo) {
                'olt' => Olt::where('branch_id', session('branch_id'))->find($id),
                'mufla' => SpliceClosure::deSucursal()->find($id),
                'caja' => NapBox::deSucursal()->find($id),
                default => null,
            };

            abort_if($modelo === null, 422, 'El extremo elegido no existe en esta sucursal.');

            $resultado["{$extremo}_type"] = $modelo::class;
            $resultado["{$extremo}_id"] = $modelo->id;
        }

        return $resultado;
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?FiberCable $cable = null): array
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
                Rule::unique('fiber_cables', 'code')
                    ->where('optical_network_id', $request->input('optical_network_id'))
                    ->ignore($cable?->id),
            ],
            'name' => 'nullable|string|max:255',
            'type' => ['required', Rule::in(array_keys(FiberCable::TIPOS))],
            'fiber_count' => 'required|integer|min:1|max:576',
            'buffer_count' => 'required|integer|min:1|max:48',
            'fibers_per_buffer' => 'required|integer|min:1|max:24',
            'length_m' => 'nullable|integer|min:0|max:200000',
            'installation' => ['nullable', Rule::in(array_keys(FiberCable::INSTALACIONES))],
            'owner' => 'nullable|string|max:255',
            'status' => 'required|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ], [
            'code.unique' => 'Ya hay un cable con ese código en esta red.',
        ]);
    }

    private function exigirSucursal(?FiberCable $cable): void
    {
        abort_if(
            !$cable || (int) $cable->network?->branch_id !== (int) session('branch_id'),
            403,
            'Ese cable pertenece a otra sucursal.',
        );
    }
}
