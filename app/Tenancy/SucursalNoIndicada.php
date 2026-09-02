<?php

namespace App\Tenancy;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * No se dijo en qué sucursal se guarda, y hacía falta decirlo.
 *
 * POR QUÉ TIENE SU PROPIA CLASE
 * -----------------------------
 * `branchParaEscritura()` lanza excepción en dos situaciones muy
 * distintas:
 *
 *   1. No hay contexto de trabajo. Eso es un fallo del programa —el
 *      middleware debería haberlo impedido— y un error 500 es la
 *      respuesta correcta: hay que verlo y arreglarlo.
 *
 *   2. Estamos en panel consolidado y el formulario no trajo sucursal,
 *      o trajo una que no es del usuario. Eso NO es un fallo del
 *      programa: es un dato que falta. Un 500 ahí es maltratar a quien
 *      simplemente no rellenó un campo.
 *
 * Esta clase separa el segundo caso. Al definir render(), Laravel la
 * convierte sola en lo que corresponda —un error junto al campo en un
 * formulario, un 422 en una petición JSON— sin tocar el Handler ni
 * envolver cada llamada en un try/catch.
 *
 * Sigue heredando de RuntimeException, así que quien la capture por
 * el tipo de antes la sigue capturando.
 */
class SucursalNoIndicada extends RuntimeException
{
    public function __construct(
        string $message = 'Indique la sucursal en la que se registra. En panel consolidado no se deduce sola.'
    ) {
        parent::__construct($message);
    }

    /**
     * Cómo se le muestra esto al usuario.
     *
     * El error se cuelga del campo `branch_id` para que salga junto al
     * selector, que es donde lo va a buscar.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'error' => $this->getMessage(),
                'errors' => ['branch_id' => [$this->getMessage()]],
            ], 422);
        }

        return back()
            ->withInput()
            ->withErrors(['branch_id' => $this->getMessage()]);
    }
}
