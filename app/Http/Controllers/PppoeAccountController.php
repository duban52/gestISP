<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Services\MikrotikApiService;
use App\Services\PppoeCredentialGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Controlador de cuentas PPPoE
 *
 * Gestiona los secrets PPPoE de la sucursal activa: creación y
 * edición sincronizada con el router Mikrotik, activación/
 * desactivación, importación masiva desde el router y monitoreo
 * de sesiones en tiempo real.
 */
class PppoeAccountController extends Controller
{
    /**
     * Constructor: inyecta el servicio Mikrotik y protege las
     * rutas con autenticación y permisos.
     */
    public function __construct(
        protected MikrotikApiService $mikrotik,
        protected \App\Services\ContractLinker $contractLinker,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:pppoe.edit')->only('linkContract', 'unlinkContract');
        $this->middleware('check.permission:pppoe.index')->only('index', 'apiActiveSessions');
        $this->middleware('check.permission:pppoe.show')->only('show', 'realtimeSession', 'metricsHistory');
        $this->middleware('check.permission:pppoe.create')->only('store', 'suggestCredentials');
        $this->middleware('check.permission:pppoe.edit')->only('update', 'toggleState');
        $this->middleware('check.permission:pppoe.destroy')->only('destroy');
        $this->middleware('check.permission:pppoe.import')->only('importFromRouter');
        $this->middleware('check.permission:pppoe.restart')->only('restartSession');
    }

    /**
     * Propone usuario, contraseña y comentario para una cuenta nueva.
     *
     * Sirve a los dos caminos del formulario: si llega contract_id se
     * toman los datos del contrato; si no, los que el operador
     * escribió a mano (el titular existe, pero en otro sistema).
     *
     * Va por POST y no por GET porque lleva nombre e identificación
     * de una persona, y eso no debe quedar escrito en la URL ni en
     * los registros del servidor.
     *
     * El router importa: la unicidad del usuario es POR ROUTER, así
     * que el diferenciador solo se puede calcular sabiendo en cuál se
     * va a crear la cuenta.
     */
    public function suggestCredentials(Request $request, PppoeCredentialGenerator $generador): JsonResponse
    {
        $validated = $request->validate([
            'router_id' => 'nullable|exists:routers,id',
            'contract_id' => 'nullable|exists:contracts,id',
            'nombres' => 'nullable|string|max:120',
            'apellidos' => 'nullable|string|max:120',
            'identificacion' => 'nullable|string|max:40',
            'referencia' => 'nullable|string|max:60',
        ]);

        $routerId = isset($validated['router_id']) ? (int) $validated['router_id'] : null;

        if (!empty($validated['contract_id'])) {
            $contrato = Contract::with('client')->findOrFail($validated['contract_id']);

            return response()->json($generador->paraContrato($contrato, $routerId));
        }

        return response()->json($generador->generar([
            'nombres' => $validated['nombres'] ?? null,
            'apellidos' => $validated['apellidos'] ?? null,
            'identificacion' => $validated['identificacion'] ?? null,
            'referencia' => $validated['referencia'] ?? null,
            // El titular está en otro sistema: la referencia es el
            // contrato o número de cliente de ESE sistema.
            'etiqueta_referencia' => 'Ref.',
        ], $routerId));
    }

    public function index(): View
    {
        $routers  = Router::byBranch(session('branch_id'))->active()->get();
        $accounts = PppoeAccount::where('branch_id', session('branch_id'))
            ->with(['router', 'contract.client'])
            ->get();

        return view('gestisp.pppoe.index', compact('routers', 'accounts'));
    }

    /**
     * Crea una cuenta PPPoE en el router y la registra.
     *
     * CUENTAS CON Y SIN CONTRATO
     * --------------------------
     * Lo normal es que la cuenta pertenezca a un contrato: se busca
     * el cliente y de ahí salen el usuario, la clave y el comentario.
     * Pero no toda cuenta le factura a alguien — enlaces entre sedes
     * propias, cámaras, antenas de la misma empresa, pruebas de
     * laboratorio. Antes esos casos obligaban a inventar un contrato
     * o a crear la cuenta a mano en el Mikrotik, por fuera del
     * sistema (y por tanto fuera de todo control).
     *
     * Marcando "no pertenece a un contrato" la cuenta se crea sin
     * contract_id y el COMENTARIO pasa a ser obligatorio: si nadie
     * responde por la cuenta desde un contrato, al menos tiene que
     * quedar escrito para qué es.
     */
    public function store(Request $request): RedirectResponse
    {
        // La casilla manda: un contract_id que quedara en el
        // formulario no debe vincular una cuenta que se pidió suelta.
        $sinContrato = $request->boolean('sin_contrato');

        $validated = $request->validate([
            'router_id'      => 'required|exists:routers,id',
            'sin_contrato'   => 'nullable|boolean',
            'contract_id'    => [
                Rule::requiredIf(fn () => !$sinContrato),
                'nullable',
                'exists:contracts,id',
            ],
            'username'       => 'required|string|max:255',
            'password'       => 'required|string|max:255',
            'profile'        => 'required|string|max:255',
            'remote_address' => 'nullable|ip',
            // Sin contrato el comentario es lo único que dice para
            // qué existe esta cuenta: se vuelve obligatorio.
            'comment'        => [
                Rule::requiredIf(fn () => $sinContrato),
                'nullable',
                'string',
                'max:255',
            ],
        ], [
            'contract_id.required' => 'Seleccione el contrato o marque que la cuenta no pertenece a ninguno.',
            'comment.required' => 'Describa para qué es esta cuenta: es lo único que la identifica sin contrato.',
        ]);

        if ($sinContrato) {
            $validated['contract_id'] = null;
        }

        $router = Router::findOrFail($validated['router_id']);

        // Verificar duplicado local antes de tocar el router
        $exists = PppoeAccount::where('router_id', $router->id)
            ->where('username', $validated['username'])
            ->exists();

        if ($exists) {
            return back()->with('error', "El usuario {$validated['username']} ya existe en este router.")->withInput();
        }

        try {
            $mikrotikId = $this->mikrotik->createPppSecret($router, $validated);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al crear en Mikrotik: ' . $e->getMessage())->withInput();
        }

        $cuenta = PppoeAccount::create([
            'branch_id'      => session('branch_id'),
            'router_id'      => $validated['router_id'],
            'contract_id'    => $validated['contract_id'] ?? null,
            'mikrotik_id'    => $mikrotikId,
            'username'       => $validated['username'],
            'password'       => $validated['password'],
            'profile'        => $validated['profile'],
            'service'        => 'pppoe',
            'remote_address' => $validated['remote_address'] ?? null,
            'disabled'       => false,
            'comment'        => $validated['comment'] ?? null,
        ]);

        // Actualizar credenciales pppoe en el contrato si está asociado
        if (!empty($validated['contract_id'])) {
            Contract::where('id', $validated['contract_id'])->update([
                'user_pppoe'     => $validated['username'],
                'password_pppoe' => $validated['password'],
            ]);
        }

        $this->auditarCreacion($cuenta, $router, $sinContrato);

        return back()->with('success', $sinContrato
            ? 'Cuenta PPPoE creada SIN contrato asociado.'
            : 'Cuenta PPPoE creada correctamente.');
    }

    /**
     * Deja constancia de la creación de la cuenta.
     *
     * Interesa sobre todo el caso SIN contrato: una cuenta que
     * consume ancho de banda y no le factura a nadie tiene que poder
     * rastrearse hasta quién la creó y para qué dijo que era.
     */
    private function auditarCreacion(PppoeAccount $cuenta, Router $router, bool $sinContrato): void
    {
        $contrato = $cuenta->contract;

        app(\App\Services\Audit\AuditLogger::class)->action(
            'pppoe.created',
            sprintf(
                'Creó la cuenta PPPoE %s en %s (%s)',
                $cuenta->username,
                $router->name,
                $sinContrato
                    ? 'sin contrato: ' . ($cuenta->comment ?: 'sin descripción')
                    : 'contrato ' . ($contrato?->numero_visible ?? '—'),
            ),
            [
                'usuario_pppoe' => $cuenta->username,
                'router' => $router->name,
                'perfil' => $cuenta->profile,
                'sin_contrato' => $sinContrato,
                'contrato' => $contrato?->numero_visible,
                'comentario' => $cuenta->comment,
            ],
            $cuenta,
            'red',
        );
    }

    public function update(Request $request, PppoeAccount $pppoe): RedirectResponse
    {
        $validated = $request->validate([
            'username'       => 'required|string|max:255',
            'password'       => 'nullable|string|max:255',
            'profile'        => 'required|string|max:255',
            'remote_address' => 'nullable|ip',
            'comment'        => 'nullable|string|max:255',
        ]);

        $router = Router::findOrFail($pppoe->router_id);

        try {
            $this->mikrotik->updatePppSecret($router, $pppoe, $validated);

            // Tumbar la sesión para que tome el nuevo perfil/credenciales
            $this->mikrotik->dropActiveSession($router, $pppoe->username);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al actualizar en Mikrotik: ' . $e->getMessage());
        }

        $updateData = [
            'username'       => $validated['username'],
            'profile'        => $validated['profile'],
            'remote_address' => $validated['remote_address'] ?? null,
            'comment'        => $validated['comment'] ?? null,
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = $validated['password'];
        }

        $pppoe->update($updateData);

        return back()->with('success-update', 'Cuenta PPPoE actualizada.');
    }

    /**
     * Vincula la cuenta a un contrato.
     *
     * Va por su propia ruta y no dentro del formulario de edición
     * a propósito: editar la cuenta reescribe el secret en el
     * Mikrotik y tumba la sesión activa para aplicar los cambios.
     * Vincular es solo un registro en la base de datos y no tiene
     * por qué dejar al cliente sin servicio.
     */
    public function linkContract(Request $request, PppoeAccount $pppoe): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
        ]);

        try {
            $contrato = $this->contractLinker->linkPppoe($pppoe, (int) $validated['contract_id']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Cuenta vinculada al contrato #{$contrato->id}.");
    }

    public function unlinkContract(PppoeAccount $pppoe): RedirectResponse
    {
        try {
            $this->contractLinker->unlinkPppoe($pppoe);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cuenta desvinculada del contrato.');
    }

    public function toggleState(PppoeAccount $pppoe): RedirectResponse
    {
        $router      = Router::findOrFail($pppoe->router_id);
        $newDisabled = !$pppoe->disabled;

        try {
            $this->mikrotik->setPppSecretState($router, $pppoe, $newDisabled);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al cambiar estado: ' . $e->getMessage());
        }

        $pppoe->update(['disabled' => $newDisabled]);

        return back()->with(
            $newDisabled ? 'success-update' : 'success',
            $newDisabled ? 'Cuenta suspendida.' : 'Cuenta reactivada.'
        );
    }

    public function destroy(PppoeAccount $pppoe): RedirectResponse
    {
        $router = Router::findOrFail($pppoe->router_id);

        try {
            $this->mikrotik->deletePppSecret($router, $pppoe);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al eliminar en Mikrotik: ' . $e->getMessage());
        }

        $pppoe->delete();

        return back()->with('success-delete', 'Cuenta PPPoE eliminada.');
    }

    /**
     * API JSON — sesiones activas de un router
     */
    public function apiActiveSessions(Router $router): JsonResponse
    {
        try {
            return response()->json($this->mikrotik->getActiveSessions($router));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Importa a la DB los secrets que ya existen en el router
     */
    public function importFromRouter(Router $router): RedirectResponse
    {
        // 5 minutos para importaciones grandes, pero SOLO en web: en
        // consola PHP corre sin límite y set_time_limit() no lo
        // amplía, lo impone. Ver la nota en PppoeMassCutoff::ejecutar.
        if (!app()->runningInConsole()) {
            set_time_limit(300);
        }

        try {
            $secrets = $this->mikrotik->getPppSecrets($router);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al conectar: ' . $e->getMessage());
        }

        $imported = 0;

        // Una sola consulta: usernames que ya existen en este router
        $existingUsernames = PppoeAccount::where('router_id', $router->id)
            ->pluck('username')
            ->flip(); // flip para búsqueda O(1) con isset

        $toInsert = [];

        foreach ($secrets as $secret) {
            if (isset($existingUsernames[$secret['username']])) {
                continue;
            }

            $toInsert[] = [
                'branch_id'      => session('branch_id'),
                'router_id'      => $router->id,
                'mikrotik_id'    => $secret['mikrotik_id'],
                'username'       => $secret['username'],
                'password'       => $secret['password'] ?? '',
                'profile'        => $secret['profile'],
                'service'        => $secret['service'],
                'remote_address' => $secret['remote_address'],
                'disabled'       => $secret['disabled'],
                'comment'        => $secret['comment'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ];

            $imported++;
        }

        // Insertar en lotes de 100 (mucho más rápido que creates individuales)
        foreach (array_chunk($toInsert, 100) as $chunk) {
            PppoeAccount::insert($chunk);
        }

        return back()->with('success', "{$imported} cuentas importadas desde {$router->name}.");
    }
    public function show(PppoeAccount $pppoe)
    {
        // Carga instantánea: solo datos de la DB
        $pppoe->load(['router', 'contract.client']);

        return view('gestisp.pppoe.show', compact('pppoe'));
    }

    /**
     * Endpoint AJAX: estado de conexión en tiempo real
     */
    public function realtimeSession(PppoeAccount $pppoe): \Illuminate\Http\JsonResponse
    {
        $router = Router::findOrFail($pppoe->router_id);

        try {
            $session = $this->mikrotik->getActiveSession($router, $pppoe->username);

            // Velocidad instantánea: solo tiene sentido si hay
            // sesión activa. El router la entrega directamente en
            // bits por segundo, sin esperar a dos muestras.
            $traffic = $session
                ? $this->mikrotik->getSessionTraffic($router, $pppoe->username)
                : null;

            return response()->json([
                'ok'        => true,
                'connected' => $session !== null,
                'session'   => $session,
                'traffic'   => $traffic,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo consultar el router: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Endpoint AJAX: historial de tráfico para la gráfica de ancho
     * de banda. Las muestras las genera el comando pppoe:poll.
     */
    public function metricsHistory(Request $request, PppoeAccount $pppoe): \Illuminate\Http\JsonResponse
    {
        $hours = (int) $request->get('hours', 24);
        $hours = max(1, min($hours, 720)); // entre 1 hora y 30 días

        $samples = $pppoe->metrics()
            ->where('measured_at', '>=', now()->subHours($hours))
            ->orderBy('measured_at')
            ->get(['measured_at', 'in_bps', 'out_bps', 'connected']);

        $withData = $samples->filter(fn ($s) => $s->in_bps !== null);

        return response()->json([
            'ok' => true,
            'hours' => $hours,
            'count' => $samples->count(),
            'has_traffic' => $withData->isNotEmpty(),
            // Resumen para mostrar sobre la gráfica
            'peak_in_bps' => $withData->max('in_bps'),
            'peak_out_bps' => $withData->max('out_bps'),
            'avg_out_bps' => $withData->isNotEmpty() ? (int) round($withData->avg('out_bps')) : null,
            'samples' => $samples->map(fn ($s) => [
                't' => $s->measured_at->format('Y-m-d H:i'),
                'in_bps' => $s->in_bps,
                'out_bps' => $s->out_bps,
                'connected' => $s->connected,
            ]),
        ]);
    }

    /**
     * Reinicia la sesión del usuario (tumba la conexión activa)
     */
    public function restartSession(PppoeAccount $pppoe): \Illuminate\Http\RedirectResponse
    {
        $router = Router::findOrFail($pppoe->router_id);

        try {
            $dropped = $this->mikrotik->dropActiveSession($router, $pppoe->username);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al reiniciar sesión: ' . $e->getMessage());
        }

        return back()->with(
            $dropped ? 'success' : 'success-update',
            $dropped
                ? 'Sesión reiniciada. El cliente se reconectará automáticamente.'
                : 'El usuario no tenía sesión activa.'
        );
    }
}
