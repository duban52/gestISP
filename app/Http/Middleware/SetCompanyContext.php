<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa el contexto de empresa de la peticion.
 *
 * Es lo que enciende el aislamiento: mientras el contexto no este
 * activo, el global scope de BelongsToCompany no filtra nada. Por eso
 * va DESPUES de EnsureBranchSession, que es quien deja la sucursal en
 * la sesion, y ANTES de todo lo que consulte datos.
 *
 * Solo actua sobre usuarios autenticados con sucursal en sesion. Un
 * invitado no llega a ninguna consulta de datos, y un proceso de
 * consola no pasa por aqui —que es justo lo que permite que los
 * pollers y la facturacion recorran varias empresas.
 */
class SetCompanyContext
{
    public function __construct(private readonly CurrentContext $contexto)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check() || !$request->hasSession()) {
            return $next($request);
        }

        $sesion = $request->session();

        // Nada de esto se lee de la PETICION: sale de la sesion, donde
        // lo dejo ContextResolver despues de comprobar que el usuario
        // tiene ese acceso. Aceptar un company_id de la peticion seria
        // darle la llave al que llama a la puerta.
        $companyId = $sesion->get('company_id');
        $branchIds = $sesion->get('branch_ids');
        $branchId = $sesion->get('branch_id');

        if ($companyId && is_array($branchIds) && $branchIds !== []) {
            // Camino normal: el contexto se eligio en el selector. Es
            // el unico que sabe reconstruir el panel consolidado, donde
            // no hay UNA sucursal activa sino un conjunto.
            $this->contexto->establecer(
                (int) $companyId,
                $branchIds,
                $branchId ? (int) $branchId : null,
            );

            return $next($request);
        }

        if ($branchId) {
            // Sesion antigua: la que dejo el login de siempre, con solo
            // la sucursal. Se deduce la empresa de ella. Sin esto,
            // quien tuviera la sesion abierta al desplegar se quedaria
            // sin contexto —y por tanto sin barrera— hasta volver a
            // entrar.
            $this->contexto->establecerDesdeSucursal((int) $branchId);

            return $next($request);
        }

        // SIN CONTEXTO NO SE VEN DATOS.
        //
        // El aislamiento vive en un global scope que solo filtra cuando
        // hay contexto. Dejar pasar a un usuario autenticado sin el
        // seria dejarlo navegar SIN barrera. Se le manda a elegir.
        //
        // Es el caso de quien tiene acceso a varias empresas y todavia
        // no ha escogido: EnsureBranchSession ya no adivina por el.
        if (!$this->esRutaDeContexto($request)) {
            return redirect()->route('context.select');
        }

        return $next($request);
    }

    /**
     * Rutas que se pueden abrir sin contexto.
     *
     * Son las de elegirlo y la de salir: sin esta excepcion, el
     * usuario quedaria dando vueltas en una redireccion infinita hacia
     * una pantalla que tampoco podria abrir.
     */
    private function esRutaDeContexto(Request $request): bool
    {
        return $request->routeIs('context.*', 'logout')
            || $request->is('logout');
    }
}
