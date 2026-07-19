{{-- ============================================================
     Inventario de almacén

     Existencias actuales del almacén: cantidad por material y
     los seriales registrados de los equipos.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Inventario de almacén',
    'pdfSubtitle' => $warehouse->description,
])

@php
    $items = collect($inventoriesData);
    // Materiales que se controlan por serial (tienen SNs listados)
    $withSerials = $items->filter(fn ($i) => !empty($i['sns']));
@endphp

@section('meta')
    <tr>
        <td style="width: 30%">
            <span class="meta-label">Almacén</span>
            {{ $warehouse->description }}
        </td>
        <td style="width: 23%">
            <span class="meta-label">Materiales distintos</span>
            {{ $items->count() }}
        </td>
        <td style="width: 23%">
            <span class="meta-label">Unidades totales</span>
            {{ number_format($items->sum('quantity'), 2) }}
        </td>
        <td style="width: 24%">
            <span class="meta-label">Corte</span>
            {{ now()->format('d/m/Y h:i a') }}
        </td>
    </tr>
@endsection

@section('content')

    <div class="section-title">Existencias</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 32%">Material</th>
            <th style="width: 12%" class="text-right">Cantidad</th>
            <th style="width: 13%">Unidad</th>
            <th style="width: 43%">Seriales registrados</th>
        </tr>
        </thead>
        <tbody>
        @forelse($items as $inventory)
            <tr>
                <td>{{ $inventory['material'] }}</td>
                <td class="text-right strong">{{ number_format($inventory['quantity'], 2) }}</td>
                <td>{{ $inventory['unit_of_measurement'] }}</td>
                {{-- Los seriales pueden ser una lista larga: la
                     celda quiebra el texto para no desbordar --}}
                <td>{{ $inventory['sns'] ?: '—' }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="4">El almacén no tiene existencias registradas.</td>
            </tr>
        @endforelse
        </tbody>
        @if($items->isNotEmpty())
            <tfoot>
            <tr>
                <td>TOTAL</td>
                <td class="text-right">{{ number_format($items->sum('quantity'), 2) }}</td>
                <td colspan="2">
                    {{ $items->count() }} material(es) · {{ $withSerials->count() }} con control de serial
                </td>
            </tr>
            </tfoot>
        @endif
    </table>

    <div class="note">
        Documento de existencias al corte indicado. Las cantidades reflejan los
        movimientos registrados en el sistema hasta ese momento; cualquier
        diferencia física debe registrarse como movimiento de ajuste.
    </div>

    {{-- ---------- Firmas ---------- --}}
    <table class="signature-area">
        <tr>
            <td>
                <div class="signature-line">
                    @auth{{ auth()->user()->name }} {{ auth()->user()->last_name }}@endauth<br>
                    Generado por
                </div>
            </td>
            <td>
                <div class="signature-line">
                    Verificado por (responsable de almacén)
                </div>
            </td>
        </tr>
    </table>

@endsection
