<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use App\Services\SessionTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Perfil del usuario autenticado.
 *
 * Cada quien administra AQUÍ sus propios datos: foto, información
 * personal y contraseña. Es distinto del módulo de Usuarios, que sirve
 * para que un administrador gestione las cuentas de los demás.
 *
 * Por eso NO lleva el middleware check.permission: no hace falta un
 * permiso para editarse a uno mismo, y siempre se opera sobre
 * Auth::user() — nunca sobre un id que venga en la petición, así nadie
 * puede modificar el perfil ajeno.
 */
class ProfileController extends Controller
{
    /** Carpeta de las fotos dentro del disco público. */
    private const CARPETA_FOTOS = 'avatars';

    public function __construct(private readonly SessionTracker $tracker)
    {
        $this->middleware('auth');
    }

    /**
     * Pantalla del perfil: datos, seguridad y sesiones recientes.
     */
    public function edit(): View
    {
        $user = Auth::user()->loadMissing('branches');

        // Últimos accesos, para que el usuario detecte entradas que no
        // reconozca. Sale de la trazabilidad que ya registra el sistema.
        $sesiones = UserSession::where('user_id', $user->id)
            ->orderByDesc('login_at')
            ->limit(5)
            ->get();

        return view('gestisp.profile.edit', compact('user', 'sesiones'));
    }

    /**
     * Actualiza los datos personales.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $datos = $request->validate([
            'name' => 'required|string|max:40',
            'last_name' => 'required|string|max:40',
            'identity_number' => [
                'required', 'string', 'max:20',
                Rule::unique('users', 'identity_number')->ignore($user->id),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'number_phone' => 'required|string|max:20',
            'address' => 'required|string|max:255',
        ], [
            'identity_number.unique' => 'Ese número de identificación ya está registrado en otra cuenta.',
            'email.unique' => 'Ese correo ya está registrado en otra cuenta.',
        ]);

        $user->update($datos);

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Sus datos se actualizaron correctamente.');
    }

    /**
     * Cambia la contraseña.
     *
     * Se exige la contraseña actual: si alguien encuentra la sesión
     * abierta, no puede apoderarse de la cuenta cambiándola.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed|different:current_password',
        ], [
            'password.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ]);

        if (!Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'La contraseña actual no es correcta.',
            ]);
        }

        $user->update(['password' => $request->input('password')]);

        // Cambiar la contraseña expulsa las sesiones abiertas en otros
        // equipos. Se usa la trazabilidad propia del sistema y no
        // Auth::logoutOtherDevices(), que exige el middleware
        // AuthenticateSession (no activo aquí) y no cerraría nada.
        $cerradas = $this->tracker->forceCloseAllFor(
            $user,
            $this->tracker->traceIdActual($request),
        );

        $aviso = $cerradas > 0
            ? "Su contraseña se actualizó. Se cerraron {$cerradas} sesión(es) abiertas en otros dispositivos."
            : 'Su contraseña se actualizó correctamente.';

        return redirect()
            ->route('profile.edit')
            ->with('success', $aviso);
    }

    /**
     * Sube o reemplaza la foto de perfil.
     */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'avatar.required' => 'Seleccione una imagen para subirla.',
            'avatar.image' => 'El archivo debe ser una imagen.',
            'avatar.mimes' => 'La foto debe estar en formato JPG, PNG o WEBP.',
            'avatar.max' => 'La foto no puede pesar más de 2 MB.',
        ]);

        $user = Auth::user();

        $anterior = $user->avatar;

        $user->update([
            'avatar' => $request->file('avatar')->store(self::CARPETA_FOTOS, 'public'),
        ]);

        // La foto vieja se borra DESPUÉS de guardar la nueva: si el
        // guardado falla, el usuario no se queda sin ninguna.
        $this->borrarFoto($anterior);

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Su foto de perfil se actualizó.');
    }

    /**
     * Quita la foto y vuelve al avatar con las iniciales.
     */
    public function destroyPhoto(): RedirectResponse
    {
        $user = Auth::user();

        if (!$user->avatar) {
            return redirect()->route('profile.edit');
        }

        $anterior = $user->avatar;
        $user->update(['avatar' => null]);
        $this->borrarFoto($anterior);

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Se quitó su foto de perfil.');
    }

    /**
     * Elimina del disco una foto anterior, si existe.
     */
    private function borrarFoto(?string $ruta): void
    {
        if ($ruta && Storage::disk('public')->exists($ruta)) {
            Storage::disk('public')->delete($ruta);
        }
    }
}
