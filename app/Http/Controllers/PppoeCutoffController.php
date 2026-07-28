<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\PppoeMassCutoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Cortes masivos de servicio sobre cuentas PPPoE.
 *
 * La pantalla trabaja en dos pasos que NO se pueden saltar:
 *
 *   1. REVISAR — se resuelve la lista contra la base de datos y se
 *      muestra a quién se va a cortar. No se toca nada.
 *   2. EJECUTAR — recién ahí se deshabilita el secret y se tumba la
 *      sesión.
 *
 * La separación no es cosmética: un corte masivo deja a decenas de
 * clientes sin servicio y no hay un botón de deshacer. Que el
 * operador vea nombres y contratos antes de confirmar es lo que
 * evita cortar a la lista equivocada.
 *
 * La regla de negocio vive completa en App\Services\PppoeMassCutoff;
 * aquí solo se valida la entrada y se traduce a JSON.
 */
class PppoeCutoffController extends Controller
{
    public function __construct(
        private readonly PppoeMassCutoff $cortes,
        private readonly AuditLogger $auditLogger,
    ) {
        $this->middleware('auth');
        $this->middleware('check.permission:pppoe.cutoff');
    }

    /** Pantalla de cortes masivos. */
    public function create(): View
    {
        return view('gestisp.pppoe.cutoff', [
            'maximo' => PppoeMassCutoff::MAXIMO,
        ]);
    }

    /**
     * Paso 1: resuelve la lista y muestra qué pasaría. No corta nada.
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $identificadores = $this->identificadores($request);

            if (empty($identificadores)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'No se encontró ningún número de contrato ni usuario en lo que envió.',
                ], 422);
            }

            $filas = $this->cortes->resolver($identificadores, (int) session('branch_id'));

            return response()->json([
                'ok' => true,
                'identificadores' => $identificadores,
                'filas' => $filas,
                'resumen' => $this->cortes->resumen($filas),
            ]);

        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Paso 2: ejecuta el corte.
     *
     * Recibe la LISTA DE IDENTIFICADORES, no las cuentas resueltas:
     * el servicio vuelve a buscarlas contra la base, de modo que lo
     * que se corta nunca sale de un formulario que se pueda manipular
     * desde el navegador.
     */
    public function execute(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'identificadores' => 'required|array|min:1|max:' . PppoeMassCutoff::MAXIMO,
            'identificadores.*' => 'required|string|max:150',
        ], [
            'identificadores.required' => 'No hay nada que cortar. Revise la lista primero.',
            'identificadores.max' => 'La lista supera el máximo por tanda (' . PppoeMassCutoff::MAXIMO . ').',
        ]);

        try {
            $resultado = $this->cortes->ejecutar(
                $validado['identificadores'],
                (int) session('branch_id'),
                auth()->id(),
            );

            return response()->json([
                'ok' => true,
                'cortadas' => $resultado['cortadas'],
                'errores' => $resultado['errores'],
                'filas' => $resultado['filas'],
                'mensaje' => $this->mensajeFinal($resultado['cortadas'], $resultado['errores']),
            ]);

        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Identificadores del request: del archivo si viene, del texto si no.
     *
     * @return array<int, string>
     */
    private function identificadores(Request $request): array
    {
        $request->validate([
            'archivo' => 'nullable|file|max:5120|mimes:txt,csv,xlsx,xls',
            'lista' => 'nullable|string|max:100000',
        ], [
            'archivo.mimes' => 'El archivo debe ser .txt, .csv, .xlsx o .xls.',
            'archivo.max' => 'El archivo no puede pesar más de 5 MB.',
        ]);

        if ($request->hasFile('archivo')) {
            $archivo = $request->file('archivo');

            // Queda constancia de qué archivo se usó: si mañana se
            // cortó a quien no era, hay que poder saber de dónde salió
            // la lista.
            $this->auditLogger->action(
                'pppoe.cutoff_file_loaded',
                'Cargó el archivo "' . $archivo->getClientOriginalName() . '" para un corte masivo',
                [
                    'archivo' => $archivo->getClientOriginalName(),
                    'tamano_kb' => round($archivo->getSize() / 1024, 1),
                ],
                null,
                'pppoe',
            );

            return $this->cortes->identificadoresDesdeArchivo($archivo);
        }

        return $this->cortes->identificadoresDesdeTexto($request->input('lista'));
    }

    private function mensajeFinal(int $cortadas, int $errores): string
    {
        if ($cortadas === 0 && $errores === 0) {
            return 'No había ninguna cuenta por cortar.';
        }

        if ($errores === 0) {
            return $cortadas === 1
                ? 'Se cortó 1 cuenta.'
                : "Se cortaron {$cortadas} cuentas.";
        }

        return sprintf(
            'Se cortaron %d cuenta(s) y %d fallaron. Revise el detalle y vuelva a intentar solo esas.',
            $cortadas,
            $errores,
        );
    }
}
