<?php

namespace App\Http\Controllers;

use App\Models\LineProfile;
use App\Models\Olt;
use App\Models\SrvProfile;
use App\Models\VlanOlt;
use App\Services\OltSshService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Controlador de OLTs
 *
 * Gestiona el registro de OLTs y su configuración (VLANs, perfiles
 * de línea y de servicio) consultada por SSH, además del autofind
 * de ONTs nuevas conectadas.
 */
class OltController extends Controller
{
    protected $oltSshService;

    /**
     * Constructor: inyecta el servicio SSH y protege las rutas
     * con autenticación y permisos.
     *
     * Las consultas de VLANs/perfiles pertenecen al formulario de
     * edición (olts.edit) y el autofind lo consume la vista de
     * ONTs por autorizar (onts.index).
     */
    public function __construct(OltSshService $oltSshService)
    {
        $this->oltSshService = $oltSshService;

        $this->middleware('auth');
        $this->middleware('check.permission:olts.index')->only('index', 'apiOlts');
        $this->middleware('check.permission:olts.create')->only('create', 'store');
        $this->middleware('check.permission:olts.edit')->only('edit', 'viewVlans', 'viewLineProfiles', 'viewSrvProfiles');
        $this->middleware('check.permission:olts.vlans')->only('storeVlan');
        $this->middleware('check.permission:onts.index')->only('ontsAutofind');
    }

    public function index(): View
    {

        return view('gestisp.olts.index');
    }

    public function create(): View
    {
        return view('gestisp.olts.create');
    }
    public function edit(Olt $olt)
    {
        return view('gestisp.olts.edit', compact('olt'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateOltData($request);
        $validated['branch_id'] = session('branch_id');

        // Cifrar la contraseña
        $validated['password'] = bcrypt($validated['password']);

        Olt::create($validated);

        return redirect()
            ->route('olts.index')
            ->with('success', 'OLT creada correctamente.');
    }

    public function ontsAutofind(Olt $olt): JsonResponse
    {
        try {
            $onts = $this->oltSshService->getAutoFindOnts($olt);
            return response()->json($onts);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener ONTs: ' . $e->getMessage()
            ], 500);
        }
    }

    public function apiOlts(): JsonResponse
    {
        $olts = Olt::byBranch(session('branch_id'))->get();

        $data = $olts->map(function ($olt) {
            $remoteData = $this->getRemoteData($olt);
            return [
                'id' => $olt->id,
                'name' => $olt->name,
                'ip_address' => $olt->ip_address,
                'status_text' => $remoteData['status'],
                'temperature' => $remoteData['temperature'],
                'uptime' => $remoteData['uptime'],
            ];
        });

        return response()->json($data);
    }


    /**
     * Obtiene datos remotos de la OLT via SSH
     */
    private function getRemoteData(Olt $olt): array
    {
        $defaultResult = [
            'status' => 'Desconectado',
            'temperature' => 'N/A',
            'uptime' => 'N/A',
        ];

        try {
            $result = $this->oltSshService->getOltStatus($olt);

            // Actualizar la base de datos con los datos obtenidos
            $this->updateOltStatus($olt, $result);

            return $result;
        } catch (\Exception $e) {
            // Marcar como desconectado en la base de datos
            $this->updateOltStatus($olt, $defaultResult, false);
            return $defaultResult;
        }
    }

    /**
     * Actualiza el estado de la OLT en la base de datos
     */
    private function updateOltStatus(Olt $olt, array $data, bool $connected = true): void
    {
        $updateData = ['status' => $connected];

        if ($connected) {
            $updateData['temperature'] = is_numeric($data['temperature']) ? $data['temperature'] : null;
            $updateData['uptime'] = $data['uptime'] !== 'N/A' ? $data['uptime'] : null;
        }

        $olt->update($updateData);
    }

    /**
     * Valida los datos del formulario de OLT
     */
    private function validateOltData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|min:5|max:255',
            'ip_address' => 'required|ip',
            'ssh_port' => 'required|numeric|min:1|max:65535',
            'telnet_port' => 'nullable|numeric|min:1|max:65535',
            'snmp_port' => 'nullable|numeric|min:1|max:65535',
            'read_snmp_comunity' => 'nullable|string|max:255',
            'write_snmp_comunity' => 'nullable|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
        ]);
    }
    //Muestra las vlans en la vista de editar las olt
    public function viewVlans(Olt $olt): JsonResponse
    {
        $vlans_olt = VlanOlt::where('olt_id', $olt->id)->get();

        $data = $vlans_olt->map(function ($vlan) {
            return [
                'id' => $vlan->id,
                'id_vlan' => $vlan->id_vlan,
                'name' => $vlan->name,
                'description' => $vlan->description,
            ];
        });

        return response()->json($data);
    }
    //Muestra los lineprofiles en la vista de editar las olt
    public function viewLineProfiles(Olt $olt): JsonResponse
    {
        $line_profile = LineProfile::where('olt_id', $olt->id)->get();

        $data = $line_profile->map(function ($lineProfile) {
            return [
                'id' => $lineProfile->id,
                'id_line_profile' => $lineProfile->id_line_profile,
                'name' => $lineProfile->name,
                'description' => $lineProfile->description,
            ];
        });

        return response()->json($data);
    }

    //Muestra los svrprofiles en la vista de editar las olt
    public function viewSrvProfiles(Olt $olt): JsonResponse
    {
        $srv_profiles = SrvProfile::where('olt_id', $olt->id)->get();

        $data = $srv_profiles->map(function ($srvProfile) {
            return [
                'id' => $srvProfile->id,
                'id_srv_profile' => $srvProfile->id_srv_pofile,
                'name' => $srvProfile->name,
                'description' => $srvProfile->description,
            ];
        });

        return response()->json($data);
    }

    //Crear nueva vlan (por el momento sólo va a la base de datos, en la olt se debe crear manual
    public function storeVlan(Request $request)
    {
        $validated = $request->validate([
            'olt_id' => 'required|exists:olts,id',
            'id_vlan' => 'required|integer|unique:vlan_olts,id_vlan',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        VlanOlt::create($validated);
        return redirect()->back()->with('success', '¡La VLAN se creó correctamente!');
    }

}
