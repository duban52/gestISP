<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Services\OltSnmpService;
use App\Services\OltStatistics;
use App\Services\OltSshService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
        // Reiniciar es menos invasivo que deshabilitar (no cambia la
        // configuración), pero deja al cliente sin servicio un minuto:
        // se exige el mismo permiso, que además ya existe en la base
        // de datos y no obliga a sincronizar permisos al desplegar.
        $this->middleware('check.permission:onts.activate')->only('reboot');
    }

    public function no_authorized_ont_index()
    {
        $contracts = Contract::where('branch_id', session('branch_id'))->get();
        $olts      = Olt::where('branch_id', session('branch_id'))->get();
        return view('gestisp.onts.no-authorized.index', compact('olts', 'contracts'));
    }

    /**
     * Listado de ONTs autorizadas de la sucursal.
     *
     * Admite filtrar por OLT (?olt=ID): es lo que usa el enlace "ONUs"
     * del listado de OLTs para ver de una las ONTs conectadas a ese
     * equipo. Sin el parámetro se listan todas, como siempre.
     */
    public function authorized_ont_index(Request $request)
    {
        $oltFiltrada = null;

        $query = Ont::where('branch_id', session('branch_id'))
            ->with(['olt', 'contract']);

        if ($request->filled('olt')) {
            // Se busca dentro de la sucursal activa: así el filtro no
            // sirve para asomarse a las OLTs de otra sucursal.
            $oltFiltrada = Olt::where('branch_id', session('branch_id'))
                ->find($request->query('olt'));

            if ($oltFiltrada) {
                $query->where('olt_id', $oltFiltrada->id);
            } else {
                // Id inexistente o de otra sucursal: no se muestra nada
                $query->whereRaw('1 = 0');
            }
        }

        // Filtros del lado del SERVIDOR, no de la tabla. El buscador de
        // DataTables solo ve lo que ya está en la página, y una sucursal
        // puede tener miles de ONTs: filtrar aquí es lo que hace que la
        // pantalla siga abriendo rápido cuando la red crece.
        if (($estado = $request->query('estado')) !== null && $estado !== '') {
            // "admin_enabled" es null mientras nadie haya leido el
            // estado administrativo en la OLT: eso NO es deshabilitada,
            // es "no se sabe", y cuenta como operativa.
            match ($estado) {
                'en_linea' => $query->where('status', 1)
                    ->where(fn ($q) => $q->whereNull('admin_enabled')->orWhere('admin_enabled', true)),
                'caida' => $query->where('status', '!=', 1)
                    ->where(fn ($q) => $q->whereNull('admin_enabled')->orWhere('admin_enabled', true)),
                'deshabilitada' => $query->where('admin_enabled', false),
                default => null,
            };
        }

        if (($contrato = $request->query('contrato')) === 'si') {
            $query->whereNotNull('contract_id');
        } elseif ($contrato === 'no') {
            $query->whereNull('contract_id');
        }

        $onts = $query->get();

        // La banda de señal no se puede filtrar en SQL: rx_power es una
        // columna de TEXTO y los rangos son negativos. Se filtra ya en
        // memoria, sobre lo que quedó de los filtros de arriba.
        $banda = $request->query('banda');

        if ($banda) {
            $onts = $onts->filter(
                fn (Ont $o) => $o->rx_power !== null && $o->rx_power !== ''
                    && OltStatistics::bandaDe((float) $o->rx_power) === $banda
            )->values();
        }

        return view('gestisp.onts.authorized.index', [
            'onts' => $onts,
            'oltFiltrada' => $oltFiltrada,
            'olts' => Olt::where('branch_id', session('branch_id'))->orderBy('name')->get(),
            'filtros' => $request->only(['olt', 'estado', 'contrato', 'banda']),
            'resumen' => $this->resumenDeOnts($onts),
        ]);
    }

    /**
     * Cifras de cabecera del listado de ONTs.
     *
     * Se calculan sobre lo FILTRADO, no sobre toda la sucursal: si
     * alguien mira una OLT concreta, los números tienen que ser los de
     * esa OLT o no significan nada.
     *
     * @param  \Illuminate\Support\Collection<int, Ont>  $onts
     * @return array<string, mixed>
     */
    private function resumenDeOnts($onts): array
    {
        $deshabilitadas = $onts->filter(fn (Ont $o) => $o->admin_enabled === false);
        // Una ONT cortada a propósito no es una falla de red: se saca
        // del cálculo para que la disponibilidad no se hunda sola
        // cuando hay muchos cortes por facturación.
        $enServicio = $onts->reject(fn (Ont $o) => $o->admin_enabled === false);
        $enLinea = $enServicio->filter(fn (Ont $o) => (int) $o->status === 1);

        $conPotencia = $onts->filter(
            fn (Ont $o) => $o->rx_power !== null && $o->rx_power !== '' && (int) $o->status === 1
        );

        $bandas = [];

        foreach (OltStatistics::bandas() as $clave => $definicion) {
            $bandas[$clave] = $definicion + [
                'cantidad' => $conPotencia
                    ->filter(fn (Ont $o) => OltStatistics::bandaDe((float) $o->rx_power) === $clave)
                    ->count(),
            ];
        }

        return [
            'total' => $onts->count(),
            'en_linea' => $enLinea->count(),
            'caidas' => $enServicio->count() - $enLinea->count(),
            'deshabilitadas' => $deshabilitadas->count(),
            'sin_contrato' => $onts->whereNull('contract_id')->count(),
            'disponibilidad' => $enServicio->isEmpty()
                ? 100.0
                : round($enLinea->count() / $enServicio->count() * 100, 1),
            'bandas' => $bandas,
            // Las que piden una visita: señal débil, crítica o saturada.
            'con_problema' => $bandas['debil']['cantidad']
                + $bandas['critica']['cantidad']
                + $bandas['saturacion']['cantidad'],
            'potencia_media' => $conPotencia->isNotEmpty()
                ? round($conPotencia->avg(fn (Ont $o) => (float) $o->rx_power), 2)
                : null,
        ];
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

        return back()->with('success', "ONT vinculada al contrato {$contrato->numero_visible}.");
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

    /**
     * Autoriza una ONT en la OLT y la registra.
     *
     * ONTs CON Y SIN CONTRATO
     * -----------------------
     * Lo normal es que la ONT pertenezca a un contrato: se busca el
     * cliente y de ahí sale la descripción con la que queda rotulada
     * en la OLT. Pero no siempre hay contrato detrás — equipos de
     * prueba en el laboratorio, repetidores de la misma empresa,
     * enlaces a una sede propia, ONTs de demostración. Antes había
     * que inventarle un contrato o dejar el equipo sin autorizar.
     *
     * Marcando "no pertenece a un contrato" se escribe la descripción
     * a mano y la ONT queda registrada sin contract_id. Es la
     * EXCEPCIÓN, no la norma: por eso la casilla llega desmarcada y
     * el caso queda anotado en la trazabilidad, para que un equipo
     * suelto en la red siempre tenga a alguien que responda por él.
     */
    public function activate(Request $request): \Illuminate\Http\RedirectResponse
    {
        // La casilla decide si el contrato es obligatorio. Se lee del
        // request y no de la presencia de contract_id, para que un
        // campo oculto que quedó con valor no cuele una activación
        // vinculada cuando el usuario pidió lo contrario.
        $sinContrato = $request->boolean('sin_contrato');

        $validated = $request->validate([
            'olt_id'          => 'required|exists:olts,id',
            'ont_sn'          => 'required|string',
            'ont_location'    => 'required|string',
            'sin_contrato'    => 'nullable|boolean',
            'contract_id'     => [
                Rule::requiredIf(fn () => !$sinContrato),
                'nullable',
                'exists:contracts,id',
            ],
            // Siempre obligatoria: es el rótulo con el que la ONT
            // queda escrita en la OLT y lo único que la identifica
            // cuando no hay contrato.
            'description'     => 'required|string|max:150',
            'vlan'            => 'required|integer',
            'ont_lineprofile' => 'required|integer',
            'ont_srvprofile'  => 'required|integer',
            // Dónde queda conectada la ONT. OPCIONAL a propósito: hay
            // instalaciones que no pasan por una caja documentada, y
            // exigirlo bloquearía la activación por un dato de
            // inventario. Se comprueba que exista; que sea del puerto
            // PON correcto y esté libre lo valida el servicio.
            'nap_port_id'     => 'nullable|exists:nap_ports,id',
        ], [
            'contract_id.required' => 'Seleccione el contrato o marque que la ONT no pertenece a ninguno.',
            'description.required' => 'La descripción es obligatoria: es el rótulo de la ONT en la OLT.',
        ]);

        // Sin contrato: se ignora cualquier id que viniera en el
        // formulario.
        $contractId = $sinContrato ? null : $validated['contract_id'];

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
            'contract_id'  => $contractId,
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

        // El serial solo se copia al contrato cuando hay contrato
        if ($contractId) {
            Contract::where('id', $contractId)->update(['cpe_sn' => $validated['ont_sn']]);
        }

        $this->auditarActivacion($ont, $olt, $contractId, $sinContrato);

        $avisoNap = $this->ocuparPuertoNap($validated['nap_port_id'] ?? null, $contractId, $ont);

        $mensaje = $sinContrato
            ? 'ONT activada y registrada SIN contrato asociado.'
            : 'ONT activada y registrada correctamente.';

        // La ONT YA quedó activa en la OLT: si algo falla al anotar la
        // caja, no se puede devolver un error a secas o parecerá que la
        // activación no se hizo. Se confirma el éxito y se avisa aparte.
        return back()->with('success', $mensaje . $avisoNap);
    }

    /**
     * Anota en qué puerto de qué caja NAP quedó conectada la ONT.
     *
     * El puerto lo ocupa el CONTRATO, no el equipo: así lo modela el
     * inventario de red, porque lo que importa saber cuando se
     * interviene una caja es a qué clientes se deja sin servicio. Por
     * eso sin contrato no hay nada que anotar.
     *
     * Devuelve el texto que se añade al mensaje de éxito. Nunca lanza:
     * la ONT ya quedó activa en la OLT, y un fallo aquí es un problema
     * de inventario, no de servicio. Convertirlo en error haría creer
     * que la activación no se hizo, y alguien la repetiría.
     */
    private function ocuparPuertoNap(?string $napPortId, ?int $contractId, Ont $ont): string
    {
        if (!$napPortId) {
            return '';
        }

        if (!$contractId) {
            return ' La caja NAP no se anotó: el puerto de una caja se asigna a un contrato,'
                . ' y esta ONT quedó sin contrato.';
        }

        $puerto = \App\Models\NapPort::with('napBox.network', 'napBox.ponPort')->find($napPortId);

        // El id llega del navegador: la caja tiene que ser de la
        // sucursal activa y colgar del MISMO puerto PON donde acaba de
        // quedar la ONT. Si no, se estaría registrando una instalación
        // físicamente imposible.
        $mismaSucursal = (int) $puerto?->napBox?->network?->branch_id === (int) session('branch_id');
        $mismoPon = $puerto?->napBox?->ponPort
            && (int) $puerto->napBox->ponPort->olt_id === (int) $ont->olt_id
            && (int) $puerto->napBox->ponPort->slot === (int) $ont->slot
            && (int) $puerto->napBox->ponPort->port === (int) $ont->port;

        if (!$puerto || !$mismaSucursal || !$mismoPon) {
            return ' La caja NAP no se anotó: el puerto elegido no pertenece al puerto PON de esta ONT.';
        }

        try {
            app(\App\Services\OdnManager::class)->asignarPuerto(
                Contract::findOrFail($contractId),
                $puerto,
            );
        } catch (\RuntimeException $e) {
            return ' La caja NAP no se anotó: ' . $e->getMessage();
        }

        return sprintf(' Queda en la caja %s, puerto %d.', $puerto->napBox->code, $puerto->number);
    }

    /**
     * Deja constancia de la autorización.
     *
     * Se registra siempre, pero interesa especialmente el caso SIN
     * contrato: un equipo suelto en la red, que no le factura a
     * nadie, tiene que poder rastrearse hasta quién lo autorizó y con
     * qué justificación escribió en la descripción.
     */
    private function auditarActivacion(Ont $ont, Olt $olt, ?int $contractId, bool $sinContrato): void
    {
        $contrato = $contractId ? Contract::find($contractId) : null;

        app(\App\Services\Audit\AuditLogger::class)->action(
            'onts.activated',
            sprintf(
                'Autorizó la ONT %s en %s (%s)',
                $ont->sn,
                $olt->name,
                $sinContrato
                    ? 'sin contrato: ' . $ont->description
                    : 'contrato ' . ($contrato?->numero_visible ?? $contractId),
            ),
            [
                'sn' => $ont->sn,
                'olt' => $olt->name,
                'ubicacion' => "{$ont->slot}/{$ont->port}",
                'onu_id' => $ont->onu_id,
                'vlan' => $ont->vlan,
                'descripcion' => $ont->description,
                'sin_contrato' => $sinContrato,
                'contrato' => $contrato?->numero_visible,
            ],
            $ont,
            'red',
        );
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
        // ---- Tiempo en línea ----
        // La OLT no lo expone por SNMP (su consola sí lo calcula), así
        // que se deduce de la última conexión mientras la ONT siga en
        // línea. Comprobado contra la consola: para una ONT levantada
        // el 23/07 a las 19:40 informaba "1 day(s), 22 hour(s)...",
        // que es exactamente el tiempo transcurrido desde esa fecha.
        $data['online_duration'] = $this->calcularTiempoEnLinea(
            $data['last_up_time'] ?? null,
            $data['run_status'] ?? null,
        );

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
     * Tiempo que la ONT lleva conectada sin interrupción.
     *
     * Solo tiene sentido si la ONT está en línea: si está caída, lo
     * que importa es cuándo se cayó, no cuánto duró conectada.
     *
     * @param  string|null  $ultimaConexion  Fecha "d/m/Y H:i"
     * @param  string|null  $estado          'online' / 'offline'
     */
    private function calcularTiempoEnLinea(?string $ultimaConexion, ?string $estado): ?string
    {
        if (!$ultimaConexion || $estado !== 'online') {
            return null;
        }

        try {
            $desde = \Carbon\Carbon::createFromFormat('d/m/Y H:i', $ultimaConexion);
        } catch (\Exception $e) {
            return null;
        }

        if (!$desde || $desde->isFuture()) {
            return null;
        }

        $minutos = $desde->diffInMinutes(now());

        $dias = intdiv($minutos, 1440);
        $horas = intdiv($minutos % 1440, 60);
        $resto = $minutos % 60;

        $partes = [];

        if ($dias > 0) {
            $partes[] = $dias . ' ' . ($dias === 1 ? 'día' : 'días');
        }

        if ($horas > 0) {
            $partes[] = $horas . ' h';
        }

        // Los minutos solo se detallan cuando la conexión es reciente
        if ($dias === 0 && ($resto > 0 || empty($partes))) {
            $partes[] = $resto . ' min';
        }

        return implode(' ', $partes);
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

    /**
     * Reinicia la ONT del cliente.
     *
     * Útil como primer paso de soporte cuando el cliente reporta
     * lentitud o se quedó sin navegación: reinicia el equipo sin
     * tocar su configuración ni obligar a reautorizarlo.
     */
    public function reboot(Ont $ont): \Illuminate\Http\RedirectResponse
    {
        $olt = Olt::findOrFail($ont->olt_id);

        try {
            $this->oltSshService->rebootOnt($olt, $ont);
        } catch (\Exception $e) {
            return back()->with('error', 'No se pudo reiniciar la ONT: ' . $e->getMessage());
        }

        $this->auditarAccionSobreOnt(
            $ont, $olt, 'onts.rebooted',
            'Reinició la ONT %s (%s)',
        );

        return back()->with(
            'success',
            'Se envió el reinicio a la ONT. El equipo tarda cerca de un minuto en volver a conectarse.'
        );
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

        // El oyente global de modelos ya registra el cambio de
        // admin_enabled, pero como un genérico "Modificó la ONT" que
        // no dice lo importante: que se le cortó (o devolvió) el
        // servicio a un cliente concreto.
        $this->auditarAccionSobreOnt(
            $ont, $olt,
            $enable ? 'onts.enabled' : 'onts.disabled',
            ($enable ? 'Habilitó' : 'Deshabilitó') . ' la ONT %s (%s)',
        );

        return back()->with(
            $enable ? 'success' : 'success-update',
            $enable
                ? 'ONT habilitada: el servicio del cliente queda restablecido.'
                : 'ONT deshabilitada: el servicio del cliente queda suspendido.'
        );
    }

    /**
     * Deja constancia de una acción ejecutada sobre el equipo.
     *
     * Estas tres operaciones dejan a un cliente sin servicio (o se lo
     * devuelven), así que la bitácora tiene que poder responder quién
     * lo hizo y sobre qué contrato — no basta el "Modificó la ONT"
     * que genera el oyente automático de modelos.
     */
    private function auditarAccionSobreOnt(Ont $ont, Olt $olt, string $accion, string $plantilla): void
    {
        $contrato = $ont->contract;

        app(\App\Services\Audit\AuditLogger::class)->action(
            $accion,
            sprintf(
                $plantilla,
                $ont->sn,
                $contrato?->numero_visible
                    ? 'contrato ' . $contrato->numero_visible
                    : 'sin contrato: ' . ($ont->description ?: 'sin descripción'),
            ),
            [
                'sn' => $ont->sn,
                'olt' => $olt->name,
                'ubicacion' => "{$ont->slot}/{$ont->port}/{$ont->onu_id}",
                'contrato' => $contrato?->numero_visible,
                'cliente' => $contrato?->client
                    ? trim($contrato->client->name . ' ' . $contrato->client->last_name)
                    : null,
            ],
            $ont,
            'red',
        );
    }
}
