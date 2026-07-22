<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Comentarios/notas internas de un contrato.
 *
 * Bitácora libre que se ve y se alimenta desde el detalle del
 * contrato (pestaña "Comentarios"). Se reutilizan los permisos del
 * módulo de contratos: quien puede ver el contrato puede comentar;
 * borrar exige el permiso de edición.
 */
class ContractCommentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:contracts.show')->only('store');
        $this->middleware('check.permission:contracts.edit')->only('destroy');
    }

    /**
     * Guarda un comentario nuevo en el contrato.
     */
    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ], [
            'body.required' => 'Escriba el comentario antes de guardarlo.',
            'body.max'      => 'El comentario no puede superar los 2000 caracteres.',
        ]);

        $contract->comments()->create([
            'user_id' => Auth::id(),
            'body'    => $validated['body'],
        ]);

        return redirect()
            ->route('contracts.show', $contract)
            ->withFragment('contract-comments')
            ->with('success', 'Comentario agregado al contrato.');
    }

    /**
     * Elimina un comentario del contrato.
     */
    public function destroy(ContractComment $comment): RedirectResponse
    {
        $contractId = $comment->contract_id;
        $comment->delete();

        return redirect()
            ->route('contracts.show', $contractId)
            ->withFragment('contract-comments')
            ->with('success', 'Comentario eliminado.');
    }
}
