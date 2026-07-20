<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Services\OltSnmpService;
use App\Services\OltSshService;
use Illuminate\Http\RedirectResponse;
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
    public function __construct(
        OltSshService $oltSshService,
        OltSnmpService $snmpService,
        private readonly \App\Services\ContractLinker $contractLinker,
    ) {
        $this->oltSshService = $oltSshService;
        $this->snmpService   = $snmpService;

        $this->middleware('auth');
        // Vincular no toca la OLT, pero decide a qué cliente se le
        // cobra este equipo: se protege igual que activarla
        $this->middleware('check.permission:onts.activate')->only('linkContract', 'unlinkContract');
        $this->middleware('check.permission:onts.index')->only('authorized_ont_index', 'no_authorized_ont_index');
        $this->middleware('check.permission:onts.show')->only('show', 'realtimeInfo', 'syncPower', 'metricsHistory');
        $this->middleware('check.permission:onts.activate')->only('activate');
        $this->middleware('check.permission:onts.destroy')->only('destroy');
        $this->middleware('check.permission:onts.relocate')->only('relocate');
        $this->middleware('check.permission:onts.catv')->only('enableCatv', 'disableCatv', 'checkCatvState');
        // Habilitar/deshabilitar la ONT corta o restablece el
        // servicio: se protege con el mismo permiso que activarla
        $this->middleware('check.permission:onts.activate')->only('enableOnt', 'disableOnt');
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
            // Equipos que ya tiene: al vincular hay que saber si el
            // contrato está libre antes de elegirlo
            ->withCount(['ont as onts_count', 'pppoeAccounts as pppoe_count'])
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
            'estado'          => $c->status,
            'tiene_ont'       => (int) $c->onts_count > 0,
            'cuentas_pppoe'   => (int) $c->pppoe_count,
        ]));
    }

    /**
     * Vincula la ONT a un contrato.
     *
     * Pensado para las ONTs importadas desde la OLT, que llegan sin
     * cliente asignado. Solo escribe en la base de datos: la ONT ya
     * está configurada en el equipo y no se toca.
     */
    public function linkContract(Request $request, Ont $ont): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
        ]);

        try {
            $contrato = $this->contractLinker->linkOnt($ont, (int) $validated['contract_id']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "ONT vinculada al contrato #{$contrato->id}.");
    }

    /**
     * Quita la asociación de la ONT con su contrato.
     */
    public function unlinkContract(Ont $ont): RedirectResponse
    {
        try {
            $this->contractLinker->unlinkOnt($ont);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'ONT desvinculada del contrato.');
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

    /**
     * Asegura que la ONT tenga su service-port resuelto.
     *
     * Las ONTs importadas desde la OLT llegan sin él (no se expone
     * por SNMP). Eliminar o mover una ONT ejecuta
     * "undo service-port {id}" en el equipo, así que sin este dato
     * se enviaría un comando incompleto: se consulta a la OLT y se
     * guarda antes de continuar.
     */
    private function ensureServicePort(Olt $olt, Ont $ont): void
    {
        if (!empty($ont->service_port)) {
            return;
        }

        $servicePort = $this->oltSshService->resolveServicePort($olt, $ont);

        if (!$servicePort) {
            throw new \RuntimeException(
                'No se pudo obtener el service-port de esta ONT en la OLT. ' .
                'Verifíquelo en el equipo antes de continuar.'
            );
        }

        $ont->update(['service_port' => $servicePort]);
        $ont->refresh();
    }

    public function destroy(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        try {
            // Las ONTs importadas llegan sin service-port: se
            // resuelve antes de que la OLT reciba el comando
            $this->ensureServicePort($olt, $ont);

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
        // Delegado al servicio SNMP: usa SNMPv2c con GETBULK (el
        // código anterior hacía un walk SNMPv1, mucho más lento) y
        // el patrón de interfaz vive en config/olt_snmp.php
        return $this->snmpService->resolvePonPortIfIndex($olt, $slot, $port);
    }

    /**
     * @deprecated Sustituido por OltSnmpService::resolvePonPortIfIndex
     */
    private function resolveIfIndexLegacy(Olt $olt, string $slot, string $port): ?int
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
            // Mover también ejecuta "undo service-port": las ONTs
            // importadas necesitan resolverlo primero
            $this->ensureServicePort($olt, $ont);

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
     * Endpoint AJAX: información en tiempo real de la ONT.
     *
     * Usa SNMP (milisegundos) en lugar de SSH (segundos): la ficha
     * completa se obtiene con UNA sola petición al equipo.
     *
     * El estado del puerto CATV es lo único que sigue requiriendo
     * CLI, así que se consulta por SSH solo si se pide de forma
     * explícita (?catv=1), para no penalizar la carga normal.
     */
    public function realtimeInfo(Request $request, Ont $ont): \Illuminate\Http\JsonResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        $result = $this->snmpService->getOntMetrics($olt, $ont, useCache: !$request->boolean('fresh'));

        if (!$result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['error'] ?? 'La OLT no respondió a la consulta SNMP.',
            ]);
        }

        // Aplanar a [clave => valor] para la vista
        $data = [];
        foreach ($result['metrics'] as $key => $metric) {
            $data[$key] = $metric['value'];
            $data[$key . '_unit'] = $metric['unit'];
        }

        // ---- CATV ----
        // Que el OID de potencia CATV responda significa que la ONT
        // tiene módulo de televisión. El estado on/off NO se puede
        // leer por SNMP (solo por CLI, ~40 s), así que se entrega el
        // último estado conocido y la vista ofrece verificarlo.
        $data['has_catv'] = $result['metrics']['catv_rx_power']['raw'] !== null;
        $data['catv_enabled'] = $ont->catv_enabled;
        $data['catv_checked_at'] = $ont->catv_checked_at?->format('d/m/Y H:i');
        $data['admin_enabled'] = $ont->admin_enabled;

        // Guardar la última lectura como estado actual de la ONT
        if (isset($result['metrics']['rx_power']['value'])) {
            $ont->update([
                'rx_power' => $result['metrics']['rx_power']['value'],
                'status' => 1,
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => $data,
            'query_ms' => $result['query_ms'],
            'cached' => $result['cached'] ?? false,
            'source' => 'snmp',
        ]);
    }

    /**
     * Endpoint AJAX: historial de métricas para las gráficas
     * (potencia óptica y ancho de banda).
     *
     * Las muestras las genera el comando onts:poll.
     */
    public function metricsHistory(Request $request, Ont $ont): \Illuminate\Http\JsonResponse
    {
        $hours = (int) $request->get('hours', 24);
        $hours = max(1, min($hours, 720)); // entre 1 hora y 30 días

        $samples = $ont->metrics()
            ->where('measured_at', '>=', now()->subHours($hours))
            ->orderBy('measured_at')
            ->get(['measured_at', 'rx_power', 'tx_power', 'olt_rx_power', 'in_bps', 'out_bps']);

        return response()->json([
            'ok' => true,
            'hours' => $hours,
            'count' => $samples->count(),
            'has_traffic' => $samples->contains(fn ($s) => $s->in_bps !== null),
            'samples' => $samples->map(fn ($s) => [
                't' => $s->measured_at->format('Y-m-d H:i'),
                'rx' => $s->rx_power !== null ? (float) $s->rx_power : null,
                'tx' => $s->tx_power !== null ? (float) $s->tx_power : null,
                'olt_rx' => $s->olt_rx_power !== null ? (float) $s->olt_rx_power : null,
                'in_bps' => $s->in_bps,
                'out_bps' => $s->out_bps,
            ]),
        ]);
    }
    public function enableCatv(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        return $this->changeCatv($ont, true);
    }

    public function disableCatv(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        return $this->changeCatv($ont, false);
    }

    /**
     * Cambia el estado del puerto CATV y guarda el resultado.
     *
     * Al aplicarlo desde aquí el sistema sabe con certeza en qué
     * estado quedó, así que lo registra: la vista puede mostrarlo
     * al instante sin volver a consultar la OLT (que tarda ~40 s).
     */
    private function changeCatv(Ont $ont, bool $enable): \Illuminate\Http\RedirectResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);
        $accion = $enable ? 'habilitar' : 'deshabilitar';

        try {
            $this->oltSshService->setCatvPort($olt, $ont, $enable);
        } catch (\Exception $e) {
            return back()->with('error', "Error al {$accion} CATV: " . $e->getMessage());
        }

        $ont->update([
            'catv_enabled' => $enable,
            'catv_checked_at' => now(),
        ]);

        return back()->with(
            $enable ? 'success' : 'success-update',
            $enable ? 'Televisión (CATV) habilitada correctamente.' : 'Televisión (CATV) deshabilitada.'
        );
    }

    /**
     * Consulta a la OLT el estado real del puerto CATV.
     *
     * Va por CLI y tarda unos 40 segundos, por eso es una acción
     * bajo demanda y no parte de la carga de la pantalla.
     */
    public function checkCatvState(Ont $ont): \Illuminate\Http\JsonResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        try {
            $state = $this->oltSshService->getCatvPortState($olt, $ont);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo consultar la OLT: ' . $e->getMessage(),
            ]);
        }

        if ($state === null) {
            return response()->json([
                'ok' => false,
                'message' => 'La OLT no reportó el estado del puerto CATV.',
            ]);
        }

        $enabled = $state === 'on';

        $ont->update([
            'catv_enabled' => $enabled,
            'catv_checked_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'catv_enabled' => $enabled,
            'checked_at' => now()->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Habilita la ONT (restablece el servicio del cliente).
     */
    public function enableOnt(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        return $this->changeAdminState($ont, true);
    }

    /**
     * Deshabilita la ONT (corta el servicio sin borrar su
     * configuración: se puede rehabilitar cuando se quiera).
     */
    public function disableOnt(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        return $this->changeAdminState($ont, false);
    }

    private function changeAdminState(Ont $ont, bool $enable): \Illuminate\Http\RedirectResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);
        $accion = $enable ? 'habilitar' : 'deshabilitar';

        try {
            $this->oltSshService->setOntAdminState($olt, $ont, $enable);
        } catch (\Exception $e) {
            return back()->with('error', "Error al {$accion} la ONT: " . $e->getMessage());
        }

        $ont->update(['admin_enabled' => $enable]);

        return back()->with(
            $enable ? 'success' : 'success-update',
            $enable
                ? 'ONT habilitada: el servicio del cliente queda restablecido.'
                : 'ONT deshabilitada: el servicio del cliente queda suspendido.'
        );
    }
}
