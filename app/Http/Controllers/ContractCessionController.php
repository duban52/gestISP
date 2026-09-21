<?php

namespace App\Http\Controllers;

use App\Models\AffinityGroup;
use App\Models\Client;
use App\Models\Contract;
use App\Services\ContractCessionService;
use App\Tenancy\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * La pantalla de ceder un contrato.
 *
 * Tiene su propio permiso (`contracts.cede`) y no cuelga de «editar»:
 * ceder cierra el contrato del cedente, le emite sus facturas de cierre
 * y le pasa los equipos a otra persona. Quien puede corregir una
 * dirección no tiene por qué poder hacer eso.
 */
class ContractCessionController extends Controller
{
    public function __construct(private readonly ContractCessionService $cesiones)
    {
        $this->middleware('auth');
        $this->middleware('check.permission:contracts.cede');
    }

    /** El formulario, con lo que impide ceder y lo que va a pasar. */
    public function create(Contract $contract): View
    {
        $this->exigirSucursal($contract);

        $contract->loadMissing('client', 'plan', 'napPort.napBox', 'affinityGroup');

        return view('gestisp.contracts.ceder', [
            'contract' => $contract,
            'revision' => $this->cesiones->revisar($contract),
            'grupos' => AffinityGroup::where('active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Clientes a los que se puede ceder, para el buscador del formulario.
     *
     * Solo de la sucursal del contrato —la corrida mensual elige por la
     * sucursal del cliente— y nunca el titular actual.
     */
    public function clientes(Request $request, Contract $contract): JsonResponse
    {
        $this->exigirSucursal($contract);

        $termino = trim((string) $request->query('q'));

        if (mb_strlen($termino) < 3) {
            return response()->json(['results' => []]);
        }

        $like = "%{$termino}%";

        $clientes = Client::where('branch_id', $contract->branch_id)
            ->where('id', '!=', $contract->client_id)
            ->where(fn ($q) => $q->where('identity_number', 'like', $like)
                ->orWhereRaw("CONCAT(name, ' ', last_name) LIKE ?", [$like]))
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'identity_number', 'name', 'last_name']);

        return response()->json([
            'results' => $clientes->map(fn (Client $c) => [
                'id' => $c->id,
                'text' => sprintf('%s — %s', $c->identity_number, trim($c->name . ' ' . $c->last_name)),
            ]),
        ]);
    }

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $this->exigirSucursal($contract);

        $datos = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'reason' => 'required|string|max:500',
            'affinity_group_id' => ['nullable', 'integer', Rule::exists('affinity_groups', 'id')],
            // La casilla es la confirmación de que se leyó lo que va a
            // pasar: se cierra el contrato y se le factura al cedente.
            'confirmar' => 'accepted',
        ], [
            'client_id.required' => 'Elija a quién se le cede el contrato.',
            'reason.required' => 'Indique el motivo de la cesión.',
            'confirmar.accepted' => 'Confirme que leyó lo que va a pasar al ceder.',
        ]);

        $cesionario = Client::findOrFail($datos['client_id']);

        try {
            $cesion = $this->cesiones->ceder($contract, $cesionario, $datos, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Se lleva al contrato NUEVO: es el que sigue vivo, y en su
        // ficha queda el enlace al anterior.
        return redirect()
            ->route('contracts.show', $cesion->to_contract_id)
            ->with('success', 'Contrato cedido. ' . ucfirst(implode('; ', $cesion->summary['hechos'] ?? [])) . '.')
            ->with('cesion_avisos', $cesion->summary['avisos'] ?? []);
    }

    private function exigirSucursal(Contract $contract): void
    {
        abort_unless(app(CurrentContext::class)->permiteSucursal($contract->branch_id), 403);
    }
}
