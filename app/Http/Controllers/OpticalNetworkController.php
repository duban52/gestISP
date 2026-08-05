<?php

namespace App\Http\Controllers;

use App\Models\NetworkZone;
use App\Models\Olt;
use App\Models\OpticalNetwork;
use App\Models\PonPort;
use App\Services\OdnManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Redes ópticas (ODN), zonas y puertos PON.
 *
 * Este controlador cubre las tres capas de arriba de la jerarquía; las
 * cajas NAP viven en NapBoxController porque tienen bastante más
 * enjundia (mapa, puertos, ocupación).
 *
 *   RED     la planta externa de una sucursal
 *   ZONA    sector que agrupa varios puertos PON — es con lo que se
 *           planea la expansión, y por eso existe
 *   PUERTO  el troncal que sale de la OLT
 *
 * Todo está limitado a la sucursal activa. Las rutas reciben el id por
 * la URL, así que además de filtrar los listados hay que cortar el
 * acceso directo con abort_if.
 */
class OpticalNetworkController extends Controller
{
    public function __construct(
        private readonly OdnManager $odn,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:networks.index')->only('index', 'show');
        $this->middleware('check.permission:networks.create')->only('create', 'store');
        $this->middleware('check.permission:networks.edit')->only(
            'edit', 'update', 'storeZone', 'updateZone', 'destroyZone',
            'storePonPort', 'updatePonPort', 'destroyPonPort', 'detectPonPorts',
        );
        $this->middleware('check.permission:networks.destroy')->only('destroy');
    }

    /** Listado de redes con sus cifras. */
    public function index(): View
    {
        $networks = OpticalNetwork::deSucursal()
            ->withCount(['olts', 'zones', 'ponPorts', 'napBoxes'])
            ->orderBy('name')
            ->get();

        return view('gestisp.networks.index', compact('networks'));
    }

    /** Ficha de la red: zonas, puertos PON y estado de las cajas. */
    public function show(OpticalNetwork $network): View
    {
        $this->exigirSucursal($network);

        $network->load([
            'olts',
            'zones.napBoxes.ports.contract',
            'ponPorts.olt',
            'ponPorts.zone',
            'napBoxes.ports.contract',
        ]);

        $cajas = $network->napBoxes;

        return view('gestisp.networks.show', [
            'network' => $network,
            'resumen' => [
                'cajas' => $cajas->count(),
                'capacidad' => (int) $cajas->sum('capacity'),
                'ocupados' => $cajas->sum(fn ($caja) => $caja->puertosOcupados()),
                'disponibles' => $cajas->sum(fn ($caja) => $caja->puertosDisponibles()),
                'sin_ubicar' => $cajas->reject->estaGeorreferenciada()->count(),
            ],
            // OLTs de la sucursal que todavía no pertenecen a una red
            'oltsLibres' => Olt::where('branch_id', session('branch_id'))
                ->whereNull('optical_network_id')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('gestisp.networks.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validarRed($request);

        $red = OpticalNetwork::create(array_merge($datos, [
            'branch_id' => session('branch_id'),
            'user_id' => auth()->id(),
        ]));

        return redirect()->route('networks.show', $red)
            ->with('success', 'Red creada. Ahora agregue sus OLTs y puertos PON.');
    }

    public function edit(OpticalNetwork $network): View
    {
        $this->exigirSucursal($network);

        return view('gestisp.networks.edit', compact('network'));
    }

    public function update(Request $request, OpticalNetwork $network): RedirectResponse
    {
        $this->exigirSucursal($network);

        $network->update($this->validarRed($request, $network));

        return redirect()->route('networks.show', $network)
            ->with('success', 'Red actualizada.');
    }

    /**
     * Elimina una red.
     *
     * Se bloquea si tiene cajas: al borrarla, la cascada se llevaría
     * puertos y dejaría contratos sin puerto sin que nadie se entere.
     */
    public function destroy(OpticalNetwork $network): RedirectResponse
    {
        $this->exigirSucursal($network);

        if ($network->napBoxes()->exists()) {
            return back()->with('error',
                'No se puede eliminar: la red tiene cajas NAP registradas. Elimínelas primero.');
        }

        $network->olts()->update(['optical_network_id' => null]);
        $network->delete();

        return redirect()->route('networks.index')->with('success', 'Red eliminada.');
    }

    // ==================== OLTs de la red ====================

    /** Asocia o desasocia una OLT a la red. */
    public function attachOlt(Request $request, OpticalNetwork $network): RedirectResponse
    {
        $this->exigirSucursal($network);

        $validado = $request->validate([
            'olt_id' => [
                'required',
                Rule::exists('olts', 'id')->where('branch_id', session('branch_id')),
            ],
        ]);

        Olt::whereKey($validado['olt_id'])->update(['optical_network_id' => $network->id]);

        return back()->with('success', 'OLT agregada a la red.');
    }

    public function detachOlt(OpticalNetwork $network, Olt $olt): RedirectResponse
    {
        $this->exigirSucursal($network);

        if ($olt->ponPorts()->exists()) {
            return back()->with('error',
                'No se puede quitar: la OLT tiene puertos PON documentados en esta red.');
        }

        $olt->update(['optical_network_id' => null]);

        return back()->with('success', 'OLT quitada de la red.');
    }

    // ==================== Zonas ====================

    public function storeZone(Request $request, OpticalNetwork $network): RedirectResponse
    {
        $this->exigirSucursal($network);

        $datos = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $network->zones()->create($datos);

        return back()->with('success', 'Zona creada.');
    }

    public function updateZone(Request $request, NetworkZone $zone): RedirectResponse
    {
        $this->exigirSucursal($zone->network);

        $zone->update($request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7',
        ]));

        return back()->with('success', 'Zona actualizada.');
    }

    public function destroyZone(NetworkZone $zone): RedirectResponse
    {
        $this->exigirSucursal($zone->network);

        // Las cajas y puertos quedan sin zona (nullOnDelete), no se
        // borran: la zona es una capa de organización, no de existencia.
        $zone->delete();

        return back()->with('success', 'Zona eliminada. Sus cajas quedaron sin zona asignada.');
    }

    // ==================== Puertos PON ====================

    public function storePonPort(Request $request, OpticalNetwork $network): RedirectResponse
    {
        $this->exigirSucursal($network);

        $datos = $request->validate([
            'olt_id' => [
                'required',
                Rule::exists('olts', 'id')->where('branch_id', session('branch_id')),
            ],
            'frame' => 'required|integer|min:0|max:99',
            'slot' => 'required|integer|min:0|max:99',
            'port' => 'required|integer|min:0|max:99',
            'network_zone_id' => [
                'nullable',
                Rule::exists('network_zones', 'id')->where('optical_network_id', $network->id),
            ],
            'description' => 'nullable|string|max:255',
            'splitter_ratio' => 'nullable|string|max:10',
            'max_onts' => 'required|integer|min:1|max:256',
        ]);

        $repetido = PonPort::where('olt_id', $datos['olt_id'])
            ->where('frame', $datos['frame'])
            ->where('slot', $datos['slot'])
            ->where('port', $datos['port'])
            ->exists();

        if ($repetido) {
            return back()->with('error', sprintf(
                'El puerto %d/%d/%d ya está registrado en esa OLT.',
                $datos['frame'], $datos['slot'], $datos['port'],
            ))->withInput();
        }

        $network->ponPorts()->create($datos);

        return back()->with('success', 'Puerto PON registrado.');
    }

    public function updatePonPort(Request $request, PonPort $ponPort): RedirectResponse
    {
        $this->exigirSucursal($ponPort->network);

        $ponPort->update($request->validate([
            'network_zone_id' => [
                'nullable',
                Rule::exists('network_zones', 'id')->where('optical_network_id', $ponPort->optical_network_id),
            ],
            'description' => 'nullable|string|max:255',
            'splitter_ratio' => 'nullable|string|max:10',
            'max_onts' => 'required|integer|min:1|max:256',
            'active' => 'nullable|boolean',
        ]));

        return back()->with('success', 'Puerto PON actualizado.');
    }

    public function destroyPonPort(PonPort $ponPort): RedirectResponse
    {
        $this->exigirSucursal($ponPort->network);

        if ($ponPort->napBoxes()->exists()) {
            return back()->with('error',
                'No se puede eliminar: hay cajas NAP colgando de este puerto.');
        }

        $ponPort->delete();

        return back()->with('success', 'Puerto PON eliminado.');
    }

    /**
     * Siembra los puertos PON que ya están en uso según las ONTs.
     *
     * Documentar a mano una red ya tendida es tedioso y se hace mal;
     * las ONTs ya saben de qué puerto cuelgan.
     */
    public function detectPonPorts(Request $request, OpticalNetwork $network): RedirectResponse
    {
        $this->exigirSucursal($network);

        $validado = $request->validate([
            'olt_id' => [
                'required',
                Rule::exists('olts', 'id')->where('branch_id', session('branch_id')),
            ],
        ]);

        $olt = Olt::findOrFail($validado['olt_id']);

        try {
            $creados = $this->odn->detectarPuertosPon($network, $olt);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $creados > 0
            ? "Se registraron {$creados} puerto(s) PON en uso. Complete el splitter y la zona de cada uno."
            : 'No se encontraron puertos nuevos: los que están en uso ya estaban registrados.');
    }

    // ==================== Apoyo ====================

    /** @return array<string, mixed> */
    private function validarRed(Request $request, ?OpticalNetwork $red = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('optical_networks', 'name')
                    ->where(fn ($q) => $q->where('branch_id', session('branch_id')))
                    ->ignore($red?->id),
            ],
            'description' => 'nullable|string|max:255',
            // El prefijo se usa para el consecutivo de cajas (NAP001)
            'nap_prefix' => 'required|string|max:10|regex:/^[A-Za-z0-9\-]+$/',
            'active' => 'nullable|boolean',
        ], [
            'name.unique' => 'Ya existe una red con ese nombre en esta sucursal.',
            'nap_prefix.regex' => 'El prefijo solo admite letras, números y guiones.',
        ]);
    }

    private function exigirSucursal(?OpticalNetwork $red): void
    {
        abort_if(
            !$red || (int) $red->branch_id !== (int) session('branch_id'),
            403,
            'Esa red pertenece a otra sucursal.',
        );
    }
}
