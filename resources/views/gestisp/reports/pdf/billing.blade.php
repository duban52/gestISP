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
                <span class="summary-label">Facturado</span>
                <span class="summary-value">${{ number_format($resumen['facturado'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="summary-label">Recaudado</span>
                <span class="summary-value positive">${{ number_format($resumen['recaudado'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="summary-label">Tasa de recaudo</span>
                <span class="summary-value">{{ number_format($resumen['tasa_recaudo'], 1) }}%</span>
            </td>
            <td>
                <span class="summary-label">Cartera vencida</span>
                <span class="summary-value negative">${{ number_format($resumen['cartera_vencida'], 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <div class="section-title">Facturado y recaudado por período</div>
    <table class="data">
        <colgroup>
            <col style="width: 28%;"><col style="width: 24%;">
            <col style="width: 24%;"><col style="width: 24%;">
        </colgroup>
        <thead>
        <tr>
            <th>Período</th>
            <th class="text-right">Facturado</th>
            <th class="text-right">Recaudado</th>
            <th class="text-right">Diferencia</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($series['labels'] as $i => $etiqueta)
            @php
                $dif = $series['recaudado'][$i] - $series['facturado'][$i];
                // Un período sin movimiento no es un resultado
                // positivo: se muestra neutro, sin signo ni color
                $sinMovimiento = $dif == 0;
            @endphp
            <tr>
                <td>{{ $etiqueta }}</td>
                <td class="text-right">${{ number_format($series['facturado'][$i], 0, ',', '.') }}</td>
                <td class="text-right">${{ number_format($series['recaudado'][$i], 0, ',', '.') }}</td>
                <td class="text-right {{ $sinMovimiento ? 'muted' : ($dif > 0 ? 'positive' : 'negative') }}">
                    @if ($sinMovimiento)
                        $0
                    @else
                        {{ $dif > 0 ? '+' : '−' }}${{ number_format(abs($dif), 0, ',', '.') }}
                    @endif
                </td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="4">Sin movimiento en el período.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <td>Total</td>
            <td class="text-right">${{ number_format($series['facturado']->sum(), 0, ',', '.') }}</td>
            <td class="text-right">${{ number_format($series['recaudado']->sum(), 0, ',', '.') }}</td>
            <td class="text-right">
                ${{ number_format($series['recaudado']->sum() - $series['facturado']->sum(), 0, ',', '.') }}
            </td>
        </tr>
        </tfoot>
    </table>

    <div class="section-title">Cartera por antigüedad</div>
    @include('gestisp.reports.pdf.partials.bars', [
        'filas' => $cartera->map(fn ($c) => [
            'etiqueta' => $c['etiqueta'] . ' (' . $c['facturas'] . ')',
            'valor' => $c['total'],
            'color' => $c['color'],
        ])->all(),
        'dinero' => true,
    ])

    <div class="section-title">Recaudo por método de pago</div>
    <table class="data">
        <colgroup><col style="width: 46%;"><col style="width: 24%;"><col style="width: 30%;"></colgroup>
        <thead>
        <tr>
            <th>Método</th>
            <th class="text-right">Operaciones</th>
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($metodos as $m)
            <tr>
                <td>{{ $m['etiqueta'] }}</td>
                <td class="text-right">{{ number_format($m['operaciones']) }}</td>
                <td class="text-right strong">${{ number_format($m['total'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="3">Sin pagos registrados en el período.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Mayores saldos pendientes</div>
    <table class="data">
        <colgroup>
            <col style="width: 28%;"><col style="width: 14%;"><col style="width: 10%;">
            <col style="width: 16%;"><col style="width: 10%;"><col style="width: 10%;"><col style="width: 12%;">
        </colgroup>
        <thead>
        <tr>
            <th>Cliente</th>
            <th>Documento</th>
            <th class="text-center">Contrato</th>
            <th>Estado</th>
            <th class="text-right">Facturas</th>
            <th class="text-right">Mora</th>
            <th class="text-right">Saldo</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($deudores as $d)
            <tr>
                <td>{{ $d['cliente'] }}</td>
                <td>{{ $d['documento'] }}</td>
                <td class="text-center">#{{ $d['contrato'] }}</td>
                <td>{{ $d['estado'] }}</td>
                <td class="text-right">{{ $d['facturas'] }}</td>
                <td class="text-right {{ $d['dias'] > 90 ? 'negative' : '' }}">{{ $d['dias'] }} d</td>
                <td class="text-right strong">${{ number_format($d['saldo'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="7">No hay saldos pendientes.</td></tr>
        @endforelse
        </tbody>
        @if ($deudores->isNotEmpty())
            <tfoot>
            <tr>
                <td colspan="6">Total listado</td>
                <td class="text-right">${{ number_format($deudores->sum('saldo'), 0, ',', '.') }}</td>
            </tr>
            </tfoot>
        @endif
    </table>

    <div class="note">
        La facturación se cuenta por fecha de emisión y el recaudo por fecha de pago: un período puede
        recaudar más de lo que facturó si en él se cobraron facturas de meses anteriores, de modo que la
        tasa de recaudo puede superar el 100%. No se incluyen las facturas anuladas ni los borradores,
        ni los pagos anulados. La cartera corresponde al saldo pendiente vivo al momento de generar
        este documento.
    </div>
@endsection
