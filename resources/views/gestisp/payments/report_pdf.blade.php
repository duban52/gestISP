{{-- ============================================================
     Reporte de pagos recibidos

     Recaudo del período con totales por método de pago y el
     detalle de cada pago (cliente, factura y responsable).
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Reporte de pagos',
    'orientation' => 'landscape',
])

@php
    $total = $payments->sum('amount');
    $clientsCount = $payments->pluck('invoice.contract.client.id')->filter()->unique()->count();
@endphp

@section('meta')
    <tr>
        <td style="width: 30%">
            <span class="meta-label">Período</span>
            @if(!empty($from) && !empty($to))
                {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }}
                al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
            @else
                Todos los pagos registrados
            @endif
        </td>
        <td style="width: 22%">
            <span class="meta-label">Pagos recibidos</span>
            {{ $payments->count() }}
        </td>
        <td style="width: 22%">
            <span class="meta-label">Clientes</span>
            {{ $clientsCount }}
        </td>
        <td style="width: 26%">
            <span class="meta-label">Total recaudado</span>
            <span class="strong">${{ number_format($total, 2) }}</span>
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Totales por método de pago ---------- --}}
    @if($payments->isNotEmpty())
        <div class="section-title">Recaudo por método de pago</div>

        <table class="data">
            <thead>
            <tr>
                <th style="width: 40%">Método de pago</th>
                <th style="width: 20%" class="text-right">Pagos</th>
                <th style="width: 20%" class="text-right">Participación</th>
                <th style="width: 20%" class="text-right">Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($payments->groupBy('payment_method') as $method => $rows)
                <tr>
                    <td>{{ ucfirst($method ?: 'Sin especificar') }}</td>
                    <td class="text-right">{{ $rows->count() }}</td>
                    <td class="text-right">
                        {{ number_format($total > 0 ? $rows->sum('amount') / $total * 100 : 0, 1) }}%
                    </td>
                    <td class="text-right">${{ number_format($rows->sum('amount'), 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <td>TOTAL RECAUDADO</td>
                <td class="text-right">{{ $payments->count() }}</td>
                <td class="text-right">{{ number_format($payments->isEmpty() ? 0 : 100, 1) }}%</td>
                <td class="text-right">${{ number_format($total, 2) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif

    {{-- ---------- Detalle ---------- --}}
    <div class="section-title">Detalle de pagos</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 6%">N.°</th>
            <th style="width: 11%">Fecha</th>
            <th style="width: 12%">Documento</th>
            <th style="width: 22%">Cliente</th>
            <th style="width: 12%">Factura</th>
            <th style="width: 12%">Método</th>
            <th style="width: 14%">Recibido por</th>
            <th style="width: 11%" class="text-right">Valor</th>
        </tr>
        </thead>
        <tbody>
        @forelse($payments as $payment)
            <tr>
                <td>{{ $payment->id }}</td>
                <td class="nowrap">{{ $payment->payment_date?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $payment->invoice->contract->client->identity_number ?? '—' }}</td>
                <td>
                    {{ $payment->invoice->contract->client->name ?? '—' }}
                    {{ $payment->invoice->contract->client->last_name ?? '' }}
                </td>
                <td>{{ $payment->invoice?->displayNumber() ?? '—' }}</td>
                <td>{{ ucfirst($payment->payment_method ?: '—') }}</td>
                <td>{{ $payment->user->name ?? '—' }} {{ $payment->user->last_name ?? '' }}</td>
                <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="8">No se encontraron pagos en el período consultado.</td>
            </tr>
        @endforelse
        </tbody>
        @if($payments->isNotEmpty())
            <tfoot>
            <tr>
                <td colspan="7">TOTAL</td>
                <td class="text-right">${{ number_format($total, 2) }}</td>
            </tr>
            </tfoot>
        @endif
    </table>

@endsection
