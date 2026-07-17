<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Services\OltSnmpService;
use App\Services\OltSshService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controlador de ONTs
 *
 * Gestiona el ciclo de vida de las ONTs de la sucursal activa:
 * autorización (activate), consulta de estado y potencia (SSH/SNMP),
 * reubicación de puerto, CATV y eliminación.
 */
class OntController extends Controller
{
    protected OltSshService $oltSshService;
    protected OltSnmpService $snmpService;

    /**
     * Constructor: inyecta los servicios SSH/SNMP y protege las
     * rutas con autenticación y permisos.
     *
     * buscarContrato y checkSn quedan solo con auth porque son
     * consultas compartidas con el flujo de cuentas PPPoE.
     */
    public function __construct(OltSshService $oltSshService, OltSnmpService $snmpService)
    {
        $this->oltSshService = $oltSshService;
        $this->snmpService   = $snmpService;

        $this->middleware('auth');
        $this->middleware('check.permission:onts.index')->only('authorized_ont_index', 'no_authorized_ont_index');
        $this->middleware('check.permission:onts.show')->only('show', 'realtimeInfo', 'syncPower');
        $this->middleware('check.permission:onts.activate')->only('activate');
        $this->middleware('check.permission:onts.destroy')->only('destroy');
        $this->middleware('check.permission:onts.relocate')->only('relocate');
        $this->middleware('check.permission:onts.catv')->only('enableCatv', 'disableCatv');
    }

    public function no_authorized_ont_index()
    {
        $contracts = Contract::where('branch_id', session('branch_id'))->get();
        $olts      = Olt::where('branch_id', session('branch_id'))->get();
        return view('gestisp.onts.no-authorized.index', compact('olts', 'contracts'));
    }

    public function authorized_ont_index()
    {
        $onts = Ont::where('branch_id', session('branch_id'))
            ->with(['olt', 'contract'])
            ->get();

        return view('gestisp.onts.authorized.index', compact('onts'));
    }
    public function buscarContrato(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->get('q');

        $contratos = Contract::where('branch_id', session('branch_id'))
            ->whereHas('client', function ($q) use ($query) {
                $q->where('identity_number', 'like', "%{$query}%")
                    ->orWhere('name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%");
            })
            ->orWhere(function ($q) use ($query) {
                $q->where('branch_id', session('branch_id'))
                    ->where('id', 'like', "%{$query}%");
            })
            ->with('client')
            ->limit(10)
            ->get();

        return response()->json($contratos->map(fn($c) => [
            'id'              => $c->id,
            'label'           => $c->client->identity_number . ' - ' . $c->client->name . ' ' . $c->client->last_name . ' - Contrato #' . $c->id,
            'description'     => $c->client->identity_number . '-' . $c->client->name . ' ' . $c->client->last_name . '-' . $c->id,
            // Datos para autogenerar credenciales pppoe
            'client_name'     => $c->client->name,
            'client_lastname' => $c->client->last_name,
            'identity_number' => $c->client->identity_number,
        ]));
    }

    public function activate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'olt_id'          => 'required|exists:olts,id',
            'ont_sn'          => 'required|string',
            'ont_location'    => 'required|string',
            'contract_id'     => 'required|exists:contracts,id',
            'description'     => 'required|string',
            'vlan'            => 'required|integer',
            'ont_lineprofile' => 'required|integer',
            'ont_srvprofile'  => 'required|integer',
        ]);

        $olt = Olt::findOrFail($validated['olt_id']);
        $validated['fspon']       = $validated['ont_location'];
        $validated['client_name'] = $validated['description'];

        try {
            $result = $this->oltSshService->activateOnt($olt, $validated);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al activar la ONT: ' . $e->getMessage());
        }

        $parts   = explode('/', $validated['fspon']);
        $ifIndex = $this->resolveIfIndex($olt, $parts[1], $parts[2]);

        $ont = Ont::create([
            'branch_id'    => session('branch_id'),
            'olt_id'       => $validated['olt_id'],
            'contract_id'  => $validated['contract_id'],
            'slot'         => $parts[1],
            'port'         => $parts[2],
            'onu_id'       => $result['ont_id'],
            'service_port' => $result['service_port'],
            'sn'           => $validated['ont_sn'],
            'description'  => $validated['description'],
            'vlan'         => $validated['vlan'],
            'if_index'     => $ifIndex,
            'status'       => 1,
        ]);

// Actualizar el cpe_sn en el contrato con el serial de la ONT registrada
        Contract::where('id', $validated['contract_id'])
            ->update(['cpe_sn' => $validated['ont_sn']]);

        return back()->with('success', 'ONT activada y registrada correctamente.');
    }

    public function destroy(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        try {
            $this->oltSshService->deleteOnt($olt, $ont);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al eliminar la ONT: ' . $e->getMessage());
        }

        // Limpiar el cpe_sn del contrato antes de eliminar la ONT
        if ($ont->contract_id) {
            Contract::where('id', $ont->contract_id)
                ->update(['cpe_sn' => null]);
        }

        $ont->delete();

        return back()->with('success-delete', 'ONT eliminada correctamente.');
    }

    /**
     * Busca el ifIndex SNMP de una interfaz GPON dado su slot y port
     */
    private function resolveIfIndex(Olt $olt, string $slot, string $port): ?int
    {
        $host      = $olt->ip_address . ':' . ($olt->snmp_port ?? 161);
        $community = $olt->read_snmp_comunity;

        if (!$community) {
            return null;
        }

        try {
            $interfaces = @snmprealwalk($host, $community, '1.3.6.1.2.1.2.2.1.2', 2000000, 5);

            if (empty($interfaces)) {
                return null;
            }

            foreach ($interfaces as $oid => $value) {
                // Buscar la interfaz GPON_UNI que coincida con el slot/port
                if (preg_match('/GPON_UNI\s+\d+\/' . $slot . '\/' . $port . '$/', $value)) {
                    if (preg_match('/\.(\d+)$/', $oid, $m)) {
                        return (int) $m[1];
                    }
                }
            }
        } catch (\Exception $e) {
            // Si SNMP falla, la ONT se crea sin if_index
            // Se puede recuperar después con: php artisan olt:sync-interfaces
        }

        return null;
    }
    public function syncPower(Ont $ont): \Illuminate\Http\JsonResponse
    {
        $olt     = Olt::findOrFail($ont->olt_id);
        $success = $this->snmpService->syncSingleOntPower($olt, $ont);

        if (!$success) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo obtener la potencia.',
            ]);
        }

        $ont->refresh();

        return response()->json([
            'ok'       => true,
            'status'   => $ont->status,
            'rx_power' => $ont->rx_power,
            'message'  => $ont->status
                ? "Potencia actualizada: {$ont->rx_power} dBm"
                : 'ONT sin señal.',
        ]);
    }
    //Buscar si una ont ya existe para moverla de puerto
    public function checkSn(string $sn): \Illuminate\Http\JsonResponse
    {
        Log::debug('CHECK SN', [
            'sn_recibido' => $sn,
            'sn_length'   => strlen($sn),
        ]);

        $ont = Ont::where('branch_id', session('branch_id'))
            ->where('sn', $sn)
            ->with('olt')
            ->first();

        Log::debug('CHECK SN RESULT', [
            'found'  => $ont ? true : false,
            'sn_db'  => $ont?->sn,
        ]);

        if (!$ont) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists'           => true,
            'ont_id'           => $ont->id,
            'current_location' => "0/{$ont->slot}/{$ont->port}",
            'onu_id'           => $ont->onu_id,
            'description'      => $ont->description,
            'olt_name'         => $ont->olt->name ?? 'N/A',
        ]);
    }
    //Mover la ont

    public function relocate(Request $request, Ont $ont): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'ont_location'    => 'required|string',
            'vlan'            => 'required|integer',
            'ont_lineprofile' => 'required|integer',
            'ont_srvprofile'  => 'required|integer',
        ]);

        $olt = Olt::findOrFail($ont->olt_id);

        $validated['fspon'] = $validated['ont_location'];

        try {
            $result = $this->oltSshService->moveOnt($olt, $ont, $validated);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al mover la ONT: ' . $e->getMessage());
        }

        $parts   = explode('/', $validated['fspon']);
        $ifIndex = $this->resolveIfIndex($olt, $parts[1], $parts[2]);

        $ont->update([
            'slot'         => $parts[1],
            'port'         => $parts[2],
            'onu_id'       => $result['ont_id'],
            'service_port' => $result['service_port'],
            'vlan'         => $validated['vlan'],
            'if_index'     => $ifIndex,
            'status'       => 1,
        ]);

        return back()->with('success', 'ONT movida y actualizada correctamente.');
    }
    public function show(Ont $ont)
    {
        // Carga instantánea: solo datos de la DB
        $ont->load(['olt', 'contract.client']);

        return view('gestisp.onts.show', compact('ont'));
    }

    /**
     * Endpoint AJAX: información en tiempo real de la ONT via SSH
     */
    public function realtimeInfo(Ont $ont): \Illuminate\Http\JsonResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        try {
            $realtime = $this->oltSshService->getOntOpticalInfo($olt, $ont);

            return response()->json([
                'ok'   => true,
                'data' => $realtime,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo obtener información en tiempo real: ' . $e->getMessage(),
            ]);
        }
    }
    public function enableCatv(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        try {
            $this->oltSshService->setCatvPort($olt, $ont, true);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al habilitar CATV: ' . $e->getMessage());
        }

        return back()->with('success', 'Puerto CATV habilitado correctamente.');
    }

    public function disableCatv(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        try {
            $this->oltSshService->setCatvPort($olt, $ont, false);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al deshabilitar CATV: ' . $e->getMessage());
        }

        return back()->with('success-update', 'Puerto CATV deshabilitado.');
    }
}
