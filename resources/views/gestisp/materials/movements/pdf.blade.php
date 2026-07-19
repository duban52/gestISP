{{-- ============================================================
     Historial de movimientos de almacén

     Trazabilidad de entradas, salidas y traslados de material
     del período consultado, con resumen por tipo de movimiento.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Historial de movimientos de almacén',
    'orientation' => 'landscape',
])

@section('meta')
    <tr>
        <td style="width: 30%">
            <span class="meta-label">Período</span>
            @if(!empty($from) && !empty($to))
                {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }}
                al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
            @else
                Todos los movimientos registrados
            @endif
        </td>
        <td style="width: 23%">
            <span class="meta-label">Movimientos</span>
            {{ $movements->count() }}
        </td>
        <td style="width: 23%">
            <span class="meta-label">Materiales distintos</span>
            {{ $movements->pluck('material.id')->filter()->unique()->count() }}
        </td>
        <td style="width: 24%">
            <span class="meta-label">Responsables</span>
            {{ $movements->pluck('user.id')->filter()->unique()->count() }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Resumen por tipo de movimiento ---------- --}}
    @if($movements->isNotEmpty())
        <div class="section-title">Resumen por tipo de movimiento</div>

        <table class="data">
            <thead>
            <tr>
                <th style="width: 40%">Tipo de movimiento</th>
                <th style="width: 30%" class="text-right">Registros</th>
                <th style="width: 30%" class="text-right">Unidades movidas</th>
            </tr>
            </thead>
            <tbody>
            @foreach($movements->groupBy('type') as $type => $rows)
                <tr>
                    <td>{{ ucfirst($type ?: 'Sin especificar') }}</td>
                    <td class="text-right">{{ $rows->count() }}</td>
                    <td class="text-right">{{ number_format($rows->sum('quantity'), 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <td>TOTAL</td>
                <td class="text-right">{{ $movements->count() }}</td>
                <td class="text-right">{{ number_format($movements->sum('quantity'), 2) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif

    {{-- ---------- Detalle ---------- --}}
    <div class="section-title">Detalle de movimientos</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 11%">Fecha</th>
            <th style="width: 9%">Tipo</th>
            <th style="width: 12%">Origen</th>
            <th style="width: 12%">Destino</th>
            <th style="width: 16%">Material</th>
            <th style="width: 7%" class="text-right">Cant.</th>
            <th style="width: 7%">Unidad</th>
            <th style="width: 12%">Serial</th>
            <th style="width: 14%">Motivo / responsable</th>
        </tr>
        </thead>
        <tbody>
        @forelse($movements as $movement)
            <tr>
                <td class="nowrap">{{ $movement->created_at->format('d/m/Y h:i a') }}</td>
                <td>{{ ucfirst($movement->type) }}</td>
                <td>{{ $movement->warehouseOrigin->description ?? '—' }}</td>
                <td>{{ $movement->warehouseDestination->description ?? '—' }}</td>
                <td>{{ $movement->material->name ?? '—' }}</td>
                <td class="text-right">{{ number_format($movement->quantity, 2) }}</td>
                <td>{{ $movement->unit_of_measurement }}</td>
                <td>{{ $movement->serial_number ?: '—' }}</td>
                <td>
                    {{ $movement->reason ?: '—' }}
                    <br>
                    <span class="muted">
                        {{ $movement->user->name ?? '—' }} {{ $movement->user->last_name ?? '' }}
                    </span>
                </td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="9">No se encontraron movimientos en el período consultado.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

@endsection
