<?php

namespace App\Http\Middleware;

use App\Tenancy\ContextResolver;
use App\Tenancy\CurrentContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repone el contexto de trabajo cuando la sesion lo perdio.
 *
 * POR QUE HACE FALTA
 * ------------------
 * El contexto se fija al entrar, pero hay caminos que dejan al usuario
 * autenticado sin pasar por ahi:
 *
 *   - El restablecimiento de contrasena, que autentica directamente.
 *   - La cookie de "recordarme", que reabre la sesion en silencio.
 *   - Cualquier regeneracion de la sesion.
 *
 * Sin esto la sesion queda autenticada pero sin rol, y CheckPermission
 * responde 403 en el propio panel: el usuario se queda encerrado sin
 * poder hacer nada.
 *
 * QUE CAMBIO CON EL MULTIEMPRESA
 * ------------------------------
 * Antes cogia la ultima sucursal usada, o la primera que encontrara.
 * Eso ahora seria un fallo: a un usuario con acceso a varias empresas
 * le elegiria una EN SILENCIO, saltandose el selector y dejandolo
 * trabajando en un contexto que no pidio.
 *
 * Asi que ya no adivina. Solo repone cuando no hay nada que elegir
 * —una empresa con una sede, o una empresa consolidada—, que es
 * exactamente el caso que justificaba este middleware. Si hay varias
 * opciones, se queda sin contexto y SetCompanyContext manda al
 * selector.
 */
class EnsureBranchSession
{
    public function __construct(
        private readonly ContextResolver $resolver,
        private readonly CurrentContext $contexto,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && $request->hasSession() && !$request->session()->has('company_id')) {
            $this->reponer(Auth::user());
        }

        return $next($request);
    }

    /**
     * Repone el contexto SOLO si es inequivoco.
     *
     * Los datos salen de la base (user_branch), nunca de la peticion,
     * asi que por esta via nadie puede elevarse de privilegios.
     */
    private function reponer($usuario): void
    {
        $unico = $this->resolver->unicoPara($usuario);

        if (!$unico) {
            return;
        }

        $this->resolver->aplicar($usuario, $unico['company_id'], $unico['branch_id']);
    }
}
