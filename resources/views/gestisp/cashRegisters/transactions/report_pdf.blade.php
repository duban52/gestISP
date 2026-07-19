{{-- ============================================================
     Reporte de movimientos de caja

     Historial de ingresos y egresos del período consultado, con
     totales por tipo y por método de pago.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Reporte de movimientos de caja',
    'orientation' => 'landscape',
])

@php
    $income = $transactions->where('transaction_type', 'Ingreso');
    $expenses = $transactions->where('transaction_type', 'Egreso');
    $net = $income->sum('amount') - $expenses->sum('amount');
@endphp

@section('meta')
    <tr>
        <td style="width: 30%">
            <span class="meta-label">Período consultado</span>
            @if(!empty($from) && !empty($to))
                {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }}
                al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
            @else
                Todos los movimientos registrados
            @endif
        </td>
        <td style="width: 20%">
            <span class="meta-label">Movimientos</span>
            {{ $transactions->count() }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Total ingresos</span>
            ${{ number_format($income->sum('amount'), 2) }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Total egresos</span>
            ${{ number_format($expenses->sum('amount'), 2) }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Resumen ---------- --}}
    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Ingresos</span>
                <span class="summary-value">${{ number_format($income->sum('amount'), 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Egresos</span>
                <span class="summary-value">${{ number_format($expenses->sum('amount'), 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Saldo neto</span>
                <span class="summary-value">${{ number_format($net, 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Total de movimientos</span>
                <span class="summary-value">{{ $transactions->count() }}</span>
            </td>
        </tr>
    </table>

    {{-- ---------- Totales por método de pago ---------- --}}
    @if($income->isNotEmpty())
        <div class="section-title">Ingresos por método de pago</div>

        <table class="data">
            <thead>
            <tr>
                <th style="width: 50%">Método de pago</th>
                <th style="width: 20%" class="text-right">Transacciones</th>
                <th style="width: 30%" class="text-right">Total</th>
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
        </table>
    @endif

    {{-- ---------- Detalle ---------- --}}
    <div class="section-title">Detalle de movimientos</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 5%">N.°</th>
            <th style="width: 12%">Fecha y hora</th>
            <th style="width: 9%">Tipo</th>
            <th style="width: 11%">Método</th>
            <th style="width: 12%">Caja</th>
            <th style="width: 16%">Registrado por</th>
            <th style="width: 23%">Descripción</th>
            <th style="width: 12%" class="text-right">Valor</th>
        </tr>
        </thead>
        <tbody>
        @forelse($transactions as $transaction)
            <tr>
                <td>{{ $transaction->id }}</td>
                <td class="nowrap">{{ $transaction->created_at->format('d/m/Y h:i a') }}</td>
                <td>{{ $transaction->transaction_type }}</td>
                <td>{{ ucfirst($transaction->payment_method ?: '—') }}</td>
                <td>Caja #{{ $transaction->cash_register_id ?? '—' }}</td>
                <td>{{ $transaction->user->name ?? '—' }} {{ $transaction->user->last_name ?? '' }}</td>
                <td>{{ $transaction->description }}</td>
                <td class="text-right {{ $transaction->transaction_type === 'Egreso' ? 'negative' : '' }}">
                    {{ $transaction->transaction_type === 'Egreso' ? '−' : '' }}${{ number_format($transaction->amount, 2) }}
                </td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="8">No se encontraron movimientos en el período consultado.</td>
            </tr>
        @endforelse
        </tbody>
        @if($transactions->isNotEmpty())
            <tfoot>
            <tr>
                <td colspan="7">SALDO NETO DEL PERÍODO (ingresos − egresos)</td>
                <td class="text-right">${{ number_format($net, 2) }}</td>
            </tr>
            </tfoot>
        @endif
    </table>

@endsection
