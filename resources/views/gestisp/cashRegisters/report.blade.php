{{-- ============================================================
     Comprobante de cierre de caja

     Documento que respalda el arqueo: base inicial, movimientos
     del turno, valor esperado, valor contado y diferencia, con
     el detalle de cada transacción y espacio de firmas.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Comprobante de cierre de caja',
    'pdfSubtitle' => 'Caja N.° ' . $cashRegister->id,
    'branch' => $cashRegister->branch ?? null,
])

@php
    $transactions = $cashRegister->transactions;
    $income = $transactions->where('transaction_type', 'Ingreso');
    $expenses = $transactions->where('transaction_type', 'Egreso');
    $difference = (float) ($cashRegister->difference ?? 0);
@endphp

@section('meta')
    <tr>
        <td style="width: 25%">
            <span class="meta-label">Responsable</span>
            {{ $cashRegister->user->name ?? '—' }} {{ $cashRegister->user->last_name ?? '' }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Apertura</span>
            {{ $cashRegister->opened_at?->format('d/m/Y h:i a') ?? '—' }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Cierre</span>
            {{ $cashRegister->closed_at?->format('d/m/Y h:i a') ?? 'Caja abierta' }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Movimientos</span>
            {{ $transactions->count() }} registro(s)
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Resumen del arqueo ---------- --}}
    <div class="section-title">Resumen del arqueo</div>

    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Base inicial</span>
                <span class="summary-value">${{ number_format($cashRegister->initial_amount, 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Total ingresos</span>
                <span class="summary-value">${{ number_format($cashRegister->total_income ?? 0, 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Total egresos</span>
                <span class="summary-value">${{ number_format($cashRegister->total_expenses ?? 0, 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Esperado en caja</span>
                <span class="summary-value">${{ number_format($cashRegister->expected_amount ?? 0, 2) }}</span>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 34%">Concepto</th>
            <th style="width: 33%" class="text-right">Valor</th>
            <th style="width: 33%">Observación</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>Valor esperado (base + ingresos − egresos)</td>
            <td class="text-right">${{ number_format($cashRegister->expected_amount ?? 0, 2) }}</td>
            <td class="muted">Calculado por el sistema</td>
        </tr>
        <tr>
            <td>Valor contado al cierre</td>
            <td class="text-right">
                {{ $cashRegister->final_amount !== null ? '$' . number_format($cashRegister->final_amount, 2) : '—' }}
            </td>
            <td class="muted">Declarado por el responsable</td>
        </tr>
        </tbody>
        <tfoot>
        <tr>
            <td>DIFERENCIA</td>
            <td class="text-right {{ $difference < 0 ? 'negative' : ($difference > 0 ? 'positive' : '') }}">
                ${{ number_format($difference, 2) }}
            </td>
            <td>
                @if($difference < 0)
                    Faltante respecto a lo esperado
                @elseif($difference > 0)
                    Sobrante respecto a lo esperado
                @else
                    Caja cuadrada
                @endif
            </td>
        </tr>
        </tfoot>
    </table>

    {{-- ---------- Ingresos por método de pago ---------- --}}
    @if($income->isNotEmpty())
        <div class="section-title">Ingresos por método de pago</div>

        <table class="data">
            <thead>
            <tr>
                <th style="width: 40%">Método de pago</th>
                <th style="width: 25%" class="text-right">Transacciones</th>
                <th style="width: 35%" class="text-right">Total recaudado</th>
            </tr>
            </thead>
            <tbody>
            @foreach($income->groupBy('payment_method') as $method => $rows)
                <tr>
                    <td>{{ ucfirst($method ?: 'Sin especificar') }}</td>
                    <td class="text-right">{{ $rows->count() }}</td>
                    <td class="text-right">${{ number_format($rows->sum('amount'), 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <td>TOTAL INGRESOS</td>
                <td class="text-right">{{ $income->count() }}</td>
                <td class="text-right">${{ number_format($income->sum('amount'), 2) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif

    {{-- ---------- Detalle de movimientos ---------- --}}
    <div class="section-title">Detalle de movimientos del turno</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 6%">N.°</th>
            <th style="width: 18%">Fecha y hora</th>
            <th style="width: 10%">Tipo</th>
            <th style="width: 13%">Método</th>
            <th style="width: 38%">Descripción</th>
            <th style="width: 15%" class="text-right">Valor</th>
        </tr>
        </thead>
        <tbody>
        @forelse($transactions->sortBy('created_at') as $transaction)
            <tr>
                <td>{{ $transaction->id }}</td>
                <td class="nowrap">{{ $transaction->created_at->format('d/m/Y h:i a') }}</td>
                <td>{{ $transaction->transaction_type }}</td>
                <td>{{ ucfirst($transaction->payment_method ?: '—') }}</td>
                <td>{{ $transaction->description }}</td>
                <td class="text-right {{ $transaction->transaction_type === 'Egreso' ? 'negative' : '' }}">
                    {{ $transaction->transaction_type === 'Egreso' ? '−' : '' }}${{ number_format($transaction->amount, 2) }}
                </td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="6">No se registraron movimientos en este turno.</td>
            </tr>
        @endforelse
        </tbody>
        @if($transactions->isNotEmpty())
            <tfoot>
            <tr>
                <td colspan="5">SALDO NETO DEL TURNO (ingresos − egresos)</td>
                <td class="text-right">
                    ${{ number_format($income->sum('amount') - $expenses->sum('amount'), 2) }}
                </td>
            </tr>
            </tfoot>
        @endif
    </table>

    {{-- ---------- Observaciones ---------- --}}
    @if($cashRegister->opening_notes || $cashRegister->closing_notes)
        <div class="section-title">Observaciones</div>
        <table class="detail">
            @if($cashRegister->opening_notes)
                <tr>
                    <td class="label">Apertura</td>
                    <td>{{ $cashRegister->opening_notes }}</td>
                </tr>
            @endif
            @if($cashRegister->closing_notes)
                <tr>
                    <td class="label">Cierre</td>
                    <td>{{ $cashRegister->closing_notes }}</td>
                </tr>
            @endif
        </table>
    @endif

    {{-- ---------- Firmas ---------- --}}
    <table class="signature-area">
        <tr>
            <td>
                <div class="signature-line">
                    {{ $cashRegister->user->name ?? '' }} {{ $cashRegister->user->last_name ?? '' }}<br>
                    Responsable de la caja
                </div>
            </td>
            <td>
                <div class="signature-line">
                    Recibido y verificado por
                </div>
            </td>
        </tr>
    </table>

@endsection
