<?php

namespace App\Http\Controllers;

use App\Models\LineProfile;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\SrvProfile;
use App\Models\VlanOlt;
use App\Services\OltSshService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\Model;

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
        $this->middleware('check.permission:olts.edit')
            ->only('edit', 'update', 'viewVlans', 'viewLineProfiles', 'viewSrvProfiles');
        $this->middleware('check.permission:olts.vlans')->only(
            'storeVlan', 'storeLineProfile', 'storeSrvProfile',
            'updateVlan', 'updateLineProfile', 'updateSrvProfile',
            'destroyVlan', 'destroyLineProfile', 'destroySrvProfile',
        );
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

        // La contraseña se guarda tal cual: bcrypt es irreversible
        // y el acceso por SSH necesita recuperarla para autenticar
        // contra el equipo (ver Olt::getPlainPassword).

        Olt::create($validated);

        return redirect()
            ->route('olts.index')
            ->with('success', 'OLT creada correctamente.');
    }

    /**
     * Actualiza los datos de conexión de una OLT.
     *
     * La contraseña es opcional: si se deja en blanco se conserva
     * la que ya estaba, para no obligar a reescribirla cada vez
     * que se corrige cualquier otro campo.
     */
    public function update(Request $request, Olt $olt): RedirectResponse
    {
        abort_if((int) $olt->branch_id !== (int) session('branch_id'), 403);

        $validated = $this->validateOltData($request, actualizando: true);

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $olt->update($validated);

        return redirect()
            ->route('olts.edit', $olt)
            ->with('success', 'OLT actualizada correctamente.');
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
     * Valida los datos del formulario de OLT.
     *
     * Al actualizar, la contraseña deja de ser obligatoria: en
     * blanco significa "conservar la actual".
     */
    private function validateOltData(Request $request, bool $actualizando = false): array
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
            'password' => ($actualizando ? 'nullable' : 'required') . '|string|max:255',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
        ]);
    }
    //Muestra las vlans en la vista de editar las olt
    public function viewVlans(Olt $olt): JsonResponse
    {
        // Cuántas ONTs de esta OLT usan cada VLAN, para avisar
        // antes de eliminarla. Se cuenta de una vez y no una
        // consulta por fila.
        $enUso = Ont::where('olt_id', $olt->id)
            ->selectRaw('vlan, COUNT(*) as total')
            ->groupBy('vlan')
            ->pluck('total', 'vlan');

        return $this->recursosDeOlt($olt, 'vlan', fn ($vlan) => [
            'en_uso' => (int) ($enUso[$vlan->id_vlan] ?? 0),
        ]);
    }

    //Muestra los lineprofiles en la vista de editar las olt
    public function viewLineProfiles(Olt $olt): JsonResponse
    {
        return $this->recursosDeOlt($olt, 'lineProfile');
    }

    //Muestra los svrprofiles en la vista de editar las olt
    public function viewSrvProfiles(Olt $olt): JsonResponse
    {
        return $this->recursosDeOlt($olt, 'srvProfile');
    }

    /**
     * Devuelve las VLANs o perfiles de una OLT para la pantalla
     * de edición, con las rutas de cada fila ya resueltas.
     *
     * Las URLs se arman aquí y no en JavaScript: el proyecto
     * genera siempre las rutas con route() para respetar el
     * prefijo de la aplicación.
     *
     * @param  callable|null  $extra  Datos propios del recurso
     */
    private function recursosDeOlt(Olt $olt, string $tipo, ?callable $extra = null): JsonResponse
    {
        $recurso = self::RECURSOS[$tipo];
        $columna = $recurso['columna'];

        $registros = $recurso['modelo']::where('olt_id', $olt->id)
            ->orderBy($columna)
            ->get();

        $ruta = [
            'vlan' => 'olt.vlans',
            'lineProfile' => 'olt.lineprofiles',
            'srvProfile' => 'olt.srvprofiles',
        ][$tipo];

        $data = $registros->map(function ($registro) use ($columna, $ruta, $extra) {
            $fila = [
                'id' => $registro->id,
                $columna => $registro->{$columna},
                'name' => $registro->name,
                'description' => $registro->description,
                'update_url' => route("{$ruta}.update", $registro),
                'destroy_url' => route("{$ruta}.destroy", $registro),
            ];

            return $extra ? array_merge($fila, $extra($registro)) : $fila;
        });

        return response()->json($data);
    }

    /**
     * VLANs y perfiles comparten forma: identificador, nombre y
     * descripción, únicos dentro de su OLT. Este mapa reúne lo
     * único que cambia entre ellos para no repetir tres veces el
     * mismo crear/editar/eliminar.
     *
     * - bag: contenedor de errores propio de cada formulario
     * - etiqueta: cómo se nombra el recurso en los mensajes
     */
    private const RECURSOS = [
        'vlan' => [
            'modelo' => VlanOlt::class,
            'tabla' => 'vlan_olts',
            'columna' => 'id_vlan',
            'reglas' => [
                'id_vlan' => 'required|integer|min:1|max:4094',
                'name' => 'required|string|max:100',
            ],
            'bag' => 'vlan',
            'etiqueta' => 'La VLAN',
        ],
        'lineProfile' => [
            'modelo' => LineProfile::class,
            'tabla' => 'line_profiles',
            'columna' => 'id_line_profile',
            'reglas' => [
                'id_line_profile' => 'required|string|max:50',
                'name' => 'required|string|max:50',
            ],
            'bag' => 'lineProfile',
            'etiqueta' => 'El perfil de línea',
        ],
        'srvProfile' => [
            'modelo' => SrvProfile::class,
            'tabla' => 'srv_profiles',
            'columna' => 'id_srv_profile',
            'reglas' => [
                'id_srv_profile' => 'required|string|max:50',
                'name' => 'required|string|max:255',
            ],
            'bag' => 'srvProfile',
            'etiqueta' => 'El perfil de servicio',
        ],
    ];

    /**
     * Registra una VLAN de la OLT.
     *
     * Solo queda en la base de datos: en el equipo la VLAN se
     * crea a mano. Aquí se guarda para poder ofrecerla al
     * autorizar una ONT.
     */
    public function storeVlan(Request $request): RedirectResponse
    {
        return $this->guardarRecurso($request, 'vlan');
    }

    /**
     * Registra un perfil de línea (line-profile) de la OLT.
     *
     * Igual que las VLANs, refleja lo que ya existe en el equipo
     * para poder elegirlo al autorizar una ONT.
     */
    public function storeLineProfile(Request $request): RedirectResponse
    {
        return $this->guardarRecurso($request, 'lineProfile');
    }

    /**
     * Registra un perfil de servicio (srv-profile) de la OLT.
     */
    public function storeSrvProfile(Request $request): RedirectResponse
    {
        return $this->guardarRecurso($request, 'srvProfile');
    }

    public function updateVlan(Request $request, VlanOlt $vlan): RedirectResponse
    {
        return $this->guardarRecurso($request, 'vlan', $vlan);
    }

    public function updateLineProfile(Request $request, LineProfile $lineProfile): RedirectResponse
    {
        return $this->guardarRecurso($request, 'lineProfile', $lineProfile);
    }

    public function updateSrvProfile(Request $request, SrvProfile $srvProfile): RedirectResponse
    {
        return $this->guardarRecurso($request, 'srvProfile', $srvProfile);
    }

    public function destroyVlan(VlanOlt $vlan): RedirectResponse
    {
        return $this->eliminarRecurso($vlan, 'vlan');
    }

    public function destroyLineProfile(LineProfile $lineProfile): RedirectResponse
    {
        return $this->eliminarRecurso($lineProfile, 'lineProfile');
    }

    public function destroySrvProfile(SrvProfile $srvProfile): RedirectResponse
    {
        return $this->eliminarRecurso($srvProfile, 'srvProfile');
    }

    /**
     * Crea o actualiza una VLAN o un perfil.
     *
     * Con $existente llega desde el formulario de edición; sin él,
     * desde el de creación.
     */
    private function guardarRecurso(Request $request, string $tipo, ?Model $existente = null): RedirectResponse
    {
        $recurso = self::RECURSOS[$tipo];

        // Al editar, la OLT no se cambia: se conserva la del
        // registro para que no pueda moverse a otro equipo
        if ($existente) {
            $this->verificarSucursal($existente->olt_id);
            $request->merge(['olt_id' => $existente->olt_id]);
        }

        $validated = $this->validateOltResource($request, $recurso, $existente);

        if ($existente) {
            $existente->update($validated);
            $mensaje = "{$recurso['etiqueta']} se actualizó correctamente.";
        } else {
            $recurso['modelo']::create($validated);
            $mensaje = "{$recurso['etiqueta']} se creó correctamente.";
        }

        return redirect()->back()->with('success', $mensaje);
    }

    /**
     * Elimina una VLAN o un perfil del catálogo.
     *
     * Solo se borra el registro de GestISP: en la OLT sigue
     * existiendo, porque este listado únicamente refleja lo que
     * hay configurado en el equipo.
     */
    private function eliminarRecurso(Model $registro, string $tipo): RedirectResponse
    {
        $this->verificarSucursal($registro->olt_id);

        $recurso = self::RECURSOS[$tipo];

        $registro->delete();

        return redirect()->back()->with(
            'success',
            "{$recurso['etiqueta']} se eliminó del listado. En la OLT sigue configurada."
        );
    }

    /**
     * La OLT debe pertenecer a la sucursal activa.
     *
     * La sucursal se guarda en sesión como TEXTO, así que se
     * convierte antes de comparar ('1' !== 1 rechazaría todo).
     */
    private function verificarSucursal(int $oltId): void
    {
        $olt = Olt::findOrFail($oltId);

        abort_if((int) $olt->branch_id !== (int) session('branch_id'), 403);
    }

    /**
     * Valida una VLAN o un perfil antes de guardarlo.
     *
     * Comparten tres reglas:
     *
     * - El identificador es único DENTRO de la OLT, no en toda la
     *   tabla. Antes se validaba de forma global y eso impedía
     *   registrar la VLAN 100 en una segunda OLT, aunque es un
     *   valor habitual y perfectamente válido en cada equipo.
     * - La OLT debe pertenecer a la sucursal activa: el olt_id
     *   llega en un campo oculto del formulario y sin esta
     *   comprobación se podría escribir en una OLT ajena.
     * - La sucursal de la sesión se guarda como TEXTO, así que se
     *   convierte antes de comparar ('1' !== 1 rechazaría todo).
     *
     * Los errores se devuelven en un "bag" propio de cada
     * formulario: los tres comparten los campos "name" y
     * "description", así que sin separarlos un error en la VLAN
     * aparecería también bajo los modales de los perfiles.
     *
     * @param  array<string, mixed>  $recurso  Entrada de self::RECURSOS
     * @param  Model|null  $existente  Registro que se está editando
     * @return array<string, mixed>
     */
    private function validateOltResource(Request $request, array $recurso, ?Model $existente = null): array
    {
        $idColumn = $recurso['columna'];
        $rules = $recurso['reglas'];

        $unica = Rule::unique($recurso['tabla'], $idColumn)->where(
            fn ($query) => $query->where('olt_id', $request->input('olt_id'))
        );

        // Al editar, el propio registro no cuenta como duplicado
        if ($existente) {
            $unica->ignore($existente->getKey());
        }

        // El identificador lleva sus reglas propias MÁS la de
        // unicidad: se concatenan en un array para que ninguna
        // se pierda al combinarlas.
        $rules[$idColumn] = array_merge(explode('|', $rules[$idColumn]), [$unica]);

        $rules['olt_id'] = 'required|exists:olts,id';
        $rules['description'] = 'nullable|string|max:255';

        $validated = $request->validateWithBag($recurso['bag'], $rules, [
            $idColumn . '.unique' => 'Ese identificador ya está registrado en esta OLT.',
        ]);

        $this->verificarSucursal((int) $validated['olt_id']);

        return $validated;
    }
}
