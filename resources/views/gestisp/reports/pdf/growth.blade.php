@extends('gestisp.pdf.layout')

@section('meta')
    <tr>
        <td><span class="meta-label">Período</span><br>{{ $period->etiquetaRango() }}</td>
        <td><span class="meta-label">Agrupación</span><br>{{ $period->granularity->label() }}</td>
        <td><span class="meta-label">Alcance</span><br>{{ $ambito }}</td>
    </tr>
@endsection

@section('content')

    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Contratos vigentes</span>
                <span class="summary-value">{{ number_format($resumen['vigentes']) }}</span>
            </td>
            <td>
                <span class="summary-label">Altas</span>
                <span class="summary-value positive">{{ number_format($resumen['altas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Bajas</span>
                <span class="summary-value negative">{{ number_format($resumen['bajas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Neto</span>
                <span class="summary-value">{{ ($resumen['neto'] >= 0 ? '+' : '') . number_format($resumen['neto']) }}</span>
            </td>
        </tr>
    </table>

    <div class="section-title">Evolución por período</div>
    <table class="data">
        <colgroup>
            <col style="width: 28%;"><col style="width: 18%;">
            <col style="width: 18%;"><col style="width: 18%;"><col style="width: 18%;">
        </colgroup>
        <thead>
        <tr>
            <th>Período</th>
            <th class="text-right">Altas</th>
            <th class="text-right">Bajas</th>
            <th class="text-right">Neto</th>
            <th class="text-right">Base acumulada</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($series['labels'] as $i => $etiqueta)
            <tr>
                <td>{{ $etiqueta }}</td>
                <td class="text-right">{{ number_format($series['altas'][$i]) }}</td>
                <td class="text-right">{{ number_format($series['bajas'][$i]) }}</td>
                <td class="text-right {{ $series['neto'][$i] >= 0 ? 'positive' : 'negative' }}">
                    {{ ($series['neto'][$i] >= 0 ? '+' : '') . number_format($series['neto'][$i]) }}
                </td>
                <td class="text-right strong">{{ number_format($series['base'][$i]) }}</td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="5">Sin movimiento en el período.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <td>Total</td>
            <td class="text-right">{{ number_format($series['altas']->sum()) }}</td>
            <td class="text-right">{{ number_format($series['bajas']->sum()) }}</td>
            <td class="text-right">{{ number_format($series['neto']->sum()) }}</td>
            <td class="text-right">{{ number_format($series['base']->last() ?? 0) }}</td>
        </tr>
        </tfoot>
    </table>

    <div class="section-title">Distribución por estado</div>
    @include('gestisp.reports.pdf.partials.bars', [
        'filas' => $estados->map(fn ($e) => [
            'etiqueta' => $e['etiqueta'],
            'valor' => $e['total'],
            'color' => $e['color'],
        ])->all(),
    ])

    <div class="section-title">Contratos e ingreso por plan</div>
    @php $mrrTotal = $planes->sum('mrr'); @endphp
    <table class="data">
        <colgroup>
            <col style="width: 34%;"><col style="width: 14%;">
            <col style="width: 18%;"><col style="width: 20%;"><col style="width: 14%;">
        </colgroup>
        <thead>
        <tr>
            <th>Plan</th>
            <th class="text-right">Contratos</th>
            <th class="text-right">Precio</th>
            <th class="text-right">Ingreso mensual</th>
            <th class="text-right">Participación</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($planes as $p)
            <tr>
                <td>{{ $p['plan'] }}</td>
                <td class="text-right">{{ number_format($p['contratos']) }}</td>
                <td class="text-right">${{ number_format($p['precio'], 0, ',', '.') }}</td>
                <td class="text-right strong">${{ number_format($p['mrr'], 0, ',', '.') }}</td>
                <td class="text-right">{{ $mrrTotal > 0 ? number_format($p['mrr'] / $mrrTotal * 100, 1) : '0,0' }}%</td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="5">No hay contratos vigentes con plan asignado.</td></tr>
        @endforelse
        </tbody>
        @if ($planes->isNotEmpty())
            <tfoot>
            <tr>
                <td>Total</td>
                <td class="text-right">{{ number_format($planes->sum('contratos')) }}</td>
                <td></td>
                <td class="text-right">${{ number_format($mrrTotal, 0, ',', '.') }}</td>
                <td class="text-right">100,0%</td>
            </tr>
            </tfoot>
        @endif
    </table>

    <div class="note">
        El alta usa la fecha de activación del contrato y, si no está registrada, la de creación.
        La baja se aproxima con la última modificación del contrato retirado: el sistema no guarda
        una fecha de retiro propia, así que editar un contrato ya retirado desplaza su baja a esa fecha.
        La base acumulada es una reconstrucción a partir del neto y sirve para ver la tendencia,
        no para auditar un día concreto del pasado.
    </div>
@endsection
