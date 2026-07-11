<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\MikrotikApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RouterController extends Controller
{
    public function __construct(protected MikrotikApiService $mikrotik)
    {
    }

    public function index(): View
    {
        return view('gestisp.routers.index');
    }

    public function create(): View
    {
        return view('gestisp.routers.create');
    }

    public function edit(Router $router): View
    {
        return view('gestisp.routers.edit', compact('router'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRouter($request);
        $validated['branch_id'] = session('branch_id');

        Router::create($validated);

        return redirect()->route('routers.index')
            ->with('success', 'Router creado correctamente.');
    }

    public function update(Request $request, Router $router): RedirectResponse
    {
        $validated = $this->validateRouter($request, $router->id);

        // Si no se envía password nueva, conservar la actual
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $router->update($validated);

        return redirect()->route('routers.index')
            ->with('success-update', 'Router actualizado correctamente.');
    }

    public function destroy(Router $router): RedirectResponse
    {
        if ($router->pppoeAccounts()->exists()) {
            return back()->with('error', 'No se puede eliminar: el router tiene cuentas PPPoE asociadas.');
        }

        $router->delete();

        return back()->with('success-delete', 'Router eliminado correctamente.');
    }

    /**
     * API JSON — routers con estado en tiempo real (para el DataTable)
     */
    public function apiRouters(): JsonResponse
    {
        $routers = Router::byBranch(session('branch_id'))->get();

        $data = $routers->map(function ($router) {
            try {
                $info = $this->mikrotik->getSystemInfo($router);

                $router->update([
                    'status'     => true,
                    'version'    => $info['version'],
                    'board_name' => $info['board_name'],
                    'uptime'     => $info['uptime'],
                ]);
            } catch (\Exception $e) {
                $info = ['status' => false, 'version' => null, 'board_name' => null, 'uptime' => null, 'cpu_load' => null];
                $router->update(['status' => false]);
            }

            return [
                'id'         => $router->id,
                'name'       => $router->name,
                'ip_address' => $router->ip_address,
                'status'     => $info['status'],
                'version'    => $info['version'],
                'board_name' => $info['board_name'],
                'uptime'     => $info['uptime'],
                'cpu_load'   => $info['cpu_load'] ?? null,
            ];
        });

        return response()->json($data);
    }

    /**
     * API JSON — perfiles PPP de un router (para selects)
     */
    public function apiProfiles(Router $router): JsonResponse
    {
        try {
            return response()->json($this->mikrotik->getPppProfiles($router));
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al conectar: ' . $e->getMessage()], 500);
        }
    }

    private function validateRouter(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'       => 'required|string|min:3|max:255',
            'ip_address' => 'required|ip',
            'api_port'   => 'required|numeric|min:1|max:65535',
            'username'   => 'required|string|max:255',
            'password'   => ($ignoreId ? 'nullable' : 'required') . '|string|max:255',
            'model'      => 'nullable|string|max:255',
        ]);
    }
}
