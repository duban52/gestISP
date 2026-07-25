<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restaura la sucursal y el rol activos de la sesión cuando faltan.
 *
 * `branch_id` y `current_role_id` solo se escriben al iniciar sesión
 * (LoginController::authenticated), pero hay caminos que dejan al
 * usuario autenticado SIN pasar por ahí:
 *
 *   - El restablecimiento de contraseña, que autentica al usuario
 *     directamente.
 *   - La cookie de "recordarme", que reabre la sesión en silencio.
 *   - Cualquier regeneración de la sesión.
 *
 * En esos casos la sesión quedaba autenticada pero sin rol, y
 * CheckPermission respondía 403 ("No tienes permiso para realizar
 * esta acción") en el dashboard — el usuario quedaba encerrado sin
 * poder hacer nada.
 *
 * Aquí se recompone el contexto a partir de la sucursal que el
 * usuario tenía seleccionada (users.selected_branch_id) y del rol que
 * le corresponde EN ESA SUCURSAL según la tabla user_branch. Los dos
 * datos salen de la base, nunca de la petición, así que un usuario no
 * puede elevarse de privilegios por esta vía.
 */
class EnsureBranchSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && $request->hasSession() && !$request->session()->has('current_role_id')) {
            $this->restaurar($request, Auth::user());
        }

        return $next($request);
    }

    /**
     * Repone en la sesión la sucursal y el rol del usuario.
     *
     * Se prefiere la última sucursal seleccionada; si ya no tiene
     * acceso a ella (o nunca eligió una), se usa la primera a la que
     * pertenezca. Si no pertenece a ninguna, no se toca la sesión:
     * CheckPermission se encargará de mandarlo al login.
     */
    private function restaurar(Request $request, User $user): void
    {
        $branch = $user->branches()
                ->where('branches.id', $user->selected_branch_id)
                ->first()
            ?? $user->branches()->first();

        if (!$branch || !$branch->pivot->role_id) {
            return;
        }

        $request->session()->put([
            'branch_id' => (string) $branch->id,
            'current_role_id' => (string) $branch->pivot->role_id,
        ]);
    }
}
