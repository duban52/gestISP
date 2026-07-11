<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Services\MikrotikApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PppoeAccountController extends Controller
{
    public function __construct(protected MikrotikApiService $mikrotik)
    {
    }

    public function index(): View
    {
        $routers  = Router::byBranch(session('branch_id'))->active()->get();
        $accounts = PppoeAccount::where('branch_id', session('branch_id'))
            ->with(['router', 'contract.client'])
            ->get();

        return view('gestisp.pppoe.index', compact('routers', 'accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'router_id'      => 'required|exists:routers,id',
            'contract_id'    => 'nullable|exists:contracts,id',
            'username'       => 'required|string|max:255',
            'password'       => 'required|string|max:255',
            'profile'        => 'required|string|max:255',
            'remote_address' => 'nullable|ip',
            'comment'        => 'nullable|string|max:255',
        ]);

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

        PppoeAccount::create([
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

        return back()->with('success', 'Cuenta PPPoE creada correctamente.');
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
        set_time_limit(300); // 5 minutos para importaciones grandes

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

            return response()->json([
                'ok'        => true,
                'connected' => $session !== null,
                'session'   => $session,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo consultar el router: ' . $e->getMessage(),
            ]);
        }
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
