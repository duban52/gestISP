<?php

namespace App\Http\Controllers;

use App\Billing\Services\CreditBalanceService;
use App\Billing\Services\PaymentRegistrar;
use App\Models\Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Pagos por adelantado (anticipos) y saldo a favor del cliente.
 *
 * Permite recibir dinero cuando el cliente quiere adelantar varios
 * meses de servicio. El cobro entra a la caja como cualquier otro y
 * queda a favor del contrato: se abona a lo que deba en ese momento y
 * el resto se va consumiendo con las facturas de los meses
 * siguientes, sin que nadie tenga que acordarse de aplicarlo.
 *
 * Se protege con el permiso de cobrar (payments.create): quien puede
 * recibir un pago puede recibir un anticipo.
 */
class AdvancePaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:payments.create')->only('create', 'store');
        $this->middleware('check.permission:contracts.show')->only('movimientos');
    }

    /**
     * Formulario para recibir un anticipo del contrato.
     */
    public function create(Contract $contract, CreditBalanceService $saldos): View
    {
        $this->verificarSucursal($contract);

        $contract->load(['client', 'plan']);

        return view('gestisp.payments.advance', [
            'contrato' => $contract,
            'saldoAFavor' => $saldos->saldo($contract),
            'deuda' => $contract->outstandingBalance(),
            'mensualidad' => $this->valorMensual($contract),
        ]);
    }

    /**
     * Registra el anticipo.
     */
    public function store(Request $request, Contract $contract, PaymentRegistrar $registrar): RedirectResponse
    {
        $this->verificarSucursal($contract);

        $datos = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|max:40',
            'reference_number' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:500',
        ], [
            'amount.min' => 'El valor del anticipo debe ser mayor que cero.',
            'payment_method.required' => 'Indique el medio de pago.',
        ]);

        try {
            $resultado = $registrar->registerAdvance(
                array_merge($datos, ['contract_id' => $contract->id]),
                Auth::id(),
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $mensaje = sprintf('Anticipo de $%s recibido.', number_format((float) $datos['amount'], 2, ',', '.'));

        if ($resultado['aplicado'] > 0) {
            $mensaje .= sprintf(
                ' Se abonaron $%s a las facturas pendientes.',
                number_format($resultado['aplicado'], 2, ',', '.'),
            );
        }

        if ($resultado['saldo_a_favor'] > 0) {
            $mensaje .= sprintf(
                ' Quedan $%s a favor del cliente para las próximas facturas.',
                number_format($resultado['saldo_a_favor'], 2, ',', '.'),
            );
        }

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', $mensaje);
    }

    /**
     * Historial del saldo a favor: de dónde salió y en qué se usó.
     */
    public function movimientos(Contract $contract, CreditBalanceService $saldos): View
    {
        $this->verificarSucursal($contract);

        return view('gestisp.payments.credit_movements', [
            'contrato' => $contract->load('client'),
            'movimientos' => $contract->accountCredits()->with(['invoice', 'note', 'user'])->get(),
            'saldoAFavor' => $saldos->saldo($contract),
        ]);
    }

    /**
     * Valor aproximado de una mensualidad, para sugerir cuánto
     * representa el anticipo en meses de servicio.
     */
    private function valorMensual(Contract $contract): float
    {
        $ultima = $contract->invoices()
            ->where('type', 'Mensualidad')
            ->latest('id')
            ->first();

        if ($ultima) {
            return (float) $ultima->total;
        }

        // Sin facturas todavía: se estima con los servicios del plan
        return (float) ($contract->plan?->services?->sum(fn ($s) => (float) $s->base_price) ?? 0);
    }

    private function verificarSucursal(Contract $contract): void
    {
        abort_unless(
            (int) $contract->branch_id === (int) session('branch_id'),
            403,
            'Este contrato pertenece a otra sucursal.',
        );
    }
}
