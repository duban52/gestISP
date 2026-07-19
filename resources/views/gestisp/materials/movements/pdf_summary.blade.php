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
            <th style="width: 30%">Material</th>
            <th style="width: 11%" class="text-right">Cantidad</th>
            <th style="width: 12%">Unidad</th>
            <th style="width: 25%">Serial</th>
            <th style="width: 22%">Motivo</th>
        </tr>
        </thead>
        <tbody>
        @forelse($movementsCollection as $movement)
            <tr>
                <td>{{ $movement->material->name ?? '—' }}</td>
                <td class="text-right">{{ number_format($movement->quantity, 2) }}</td>
                <td>{{ $movement->unit_of_measurement }}</td>
                <td>{{ $movement->serial_number ?: '—' }}</td>
                <td>{{ $movement->reason ?: '—' }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="5">El movimiento no registra materiales.</td>
            </tr>
        @endforelse
        </tbody>
        @if($movementsCollection->isNotEmpty())
            <tfoot>
            <tr>
                <td>TOTAL DE UNIDADES</td>
                <td class="text-right">{{ number_format($movementsCollection->sum('quantity'), 2) }}</td>
                <td colspan="3"></td>
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
