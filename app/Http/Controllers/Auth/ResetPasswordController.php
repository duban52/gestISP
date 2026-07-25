<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * A dónde ir tras restablecer la contraseña.
     *
     * Apuntaba a /home, que no existe en este proyecto (la raíz es el
     * dashboard), así que el restablecimiento terminaba en un error.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Respuesta tras restablecer la contraseña correctamente.
     *
     * El trait deja al usuario autenticado, pero en GestISP una
     * sesión válida necesita además la sucursal y el rol activos, que
     * solo se eligen en el formulario de login. Esa sesión a medias
     * era la que provocaba el 403 "No tienes permiso para realizar
     * esta acción" nada más restablecer la clave.
     *
     * Por eso se cierra la sesión y se envía al login con el aviso de
     * éxito: el usuario elige su sucursal y entra con el contexto
     * completo. De paso se evita autenticar a alguien solo por tener
     * el enlace del correo.
     */
    protected function sendResetResponse(Request $request, $response)
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('status', 'Su contraseña se actualizó correctamente. Inicie sesión con la nueva contraseña.');
    }
}
