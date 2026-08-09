<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Services\ContractGeolocator;
use App\Services\NapFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Ubicación de la vivienda del contrato.
 *
 * Va en su propio controlador —y no como un caso más del update() de
 * contratos— porque ContractController::update ya reparte a mano entre
 * "datos de residencia", "datos del servicio" y "datos técnicos" según
 * qué campos vengan en la petición. Meter aquí un cuarto caso haría
 * ese reparto todavía más frágil, y esto no es un campo más: mover el
 * punto de un servicio cambia a qué caja NAP se sugiere conectarlo y si
 * las órdenes cerradas allí se consideran hechas en sitio.
 */
class ContractLocationController extends Controller
{
    public function __construct(
        private readonly ContractGeolocator $geolocator,
        private readonly NapFinder $napFinder,
    ) {
        $this->middleware('auth');
        // Ubicar es editar el contrato: no se inventa un permiso nuevo
        // que habría que conceder a mano en cada rol existente.
        $this->middleware('check.permission:contracts.edit')->only('update');
        $this->middleware('check.permission:contracts.show')->only('nearbyNaps');
    }

    /**
     * Fija (o quita) la ubicación de la vivienda.
     *
     * Enviar las dos coordenadas vacías es la forma de decir "esto
     * estaba mal ubicado": se prefiere un contrato sin punto a uno con
     * un punto falso, porque con el falso se manda al técnico a la
     * dirección equivocada.
     */
    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->requireSameBranch($contract);

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'location_source' => 'nullable|string|in:mapa,dispositivo,orden',
        ], [
            'latitude.required_with' => 'Faltó la latitud: marque el punto sobre el mapa.',
            'longitude.required_with' => 'Faltó la longitud: marque el punto sobre el mapa.',
        ]);

        if (blank($validated['latitude'] ?? null)) {
            $this->geolocator->clear($contract);

            return redirect()->back()->with('success', 'Se quitó la ubicación del contrato.');
        }

        try {
            $this->geolocator->locate(
                $contract,
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                $validated['location_source'] ?? Contract::LOCATION_SOURCE_MAP,
            );
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Se guardó la ubicación de la vivienda.');
    }

    /**
     * Cajas NAP cercanas a la vivienda (JSON, para la ficha).
     *
     * Va por AJAX y no dentro de show() porque la ocupación de cada
     * caja se calcula en el momento: pedirla al abrir la ficha
     * retrasaría una pantalla que se consulta decenas de veces al día
     * para un dato que solo interesa cuando hay una instalación en
     * curso. Y, sobre todo, porque el puerto libre de hace cinco
     * minutos puede estar ocupado ahora.
     */
    public function nearbyNaps(Request $request, Contract $contract): JsonResponse
    {
        $this->requireSameBranch($contract);

        $validated = $request->validate([
            'radio' => 'nullable|numeric|min:0.05|max:20',
        ]);

        if (!$contract->isGeolocated()) {
            return response()->json([
                'georreferenciado' => false,
                'sugerencias' => [],
            ]);
        }

        $suggestions = $this->napFinder->forContract(
            $contract,
            (float) ($validated['radio'] ?? NapFinder::DEFAULT_RADIUS_KM),
        );

        return response()->json([
            'georreferenciado' => true,
            'sugerencias' => $suggestions->map(fn ($suggestion) => [
                'caja' => $suggestion->napBox->code,
                'nombre' => $suggestion->napBox->name,
                'direccion' => $suggestion->napBox->address,
                'zona' => $suggestion->napBox->zone?->name,
                'distancia' => $suggestion->humanDistance(),
                'distancia_m' => $suggestion->distanceM,
                'libres' => $suggestion->freePorts,
                'capacidad' => $suggestion->napBox->capacity,
                'puerto_sugerido' => $suggestion->nextFreePort?->number,
                'puerto_id' => $suggestion->nextFreePort?->id,
                'etiqueta_puerto' => $suggestion->portLabel(),
                'url' => route('naps.show', $suggestion->napBox),
            ])->values(),
        ]);
    }

    /**
     * Nadie toca contratos de otra sucursal.
     *
     * El id llega de la URL: sin esta comprobación bastaría cambiarlo a
     * mano para ubicar el servicio de otra sede.
     */
    private function requireSameBranch(Contract $contract): void
    {
        abort_if((int) $contract->branch_id !== (int) session('branch_id'), 403);
    }
}
