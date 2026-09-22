{{-- ============================================================
     Historial de movimientos de almacén

     Una fila por OPERACIÓN, no por renglón: un equipo con serial
     genera un renglón por serial, y una entrada de mil ONT llenaba
     mil filas del informe. Los seriales están en el comprobante de
     cada movimiento y en el Excel.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Historial de movimientos de almacén',
    'orientation' => 'landscape',
])

@php
    // Falla cerrado: sin el dato del controlador, no se ensenan costos.
    $verCostos = $verCostos ?? false;
@endphp

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
            {{ $operaciones->count() }}
        </td>
        <td style="width: 23%">
            <span class="meta-label">Renglones</span>
            {{ $operaciones->sum('renglones') }}
        </td>
        <td style="width: 24%">
            <span class="meta-label">Responsables</span>
            {{ $operaciones->pluck('user_id')->filter()->unique()->count() }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Resumen por tipo de movimiento ---------- --}}
    @if($operaciones->isNotEmpty())
        <div class="section-title">Resumen por tipo de movimiento</div>

        <table class="data">
            <thead>
            <tr>
                <th style="width: {{ $verCostos ? '25%' : '34%' }}">Tipo de movimiento</th>
                <th style="width: 19%" class="text-right">Movimientos</th>
                <th style="width: 19%" class="text-right">Renglones</th>
                <th style="width: 19%" class="text-right">Unidades movidas</th>
                @if($verCostos)
                    {{-- Solo tiene sentido en las ENTRADAS: es lo que se
                         pagó por lo que ingresó. En traslados y salidas
                         no hay compra, así que sale «—» en vez de un
                         cero que parecería un dato. --}}
                    <th style="width: 18%" class="text-right">Valor de compra</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach($operaciones->groupBy('type') as $type => $grupo)
                <tr>
                    <td>{{ ucfirst($type ?: 'Sin especificar') }}</td>
                    <td class="text-right">{{ $grupo->count() }}</td>
                    <td class="text-right">{{ $grupo->sum('renglones') }}</td>
                    <td class="text-right">{{ number_format($grupo->sum('unidades'), 2) }}</td>
                    @if($verCostos)
                        @php
                            $invertido = $grupo->sum('invertido');
                            // Hay renglones sin precio: el total no es todo lo movido.
                            $incompleto = $grupo->sum('con_valor') < $grupo->sum('renglones');
                        @endphp
                        <td class="text-right">
                            {{ $invertido <= 0 ? '—' : '$' . number_format($invertido, 2) }}
                            @if($invertido > 0 && $incompleto)*@endif
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <td>TOTAL</td>
                <td class="text-right">{{ $operaciones->count() }}</td>
                <td class="text-right">{{ $operaciones->sum('renglones') }}</td>
                <td class="text-right">{{ number_format($operaciones->sum('unidades'), 2) }}</td>
                @if($verCostos)
                    <td class="text-right">
                        {{ $operaciones->sum('invertido') <= 0 ? '—' : '$' . number_format($operaciones->sum('invertido'), 2) }}
                    </td>
                @endif
            </tr>
            </tfoot>
        </table>

        @if($verCostos)
            <p class="muted" style="margin-top: 4px;">
                * Hay renglones sin valor de compra registrado: el total no incluye lo que se movió sin precio.
            </p>
        @endif
    @endif

    {{-- ---------- Los movimientos ---------- --}}
    <div class="section-title">Movimientos</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 6%">N.º</th>
            <th style="width: 11%">Fecha</th>
            <th style="width: 9%">Tipo</th>
            <th style="width: 12%">Origen</th>
            <th style="width: 12%">Destino</th>
            <th style="width: {{ $verCostos ? '22%' : '26%' }}">Qué se movió</th>
            <th style="width: 7%" class="text-right">Unidades</th>
            @if($verCostos)
                <th style="width: 8%" class="text-right">Compra</th>
            @endif
            <th style="width: 13%">Motivo / compra / responsable</th>
        </tr>
        </thead>
        <tbody>
        @forelse($operaciones as $operacion)
            <tr>
                <td>{{ $operacion->operacion }}</td>
                <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($operacion->fecha)->format('d/m/Y h:i a') }}</td>
                <td>{{ ucfirst($operacion->type) }}</td>
                <td>{{ $operacion->warehouseOrigin->description ?? '—' }}</td>
                <td>{{ $operacion->warehouseDestination->description ?? '—' }}</td>
                <td>
                    @foreach(($materialesPorOperacion[$operacion->operacion] ?? collect()) as $linea)
                        {{ $linea->material?->name ?? '—' }}:
                        {{ rtrim(rtrim(number_format($linea->cantidad, 2), '0'), '.') }}
                        {{ $linea->unit_of_measurement }}@if($linea->seriales > 0) ({{ $linea->seriales }} con serial)@endif
                        <br>
                    @endforeach
                </td>
                <td class="text-right">{{ rtrim(rtrim(number_format($operacion->unidades, 2), '0'), '.') }}</td>
                @if($verCostos)
                    <td class="text-right">
                        {{ $operacion->invertido > 0 ? '$' . number_format($operacion->invertido, 2) : '—' }}
                    </td>
                @endif
                <td>
                    {{ $operacion->reason ?: '—' }}
                    @if($operacion->supplier || $operacion->invoice_number)
                        <br>
                        <span class="muted">
                            {{ $operacion->supplier ?: '—' }}
                            @if($operacion->invoice_number) · Fact. {{ $operacion->invoice_number }} @endif
                        </span>
                    @endif
                    <br>
                    <span class="muted">
                        {{ $operacion->user->name ?? '—' }} {{ $operacion->user->last_name ?? '' }}
                    </span>
                </td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="{{ $verCostos ? 9 : 8 }}">No se encontraron movimientos en el período consultado.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

@endsection
