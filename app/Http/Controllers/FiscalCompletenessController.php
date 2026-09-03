<?php

namespace App\Http\Controllers;

use App\Reports\FiscalCompletenessReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Informe de completitud fiscal.
 *
 * Contesta, antes de que haga falta, la pregunta que si no solo se
 * contesta el dia que se intenta emitir: que falta para poder facturar
 * electronicamente.
 *
 * Por defecto mira solo lo que VA a facturar electronicamente —un
 * cliente cuyos contratos son todos internos no necesita datos
 * fiscales completos, y contarlo seria ruido que esconde los que si
 * importan—. Con `?todos=1` se ven todos.
 */
class FiscalCompletenessController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:fiscal.completeness');
    }

    public function index(Request $request): View
    {
        $todos = $request->boolean('todos');
        $informe = new FiscalCompletenessReport(incluirTodos: $todos);

        return view('gestisp.fiscal.completeness', [
            'resumen' => $informe->resumen(),
            // Solo los incompletos: la lista es para arreglar, no para
            // celebrar los que ya estan.
            'clientes' => $informe->clientes()->where('faltan', '!=', [])->take(200),
            'servicios' => $informe->servicios()->where('faltan', '!=', []),
            'empresa' => $informe->empresa(),
            'todos' => $todos,
        ]);
    }
}
