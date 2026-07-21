<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionTracker;
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

    public function __construct(private readonly SessionTracker $tracker)
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }
    //Obtener las sucursales para el login
    public function getBranches(Request $request)
    {
        $email = $request->query('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['branches' => []]);
        }

        // Especificamos la tabla para cada columna
        $branches = $user->branches()
            ->select('branches.id', 'branches.name')  // Especificamos la tabla 'branches'
            ->get()
            ->pluck('name', 'id')
            ->toArray();

        return response()->json([
            'branches' => $branches
        ]);
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
                $this->username() => 'Su usuario está inhabilitado. Comuníquese con un administrador.',
            ]);
        }

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    protected function authenticated(Request $request, $user)
    {
        $branchId = $request->input('branch_id');

        if (!$branchId) {
            return redirect()->back()->withErrors(['branch_id' => 'Debe seleccionar una sucursal']);
        }

        // Verificar si el usuario tiene acceso a esta sucursal
        $branchRole = $user->branches()->where('branch_id', $branchId)->first();

        if (!$branchRole) {
            return redirect()->back()->withErrors(['branch_id' => 'No tiene acceso a esta sucursal']);
        }

        // Guardar la sucursal y el rol en la sesión
        session([
            'branch_id' => $branchId,
            'current_role_id' => $branchRole->pivot->role_id, // Guardar el role_id
        ]);

        // Actualizar la sucursal seleccionada en el usuario
        $user->update(['selected_branch_id' => $branchId]);

        // Trazabilidad: registrar el inicio de sesión con la
        // sucursal elegida. Va aquí y no en el evento Login porque
        // este es el punto donde ya se conoce la sucursal.
        $this->tracker->start($user, $request, (int) $branchId);

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
