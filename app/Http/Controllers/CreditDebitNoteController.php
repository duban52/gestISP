<?php

namespace App\Http\Controllers;

use App\Billing\Enums\NoteType;
use App\Billing\Services\NoteIssuer;
use App\Models\CreditDebitNote;
use App\Models\Invoice;
use App\Support\PdfBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Notas crédito y débito sobre facturas.
 *
 * Son los documentos con los que se corrige una factura ya emitida.
 * La lógica (validación, numeración y efecto sobre el saldo) vive en
 * App\Billing\Services\NoteIssuer; aquí solo se maneja el HTTP.
 *
 * Cada acción tiene su propio permiso para poder concederlas por
 * separado: normalmente quien factura puede consultarlas, pero
 * emitirlas o anularlas se reserva a quien lleva la contabilidad.
 */
class CreditDebitNoteController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:notes.index')->only('index', 'show');
        $this->middleware('check.permission:notes.create')->only('create', 'store');
        $this->middleware('check.permission:notes.void')->only('void');
        $this->middleware('check.permission:notes.pdf')->only('pdf');
    }

    /**
     * Listado de notas de la sucursal.
     */
    public function index(Request $request): View
    {
        $notas = CreditDebitNote::with(['invoice', 'contract.client', 'user'])
            ->where('branch_id', session('branch_id'))
            ->when($request->filled('tipo'), fn ($q) => $q->where('type', $request->input('tipo')))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return view('gestisp.notes.index', [
            'notas' => $notas,
            'tipos' => NoteType::opciones(),
        ]);
    }

    /**
     * Formulario de emisión sobre una factura concreta.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $factura = Invoice::with(['contract.client', 'invoice_items', 'notes'])
            ->findOrFail($request->input('invoice'));

        if ((int) $factura->branch_id !== (int) session('branch_id')) {
            abort(403, 'Esta factura pertenece a otra sucursal.');
        }

        return view('gestisp.notes.create', [
            'factura' => $factura,
            'tipos' => NoteType::opciones(),
            'conceptos' => [
                NoteType::Credito->value => NoteType::Credito->conceptos(),
                NoteType::Debito->value => NoteType::Debito->conceptos(),
            ],
        ]);
    }

    /**
     * Emite la nota.
     */
    public function store(Request $request, NoteIssuer $emisor): RedirectResponse
    {
        $datos = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'type' => ['required', Rule::in([NoteType::Credito->value, NoteType::Debito->value])],
            'concept_code' => 'required|string|max:5',
            // El motivo es lo que sustenta la corrección ante una
            // revisión: se exige y con longitud suficiente.
            'reason' => 'required|string|min:10|max:1000',
            'subtotal' => 'required|numeric|min:0.01',
            'tax' => 'nullable|numeric|min:0',
            'issue_date' => 'nullable|date',
        ], [
            'reason.required' => 'Explique el motivo de la nota: es el sustento del ajuste.',
            'reason.min' => 'El motivo debe explicar la razón del ajuste (mínimo 10 caracteres).',
            'subtotal.min' => 'El valor de la nota debe ser mayor que cero.',
        ]);

        $factura = Invoice::findOrFail($datos['invoice_id']);

        if ((int) $factura->branch_id !== (int) session('branch_id')) {
            abort(403, 'Esta factura pertenece a otra sucursal.');
        }

        try {
            $nota = $emisor->emitir($factura, $datos);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('notes.show', $nota)
            ->with('success', sprintf(
                '%s %s emitida. El saldo de la factura %s quedó en $%s.',
                $nota->etiqueta_tipo,
                $nota->full_number,
                $factura->displayNumber(),
                number_format((float) $factura->fresh()->pending_invoice_amount, 2, ',', '.'),
            ));
    }

    /**
     * Detalle de una nota.
     */
    public function show(CreditDebitNote $note): View
    {
        $this->verificarSucursal($note);

        return view('gestisp.notes.show', [
            'nota' => $note->load(['invoice.contract.client', 'user', 'voidedBy', 'branch']),
        ]);
    }

    /**
     * Anula una nota y revierte su efecto.
     */
    public function void(Request $request, CreditDebitNote $note, NoteIssuer $emisor): RedirectResponse
    {
        $this->verificarSucursal($note);

        $request->validate([
            'void_reason' => 'required|string|min:10|max:1000',
        ], [
            'void_reason.required' => 'Explique por qué se anula la nota.',
            'void_reason.min' => 'El motivo de la anulación debe explicarse (mínimo 10 caracteres).',
        ]);

        try {
            $emisor->anular($note, $request->input('void_reason'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('notes.show', $note)
            ->with('success', 'La nota fue anulada y su efecto sobre la factura quedó revertido.');
    }

    /**
     * Documento en PDF de la nota.
     */
    public function pdf(CreditDebitNote $note): Response
    {
        $this->verificarSucursal($note);

        $note->load(['invoice.contract.client', 'user', 'branch']);

        $pdf = PdfBranding::make('gestisp.notes.pdf', [
            'nota' => $note,
            'branch' => $note->branch,
            'pdfTitle' => $note->etiqueta_tipo,
            'pdfSubtitle' => $note->full_number,
        ]);

        return $pdf->stream('nota-' . $note->full_number . '.pdf');
    }

    private function verificarSucursal(CreditDebitNote $note): void
    {
        abort_unless(
            (int) $note->branch_id === (int) session('branch_id'),
            403,
            'Esta nota pertenece a otra sucursal.',
        );
    }
}
