{{-- ============================================================
     Reporte de una corrida de facturación (PDF)

     Soporte de la generación: qué se facturó, a quién y por cuánto.
     Va apaisado porque la tabla lleva muchas columnas.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Reporte de facturación',
    'pdfSubtitle' => 'Período ' . $run->periodo_legible,
    'orientation' => 'landscape',
    'branch' => $branch ?? null,
])

@section('meta')
    <tr>
        <td style="width: 20%">
            <span class="meta-label">Corrida</span>
            N.º {{ $run->id }}
        </td>
        <td style="width: 20%">
            <span class="meta-label">Ejecutada</span>
            {{ $run->executed_at?->format('d/m/Y h:i a') ?? '—' }}
        </td>
        <td style="width: 20%">
            <span class="meta-label">Ejecutada por</span>
            {{ $run->user?->name ?? 'Sistema' }} {{ $run->user?->last_name }}
        </td>
        <td style="width: 20%">
            <span class="meta-label">Facturas generadas</span>
            {{ $resumen['facturas'] }}
        </td>
        <td style="width: 20%">
            <span class="meta-label">Contratos omitidos</span>
            {{ $run->skipped_count }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Resumen ---------- --}}
    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Subtotal</span>
                <span class="summary-value">${{ number_format($resumen['subtotal'], 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Impuestos</span>
                <span class="summary-value">${{ number_format($resumen['impuestos'], 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Total facturado</span>
                <span class="summary-value">${{ number_format($resumen['total'], 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Saldo por cobrar</span>
                <span class="summary-value">${{ number_format($resumen['saldo_pendiente'], 2) }}</span>
            </td>
        </tr>
    </table>

    {{-- ---------- Detalle ---------- --}}
    <div class="section-title">Facturas generadas</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 8%">Factura</th>
            <th style="width: 8%">Contrato</th>
            <th style="width: 9%">Identificación</th>
            <th style="width: 15%">Cliente</th>
            <th style="width: 11%">Plan</th>
            <th style="width: 20%">Detalle facturado</th>
            <th style="width: 8%" class="text-right">Subtotal</th>
            <th style="width: 6%" class="text-right">Imp.</th>
            <th style="width: 8%" class="text-right">Total</th>
            <th style="width: 7%">Estado</th>
        </tr>
        </thead>
        <tbody>
        @forelse($facturas as $factura)
            @php
                $cliente = $factura->contract?->client;
            @endphp
            <tr>
                <td>{{ $factura->displayNumber() }}</td>
                <td>{{ $factura->contract?->contract_number ?? '—' }}</td>
                <td>{{ $cliente?->identity_number ?? '—' }}</td>
                <td>{{ trim(($cliente?->name ?? '') . ' ' . ($cliente?->last_name ?? '')) ?: '—' }}</td>
                <td>{{ $factura->contract?->plan?->name ?? '—' }}</td>
                <td>
                    @foreach($factura->invoice_items as $item)
                        {{ $item->description }}: ${{ number_format((float) $item->total, 0, ',', '.') }}@if(!$loop->last)<br>@endif
                    @endforeach
                </td>
                <td class="text-right">{{ number_format((float) $factura->subtotal, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format((float) $factura->tax, 0, ',', '.') }}</td>
                <td class="text-right strong">{{ number_format((float) $factura->total, 0, ',', '.') }}</td>
                <td>{{ $factura->status }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="10">Esta corrida no generó facturas.</td>
            </tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <td colspan="6">TOTALES ({{ $resumen['facturas'] }} facturas)</td>
            <td class="text-right">{{ number_format($resumen['subtotal'], 0, ',', '.') }}</td>
            <td class="text-right">{{ number_format($resumen['impuestos'], 0, ',', '.') }}</td>
            <td class="text-right">{{ number_format($resumen['total'], 0, ',', '.') }}</td>
            <td></td>
        </tr>
        </tfoot>
    </table>

    @if($resumen['anuladas'] > 0)
        <div class="note">
            De las facturas de esta corrida, {{ $resumen['anuladas'] }} figura(n) como
            <strong>anulada(s)</strong> al momento de generar este reporte.
        </div>
    @endif

@endsection
