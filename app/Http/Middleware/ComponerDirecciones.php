<?php

namespace App\Http\Middleware;

use App\Support\Direccion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arma las direcciones que llegan por partes, antes de que las vea el
 * controlador.
 *
 * El parcial gestisp.partials.direccion manda, por cada dirección, sus
 * partes en `{campo}_partes[...]` y el nombre del campo en
 * `_direcciones[]`. Aquí se validan y se escribe `{campo}` ya armado.
 * El controlador recibe `address` como siempre y no se entera: por eso
 * esto vive aquí y no repetido en cada uno de los doce sitios que
 * guardan una dirección.
 */
class ComponerDirecciones
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('_direcciones')) {
            $campos = array_filter((array) $request->input('_direcciones'), 'is_string');
            $request->request->remove('_direcciones');

            foreach ($campos as $campo) {
                Direccion::desdeRequest($request, $campo);
            }
        }

        return $next($request);
    }
}
