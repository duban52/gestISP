<?php

namespace App\Http\Controllers;

use App\Models\FiscalCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consultas a los catalogos fiscales desde los formularios.
 *
 * Hoy solo los municipios de un departamento. Son 1.122 en total y
 * cargarlos todos de golpe en cada formulario es medio megabyte de
 * HTML y un desplegable inmanejable en movil, asi que se piden los del
 * departamento elegido.
 *
 * NO lleva permiso propio: son los codigos publicos de la DIAN, los
 * mismos para todo el mundo, y no dicen nada de ninguna empresa. Lo
 * unico que se exige es estar autenticado.
 */
class FiscalCatalogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /** Municipios de un departamento: codigo DANE => nombre. */
    public function municipios(Request $request): JsonResponse
    {
        $departamento = (string) $request->query('departamento', '');

        if ($departamento === '') {
            return response()->json([]);
        }

        return response()->json(
            FiscalCatalog::opciones(FiscalCatalog::MUNICIPIO, $departamento),
        );
    }
}
