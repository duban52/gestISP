<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Tenancy\ContextResolver;
use App\Tenancy\CurrentContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

/**
 * Eleccion y cambio del contexto de trabajo (empresa + sucursal).
 *
 * POR QUE ES UNA PANTALLA APARTE Y NO PARTE DEL LOGIN
 * ---------------------------------------------------
 * Hasta ahora la sucursal se elegia EN el formulario de acceso, y para
 * poder ofrecerla habia que consultarla antes de autenticar: una ruta
 * publica que, dado un correo, respondia si existia y a que sucursales
 * pertenecia. Eso es un mecanismo de enumeracion de usuarios servido
 * en bandeja.
 *
 * Separandolo, primero se comprueba quien eres y solo despues se
 * pregunta desde donde vas a trabajar — que ademas es el orden
 * natural.
 *
 * TAMBIEN SIRVE PARA CAMBIAR SIN SALIR
 * ------------------------------------
 * Antes, cambiar de sucursal exigia cerrar sesion y volver a entrar.
 * Con varias empresas eso pasa de incomodo a inviable.
 */
class ContextController extends Controller
{
    public function __construct(
        private readonly ContextResolver $resolver,
        private readonly CurrentContext $contexto,
    ) {
        $this->middleware('auth');
    }

    /**
     * Pantalla de eleccion.
     *
     * Si solo hay un contexto posible no se pregunta: se entra. Una
     * pantalla con una sola opcion es un clic de mas en cada acceso.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $usuario = Auth::user();

        // Solo se entra en automatico cuando AUN NO hay contexto, que
        // es el caso de venir del acceso. Si el usuario ya esta dentro
        // y abre esta pantalla a proposito, se le muestra aunque tenga
        // una sola opcion: mandarlo de vuelta en silencio pareceria que
        // el boton no funciona.
        if (!$this->contexto->activo()) {
            $unico = $this->resolver->unicoPara($usuario);

            if ($unico) {
                $this->resolver->aplicar($usuario, $unico['company_id'], $unico['branch_id']);

                return redirect()->intended('/');
            }
        }

        $disponibles = $this->resolver->disponiblesPara($usuario);

        if ($disponibles->isEmpty()) {
            // Sin ninguna sucursal asignada no hay nada que hacer
            // dentro. Antes esto dejaba al usuario dando vueltas con
            // un 403 en el panel.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Su usuario no tiene ninguna sucursal asignada. Contacte al administrador.']);
        }

        return view('gestisp.context.select', [
            'disponibles' => $disponibles,
            'actual' => [
                'company_id' => session('company_id'),
                'branch_id' => session('branch_id'),
            ],
        ]);
    }

    /**
     * Entra en el contexto elegido.
     */
    public function store(Request $request): RedirectResponse
    {
        $validado = $request->validate([
            'company_id' => 'required|integer',
            // Ausente pide el panel consolidado. Que la empresa lo
            // permita se comprueba en el resolutor, no aqui.
            'branch_id' => 'nullable|integer',
        ]);

        $usuario = Auth::user();

        try {
            $this->resolver->aplicar(
                $usuario,
                (int) $validado['company_id'],
                isset($validado['branch_id']) ? (int) $validado['branch_id'] : null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['company_id' => $e->getMessage()]);
        }

        // La auditoria va DESPUES de aplicar: asi el registro queda con
        // el contexto nuevo, que es el que explica lo que venga luego.
        $this->resolver->auditarCambio(
            Company::findOrFail($validado['company_id']),
            isset($validado['branch_id'])
                ? Branch::sinFiltroDeEmpresa()->find($validado['branch_id'])
                : null,
        );

        return redirect()->intended('/')
            ->with('success', 'Contexto de trabajo cambiado.');
    }
}
