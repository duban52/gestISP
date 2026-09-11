{{-- ============================================================
     Comprobante de movimiento de almacén

     Soporte del movimiento que se acaba de registrar (entrada,
     salida o traslado): detalla los materiales involucrados y
     deja espacio de firmas de quien entrega y quien recibe.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Comprobante de movimiento de almacén',
])

@php
    $movementsCollection = collect($movements);
    $first = $movementsCollection->first();
@endphp

@php
    // Falla cerrado: sin el dato del controlador, no se ensenan costos.
    $verCostos = $verCostos ?? false;
@endphp

@section('meta')
    <tr>
        <td style="width: 25%">
            <span class="meta-label">Tipo de movimiento</span>
            {{ ucfirst($first->type ?? '—') }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Almacén origen</span>
            {{ $first->warehouseOrigin->description ?? '—' }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Almacén destino</span>
            {{ $first->warehouseDestination->description ?? '—' }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Fecha</span>
            {{ $first?->created_at?->format('d/m/Y h:i a') ?? now()->format('d/m/Y h:i a') }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Resumen ---------- --}}
    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Materiales</span>
                <span class="summary-value">{{ $movementsCollection->pluck('material.id')->filter()->unique()->count() }}</span>
            </td>
            <td>
                <span class="summary-label">Registros</span>
                <span class="summary-value">{{ $movementsCollection->count() }}</span>
            </td>
            <td>
                <span class="summary-label">Unidades totales</span>
                <span class="summary-value">{{ number_format($movementsCollection->sum('quantity'), 2) }}</span>
            </td>
            <td>
                <span class="summary-label">Responsable</span>
                <span class="summary-value" style="font-size: 10px;">
                    {{ $first->user->name ?? '—' }} {{ $first->user->last_name ?? '' }}
                </span>
            </td>
        </tr>
    </table>

    {{-- ---------- Detalle de los materiales ---------- --}}
    <div class="section-title">Materiales del movimiento</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: {{ $verCostos ? '25%' : '30%' }}">Material</th>
            <th style="width: 10%" class="text-right">Cantidad</th>
            <th style="width: 10%">Unidad</th>
            @if($verCostos)
                <th style="width: 13%" class="text-right">V. unit. compra</th>
            @endif
            <th style="width: {{ $verCostos ? '22%' : '25%' }}">Serial</th>
            <th style="width: 20%">Motivo</th>
        </tr>
        </thead>
        <tbody>
        @forelse($movementsCollection as $movement)
            <tr>
                <td>{{ $movement->material->name ?? '—' }}</td>
                <td class="text-right">{{ number_format($movement->quantity, 2) }}</td>
                <td>{{ $movement->unit_of_measurement }}</td>
                @if($verCostos)
                    <td class="text-right">
                        {{ $movement->purchase_unit_value !== null
                            ? '$' . number_format($movement->purchase_unit_value, 2)
                            : '—' }}
                    </td>
                @endif
                <td>{{ $movement->serial_number ?: '—' }}</td>
                <td>{{ $movement->reason ?: '—' }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="{{ $verCostos ? 6 : 5 }}">El movimiento no registra materiales.</td>
            </tr>
        @endforelse
        </tbody>
        @if($movementsCollection->isNotEmpty())
            <tfoot>
            <tr>
                <td>TOTAL DE UNIDADES</td>
                <td class="text-right">{{ number_format($movementsCollection->sum('quantity'), 2) }}</td>
                @if($verCostos)
                    @php
                        $conValor = $movementsCollection->filter(fn ($m) => $m->purchase_unit_value !== null);
                        $invertido = $conValor->sum(fn ($m) => $m->quantity * (float) $m->purchase_unit_value);
                    @endphp
                    <td class="text-right">
                        {{ $conValor->isEmpty() ? '—' : '$' . number_format($invertido, 2) }}
                    </td>
                @endif
                <td colspan="{{ $verCostos ? 2 : 3 }}"></td>
            </tr>
            </tfoot>
        @endif
    </table>

    <div class="note">
        Este comprobante respalda el movimiento de material registrado en el sistema.
        Verifique que las cantidades y seriales relacionados coincidan físicamente
        con el material entregado y recibido antes de firmar.
    </div>

    {{-- ---------- Firmas ---------- --}}
    <table class="signature-area">
        <tr>
            <td>
                <div class="signature-line">
                    {{ $first->user->name ?? '' }} {{ $first->user->last_name ?? '' }}<br>
                    Entrega / registra el movimiento
                </div>
            </td>
            <td>
                <div class="signature-line">
                    Recibe conforme
                </div>
            </td>
        </tr>
    </table>

@endsection
