<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionTracker;
use App\Tenancy\ContextResolver;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    // Se renombra el logout del trait para envolverlo y capturar el
    // id de sesión antes de que se invalide (trazabilidad)
    use AuthenticatesUsers {
        logout as protected traitLogout;
    }

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    public function __construct(
        private readonly SessionTracker $tracker,
        private readonly ContextResolver $contextos,
    ) {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Credenciales con las que se intenta autenticar.
     *
     * Se añade is_active: un usuario inhabilitado no coincide y no
     * puede entrar. El mensaje diferenciado lo pone
     * sendFailedLoginResponse().
     */
    protected function credentials(Request $request)
    {
        return array_merge(
            $request->only($this->username(), 'password'),
            ['is_active' => true],
        );
    }

    /**
     * Respuesta ante un intento fallido.
     *
     * Si el correo y la contraseña son correctos pero el usuario
     * está inhabilitado, se avisa de forma clara en vez del genérico
     * "credenciales incorrectas", que confundiría a un empleado
     * suspendido.
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        $user = User::where($this->username(), $request->input($this->username()))->first();

        if ($user && !$user->is_active && \Illuminate\Support\Facades\Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                $this->username() => trans('auth.inactive'),
            ]);
        }

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    /**
     * Que pasa justo despues de comprobar las credenciales.
     *
     * EL ORDEN CAMBIO, Y ESE ES EL PUNTO
     * ----------------------------------
     * Antes la sucursal se elegia EN el formulario de acceso. Para
     * poder ofrecerla habia que consultarla ANTES de autenticar, con
     * una ruta publica que respondia, dado un correo, si existia un
     * usuario con el y a que sucursales pertenecia. Eso es enumeracion
     * de usuarios servida en bandeja, y ademas impedia el multiempresa:
     * un desplegable de sucursales sueltas no distingue de que empresa
     * es cada una.
     *
     * Ahora primero se comprueba quien eres y despues desde donde vas
     * a trabajar. Si no hay nada que elegir —una empresa con una sede,
     * o una empresa consolidada— se entra directo y el usuario no ve
     * ninguna pantalla de mas.
     */
    protected function authenticated(Request $request, $user)
    {
        $unico = $this->contextos->unicoPara($user);

        if (!$unico) {
            // La pantalla de eleccion se encarga tambien del caso de un
            // usuario sin ninguna sucursal asignada.
            return redirect()->route('context.select');
        }

        $this->contextos->aplicar($user, $unico['company_id'], $unico['branch_id']);

        // Trazabilidad: el inicio de sesion se registra AQUI y no en el
        // evento Login porque este es el punto donde ya se conoce la
        // sucursal.
        $this->tracker->start($user, $request, $unico['branch_id']);

        return redirect()->intended($this->redirectPath());
    }

    /**
     * Cierre de sesión: se marca la salida en la trazabilidad.
     *
     * El id de la fila se captura ANTES de invalidar la sesión (el
     * trait la invalida justo después), para poder localizarla.
     */
    protected function loggedOut(Request $request)
    {
        $this->tracker->end($this->traceIdSaliente, \App\Models\UserSession::REASON_MANUAL);
    }

    /**
     * Id de la fila de trazabilidad que se está cerrando, capturado
     * antes de que el trait invalide la sesión.
     */
    private ?int $traceIdSaliente = null;

    public function logout(Request $request)
    {
        // Se lee antes de que el trait invalide la sesión, para que
        // loggedOut() sepa qué fila marcar
        $this->traceIdSaliente = $this->tracker->traceIdActual($request);

        return $this->traitLogout($request);
    }
}
