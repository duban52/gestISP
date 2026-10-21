<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Mikrotik\RegistroPppoe;
use App\Services\MikrotikApiService;
use App\Tenancy\CurrentContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * El registro de PPPoE de un router, legible.
 *
 * PARA QUÉ SIRVE
 * --------------
 * Cuando un cliente llama diciendo «no tengo internet», aquí está la
 * respuesta: si entró y se cayó, si nunca llegó a autenticar, o si
 * lleva tres días reintentando con la contraseña equivocada. Antes eso
 * exigía entrar por Winbox y saber leer el log del Mikrotik.
 *
 * SE LEE DEL ROUTER EN VIVO, NO SE GUARDA
 * ---------------------------------------
 * Y es deliberado. Copiar el log a la base significaría sondear cada
 * router cada pocos minutos, guardar miles de líneas al día por
 * equipo y decidir cuándo podarlas — todo para duplicar algo que el
 * router ya tiene. Lo que importa es la última hora cuando alguien
 * llama, y eso se consulta en el momento.
 *
 * El precio está dicho en la pantalla: el log de RouterOS vive en
 * memoria, se pierde al reiniciar y solo guarda lo que una regla de
 * `/system logging` haya mandado ahí.
 */
class PppoeLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // El registro enseña los mismos usuarios que el listado: el
        // mismo permiso. No es una acción sobre el router, es leerlo.
        $this->middleware('check.permission:pppoe.index');
    }

    public function index(Request $request, RegistroPppoe $registro, MikrotikApiService $mikrotik): View
    {
        $routers = Router::whereIn('branch_id', app(CurrentContext::class)->branchIds())
            ->orderBy('name')
            ->get();

        $router = $this->routerElegido($request, $routers);

        $datos = [
            'routers' => $routers,
            'router' => $router,
            'eventos' => collect(),
            'resumen' => null,
            'error' => null,
            'sinRegistroDePpp' => false,
            'filtros' => $request->only(['router', 'tipo', 'buscar']),
            'tipos' => [
                RegistroPppoe::FALLO => 'Fallos',
                RegistroPppoe::CONEXION => 'Conexiones',
                RegistroPppoe::DESCONEXION => 'Desconexiones',
                RegistroPppoe::OTRO => 'Otros',
            ],
        ];

        if (!$router) {
            return view('gestisp.pppoe.log', $datos);
        }

        try {
            $crudo = $mikrotik->getLog($router);
        } catch (Throwable $e) {
            // El router caído no es un error de la aplicación: es
            // información, y la pantalla tiene que decirlo sin romperse.
            return view('gestisp.pppoe.log', array_merge($datos, [
                'error' => $e->getMessage(),
            ]));
        }

        $eventos = $registro->interpretar($crudo);

        // DISTINGUIR «NO HAY NADA QUE CONTAR» DE «NO SE ESTÁ
        // REGISTRANDO». El router respondió con su log, pero sin una
        // regla de logging para los temas de ppp no habrá ni una línea
        // de cuentas por muchos clientes que entren y salgan. Sin esta
        // distinción la pantalla diría «todo en orden» mientras no se
        // está mirando nada.
        $datos['sinRegistroDePpp'] = $eventos->isEmpty() && $crudo !== [];

        $eventos = $this->filtrar($eventos, $request);

        return view('gestisp.pppoe.log', array_merge($datos, [
            'eventos' => $eventos,
            'resumen' => $registro->resumen($eventos),
        ]));
    }

    /**
     * El router que se está mirando.
     *
     * Si no se pide ninguno se toma el primero: la pantalla sirve
     * para mirar, y abrirla vacía obliga a un clic que no decide nada
     * cuando hay un solo router.
     */
    private function routerElegido(Request $request, $routers): ?Router
    {
        if ($request->filled('router')) {
            // Dentro del alcance: el id viaja por la URL y sin esto se
            // leería el log de un router de otra sucursal.
            return $routers->firstWhere('id', (int) $request->query('router'));
        }

        return $routers->first();
    }

    /** @param \Illuminate\Support\Collection<int, array> $eventos */
    private function filtrar($eventos, Request $request)
    {
        if ($request->filled('tipo')) {
            $eventos = $eventos->where('tipo', $request->query('tipo'));
        }

        if ($request->filled('buscar')) {
            $texto = mb_strtolower(trim($request->query('buscar')));

            $eventos = $eventos->filter(
                fn ($e) => str_contains(mb_strtolower($e['usuario'] ?? ''), $texto)
                    || str_contains(mb_strtolower($e['mensaje']), $texto)
            );
        }

        return $eventos->values();
    }
}
