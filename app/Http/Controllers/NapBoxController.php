<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\NapBox;
use App\Models\NapPort;
use App\Models\OpticalNetwork;
use App\Services\Audit\AuditLogger;
use App\Services\NapFinder;
use App\Services\OdnManager;
use App\Support\NapSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Cajas NAP / CTO: el punto donde se conecta el cliente.
 *
 * Es la pantalla que más se usa del módulo, porque responde las dos
 * preguntas del día a día:
 *
 *   El instalador  → "¿en qué caja y qué puerto conecto a este cliente?"
 *   El de ventas   → "¿hay cupo cerca de esta dirección?"
 *
 * La segunda es la que justifica las coordenadas: sin el punto en el
 * mapa no se puede responder en una llamada.
 */
class NapBoxController extends Controller
{
    public function __construct(
        private readonly OdnManager $odn,
        private readonly AuditLogger $auditLogger,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:naps.index')->only('index', 'show', 'map', 'mapData', 'nearby');
        // byPonPort se protege con el permiso de AUTORIZAR ONTs, no con
        // el del módulo de redes: su único consumidor es el modal de
        // activación, y quien instala tiene que poder ver en qué caja
        // queda el cliente aunque no administre la red. La consulta ya
        // está acotada a la sucursal activa.
        $this->middleware('check.permission:onts.activate')->only('byPonPort');
        $this->middleware('check.permission:naps.create')->only('create', 'store');
        $this->middleware('check.permission:naps.edit')->only('edit', 'update', 'updatePort', 'releasePort');
        $this->middleware('check.permission:naps.destroy')->only('destroy');
    }

    /** Listado con ocupación y filtros. */
    public function index(Request $request): View
    {
        $cajas = NapBox::deSucursal()
            ->with(['network', 'zone', 'ponPort.olt', 'ports.contract'])
            ->when($request->filled('network_id'), fn ($q) => $q->where('optical_network_id', $request->network_id))
            ->when($request->filled('zone_id'), fn ($q) => $q->where('network_zone_id', $request->zone_id))
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%' . trim($request->q) . '%';
                $q->where(fn ($s) => $s->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('address', 'like', $like));
            })
            ->orderBy('code')
            ->get();

        // "Solo con cupo" y "solo llenas" se resuelven en memoria: la
        // ocupación depende de los contratos de cada puerto y no se
        // puede filtrar en SQL sin una consulta bastante peor.
        if ($request->input('cupo') === 'si') {
            $cajas = $cajas->filter->tieneCupo()->values();
        } elseif ($request->input('cupo') === 'no') {
            $cajas = $cajas->reject->tieneCupo()->values();
        }

        return view('gestisp.networks.naps.index', [
            'cajas' => $cajas,
            'redes' => OpticalNetwork::deSucursal()->orderBy('name')->get(),
            'filtros' => $request->all(),
            'resumen' => [
                'total' => $cajas->count(),
                'capacidad' => (int) $cajas->sum('capacity'),
                'ocupados' => $cajas->sum(fn ($c) => $c->puertosOcupados()),
                'disponibles' => $cajas->sum(fn ($c) => $c->puertosDisponibles()),
            ],
        ]);
    }

    /** Ficha de la caja: puertos, quién los ocupa y su ubicación. */
    public function show(NapBox $nap): View
    {
        $this->exigirSucursal($nap);

        $nap->load([
            'network', 'zone', 'ponPort.olt', 'user',
            'ports.contract.client',
            // La ONT de cada contrato: es lo que permite ver la señal de
            // todos los clientes de la caja en una sola pantalla. Va
            // precargada porque pedirla puerto a puerto serían dieciséis
            // consultas más en una caja de dieciséis.
            'ports.contract.ont',
            'feedStrand.cable',
        ]);

        return view('gestisp.networks.naps.show', [
            'nap' => $nap,
            'ocupacion' => $nap->ocupacion(),
            // Por dónde le llega la fibra desde la cabecera. Vacío
            // mientras no se haya documentado el hilo que la alimenta.
            'ruta' => app(\App\Services\FiberPathTracer::class)->rutaDeCaja($nap),
        ]);
    }

    public function create(Request $request): View
    {
        return view('gestisp.networks.naps.create', [
            'redes' => OpticalNetwork::deSucursal()
                ->with(['ponPorts.olt', 'zones', 'fiberCables'])
                ->orderBy('name')
                ->get(),
            'redSeleccionada' => $request->input('network_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $red = OpticalNetwork::deSucursal()->findOrFail($datos['optical_network_id']);

        $hilo = $this->hiloDeAlimentacion($datos, $red);
        unset($datos['feed_strand_id']);

        $caja = $this->odn->crearCaja($red, $datos);

        // Se conecta DESPUÉS de crearla, porque hasta que la caja no
        // existe no hay a qué asignarle el hilo. Va por el servicio de
        // la planta para que quede en la trazabilidad.
        $aviso = $this->conectarAlimentacion($caja, $hilo);

        return redirect()->route('naps.show', $caja)
            ->with('success', "Caja {$caja->code} creada con {$caja->capacity} puertos." . $aviso);
    }

    public function edit(NapBox $nap): View
    {
        $this->exigirSucursal($nap);

        return view('gestisp.networks.naps.edit', [
            'nap' => $nap,
            'redes' => OpticalNetwork::deSucursal()
                ->with(['ponPorts.olt', 'zones', 'fiberCables'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(Request $request, NapBox $nap): RedirectResponse
    {
        $this->exigirSucursal($nap);

        $datos = $this->validar($request, $nap);

        try {
            // La capacidad se ajusta aparte: crear o quitar puertos no
            // es un update cualquiera y puede fallar con motivo.
            $this->odn->ajustarCapacidad($nap, (int) $datos['capacity']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $hilo = $this->hiloDeAlimentacion($datos, $nap->network);

        unset($datos['capacity'], $datos['feed_strand_id']);
        $nap->update($datos);

        // Solo se toca la alimentación si el formulario mandó el campo:
        // así una pantalla que no lo tenga no desconecta la caja sin
        // que nadie se lo haya pedido.
        $aviso = $request->has('feed_strand_id')
            ? $this->conectarAlimentacion($nap, $hilo)
            : '';

        return redirect()->route('naps.show', $nap)->with('success', 'Caja actualizada.' . $aviso);
    }

    public function destroy(NapBox $nap): RedirectResponse
    {
        $this->exigirSucursal($nap);

        if ($nap->puertosOcupados() > 0) {
            return back()->with('error',
                'No se puede eliminar: la caja tiene clientes conectados. Trasládelos primero.');
        }

        $codigo = $nap->code;
        $nap->delete();

        $this->auditLogger->action(
            'naps.deleted',
            "Eliminó la caja {$codigo}",
            ['caja' => $codigo],
            null,
            'red',
        );

        return redirect()->route('naps.index')->with('success', "Caja {$codigo} eliminada.");
    }

    // ==================== Puertos ====================

    /** Marca un puerto como libre, reservado o dañado. */
    public function updatePort(Request $request, NapPort $port): RedirectResponse
    {
        $this->exigirSucursal($port->napBox);

        $validado = $request->validate([
            'status' => ['required', Rule::in(array_keys(NapPort::estadosEditables()))],
            'notes' => 'nullable|string|max:255',
        ]);

        if ($port->estaOcupado()) {
            return back()->with('error',
                'Ese puerto tiene un cliente conectado. Libérelo antes de cambiar su estado.');
        }

        $anterior = $port->status;
        $port->update($validado);

        $this->auditLogger->action(
            'naps.port_status_changed',
            sprintf(
                'Marcó el puerto %d de la caja %s como %s',
                $port->number,
                $port->napBox->code,
                NapPort::estadosEditables()[$validado['status']],
            ),
            [
                'caja' => $port->napBox->code,
                'puerto' => $port->number,
                'antes' => $anterior,
                'ahora' => $validado['status'],
                'nota' => $validado['notes'] ?? null,
            ],
            $port->napBox,
            'red',
        );

        return back()->with('success', 'Estado del puerto actualizado.');
    }

    /** Desconecta el contrato que ocupa un puerto. */
    public function releasePort(NapPort $port): RedirectResponse
    {
        $this->exigirSucursal($port->napBox);

        $contrato = $port->contract;

        if (!$contrato) {
            return back()->with('error', 'Ese puerto no tiene ningún contrato conectado.');
        }

        $this->odn->liberarPuerto($contrato);

        return back()->with('success',
            "El puerto {$port->number} quedó libre. El contrato {$contrato->numero_visible} ya no tiene puerto asignado.");
    }

    // ==================== Mapa ====================

    /** Pantalla del mapa con todas las cajas. */
    public function map(): View
    {
        return view('gestisp.networks.naps.map', [
            'redes' => OpticalNetwork::deSucursal()->orderBy('name')->get(),
            // El "o" va AGRUPADO: sin los paréntesis, el orWhere se
            // sale del filtro de sucursal y la cuenta acaba incluyendo
            // cajas de otras sedes. Es la trampa clásica de mezclar
            // where y orWhere en la misma cadena.
            'sinUbicar' => NapBox::deSucursal()
                ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
                ->count(),
        ]);
    }

    /**
     * Cajas georreferenciadas, en JSON para el mapa.
     *
     * Va aparte de la vista para que la pantalla cargue de inmediato y
     * los marcadores lleguen después: con varios cientos de cajas,
     * incrustarlas en el HTML haría pesada la primera carga.
     */
    public function mapData(Request $request): JsonResponse
    {
        $cajas = NapBox::deSucursal()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($request->filled('network_id'), fn ($q) => $q->where('optical_network_id', $request->network_id))
            ->with(['zone', 'ponPort.olt', 'ports.contract'])
            ->get();

        return response()->json($cajas->map(function (NapBox $caja) {
            $ocupacion = $caja->ocupacion();

            return [
                'id' => $caja->id,
                'codigo' => $caja->code,
                'nombre' => $caja->name,
                'lat' => (float) $caja->latitude,
                'lng' => (float) $caja->longitude,
                'direccion' => $caja->address,
                'zona' => $caja->zone?->name,
                'color_zona' => $caja->zone?->color,
                'puerto_pon' => $caja->ponPort?->etiqueta,
                'olt' => $caja->ponPort?->olt?->name,
                'capacidad' => $ocupacion['capacidad'],
                'ocupados' => $ocupacion['ocupados'],
                'disponibles' => $ocupacion['disponibles'],
                'porcentaje' => $ocupacion['porcentaje'],
                'estado' => $caja->status,
                'url' => route('naps.show', $caja),
            ];
        }));
    }

    /**
     * Cajas con cupo cerca de un punto.
     *
     * Responde "¿este prospecto tiene cobertura?" sin que nadie mire
     * el mapa a ojo. Se ordena por distancia y dice además qué puerto
     * concreto habría que usar, que es la siguiente pregunta que se
     * hace siempre.
     *
     * El cálculo vive en NapFinder y no aquí: la ficha del contrato y
     * la pantalla del técnico hacen la misma pregunta, y dos copias de
     * "cuál es el siguiente puerto libre" acabarían respondiendo
     * distinto.
     */
    public function nearby(Request $request, NapFinder $buscador): JsonResponse
    {
        $validado = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radio' => 'nullable|numeric|min:0.05|max:20',
        ]);

        $sugerencias = $buscador->nearestTo(
            (float) $validado['lat'],
            (float) $validado['lng'],
            (float) ($validado['radio'] ?? NapFinder::DEFAULT_RADIUS_KM),
            20,
        );

        return response()->json($sugerencias->map(fn (NapSuggestion $sugerencia) => [
            'id' => $sugerencia->napBox->id,
            'codigo' => $sugerencia->napBox->code,
            'direccion' => $sugerencia->napBox->address,
            'zona' => $sugerencia->napBox->zone?->name,
            'distancia_m' => round($sugerencia->distanceM),
            'disponibles' => $sugerencia->freePorts,
            'capacidad' => $sugerencia->napBox->capacity,
            'puerto_sugerido' => $sugerencia->nextFreePort?->number,
            'etiqueta_puerto' => $sugerencia->portLabel(),
            'url' => route('naps.show', $sugerencia->napBox),
        ])->values());
    }

    /**
     * Cajas que cuelgan de un puerto PON, con sus puertos libres.
     *
     * Lo usa el modal de autorizar una ONT: si la ONT se está activando
     * en el puerto 0/1/2, las únicas cajas donde puede estar
     * físicamente conectada son las que cuelgan de ESE puerto. Ofrecer
     * todas las de la sucursal sería invitar a registrar una instalación
     * imposible.
     *
     * Va por AJAX y no incrustado en la página porque el puerto libre
     * de hoy puede ser el ocupado de dentro de diez minutos: al abrir el
     * modal se pregunta de nuevo.
     */
    public function byPonPort(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'olt' => ['required', Rule::exists('olts', 'id')->where('branch_id', session('branch_id'))],
            'slot' => 'required|integer|min:0',
            'port' => 'required|integer|min:0',
        ]);

        $cajas = NapBox::deSucursal()
            ->whereHas('ponPort', fn ($q) => $q
                ->where('olt_id', $validado['olt'])
                ->where('slot', $validado['slot'])
                ->where('port', $validado['port']))
            ->with(['ports.contract', 'zone'])
            ->orderBy('code')
            ->get();

        return response()->json($cajas->map(fn (NapBox $caja) => [
            'id' => $caja->id,
            'codigo' => $caja->code,
            'nombre' => $caja->name,
            'direccion' => $caja->address,
            'zona' => $caja->zone?->name,
            'disponibles' => $caja->puertosDisponibles(),
            'capacidad' => $caja->capacity,
            // Solo los libres: un puerto ocupado por otro cliente no es
            // una opción, y un puerto dañado tampoco.
            'puertos' => $caja->ports
                ->filter(fn (NapPort $p) => $p->estaDisponible())
                ->map(fn (NapPort $p) => ['id' => $p->id, 'numero' => $p->number])
                ->values(),
        ])->values());
    }

    // ==================== Alimentación ====================

    /**
     * Resuelve el hilo que alimenta la caja, comprobando que sea suyo.
     *
     * El id llega del navegador: el hilo tiene que ser de un cable de
     * la MISMA red, o se estaría documentando una caja alimentada por
     * una fibra de otra red.
     *
     * @param  array<string, mixed>  $datos
     */
    private function hiloDeAlimentacion(array $datos, OpticalNetwork $red): ?\App\Models\CableStrand
    {
        if (empty($datos['feed_strand_id'])) {
            return null;
        }

        $hilo = \App\Models\CableStrand::with('cable')->find($datos['feed_strand_id']);

        abort_unless(
            $hilo && (int) $hilo->cable?->optical_network_id === (int) $red->id,
            403,
            'Ese hilo pertenece a otra red.',
        );

        return $hilo;
    }

    /**
     * Conecta el hilo y devuelve el texto que se añade al mensaje.
     *
     * No lanza: la caja ya está creada o actualizada, y un fallo aquí
     * es de documentación, no de servicio. Convertirlo en error haría
     * creer que no se guardó nada.
     */
    private function conectarAlimentacion(NapBox $caja, ?\App\Models\CableStrand $hilo): string
    {
        try {
            app(\App\Services\FiberPlantManager::class)->alimentarCaja($caja, $hilo);
        } catch (RuntimeException $e) {
            return ' El hilo de alimentación no se anotó: ' . $e->getMessage();
        }

        return $hilo
            ? ' Se alimenta de ' . $hilo->etiqueta_completa . '.'
            : '';
    }

    // ==================== Apoyo ====================

    /** @return array<string, mixed> */
    private function validar(Request $request, ?NapBox $caja = null): array
    {
        $branchId = session('branch_id');

        return $request->validate([
            'optical_network_id' => [
                'required',
                Rule::exists('optical_networks', 'id')->where('branch_id', $branchId),
            ],
            // El puerto PON debe ser de la MISMA red: sin esta regla se
            // podría colgar una caja de un troncal de otra red con solo
            // cambiar el id en el formulario.
            'pon_port_id' => [
                'required',
                Rule::exists('pon_ports', 'id')
                    ->where('optical_network_id', $request->input('optical_network_id')),
            ],
            'network_zone_id' => [
                'nullable',
                Rule::exists('network_zones', 'id')
                    ->where('optical_network_id', $request->input('optical_network_id')),
            ],
            'name' => 'nullable|string|max:120',
            'capacity' => 'required|integer|min:1|max:64',
            'splitter_ratio' => 'nullable|string|max:10',
            // Dirección y punto en el mapa son OBLIGATORIOS a propósito.
            // Una caja sin ubicación no se puede encontrar en campo ni
            // sirve para responder "¿hay cobertura en esta dirección?",
            // que es la mitad de la razón de documentar la red. Permitir
            // guardarlas vacías es lo que convierte estos inventarios en
            // listas muertas al cabo de un año.
            'address' => 'required|string|max:255',
            'reference' => 'nullable|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'status' => ['required', Rule::in(array_keys(NapBox::estados()))],
            'notes' => 'nullable|string|max:1000',
            // De qué hilo se alimenta. Opcional: hay cajas que se
            // documentan antes de que exista el cable que las va a
            // alimentar, y exigirlo bloquearía el registro.
            'feed_strand_id' => ['nullable', Rule::exists('cable_strands', 'id')],
        ], [
            'pon_port_id.exists' => 'El puerto PON elegido no pertenece a esa red.',
            'network_zone_id.exists' => 'La zona elegida no pertenece a esa red.',
            'address.required' => 'La dirección es obligatoria: sin ella nadie encuentra la caja en campo.',
            'latitude.required' => 'Falta el punto en el mapa: haga clic sobre la ubicación de la caja.',
            'longitude.required' => 'Falta el punto en el mapa: haga clic sobre la ubicación de la caja.',
        ]);
    }

    private function exigirSucursal(?NapBox $caja): void
    {
        abort_if(
            !$caja || (int) $caja->network?->branch_id !== (int) session('branch_id'),
            403,
            'Esa caja pertenece a otra sucursal.',
        );
    }
}
