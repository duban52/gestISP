<?php

namespace App\Http\Controllers;

use App\Models\Olt;
use App\Services\OltSshService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class OltController extends Controller
{
    protected $oltSshService;

    public function __construct(OltSshService $oltSshService)
    {
        $this->oltSshService = $oltSshService;
    }

    public function index(): View
    {

        return view('gestisp.olts.index');
    }

    public function create(): View
    {
        return view('gestisp.olts.create');
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
}
