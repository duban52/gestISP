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
                <span class="summary-label">Órdenes creadas</span>
                <span class="summary-value">{{ number_format($resumen['creadas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Órdenes cerradas</span>
                <span class="summary-value positive">{{ number_format($resumen['cerradas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Abiertas hoy</span>
                <span class="summary-value">{{ number_format($resumen['abiertas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Resolución promedio</span>
                <span class="summary-value">
                    {{ $resumen['horas_promedio'] !== null ? number_format($resumen['horas_promedio'], 1) . ' h' : '—' }}
                </span>
            </td>
        </tr>
    </table>

    <div class="section-title">Rendimiento por técnico</div>
    <table class="data">
        <colgroup>
            <col style="width: 30%;"><col style="width: 14%;"><col style="width: 14%;">
            <col style="width: 14%;"><col style="width: 14%;"><col style="width: 14%;">
        </colgroup>
        <thead>
        <tr>
            <th>Técnico</th>
            <th class="text-right">Asignadas</th>
            <th class="text-right">Cerradas</th>
            <th class="text-right">Rechazadas</th>
            <th class="text-right">Efectividad</th>
            <th class="text-right">Resolución</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($tecnicos as $t)
            <tr>
                <td>{{ $t['tecnico'] }}</td>
                <td class="text-right">{{ number_format($t['asignadas']) }}</td>
                <td class="text-right strong">{{ number_format($t['cerradas']) }}</td>
                <td class="text-right {{ $t['rechazadas'] > 0 ? 'negative' : 'muted' }}">
                    {{ number_format($t['rechazadas']) }}
                </td>
                <td class="text-right {{ $t['efectividad'] >= 80 ? 'positive' : '' }}">
                    {{ number_format($t['efectividad'], 1) }}%
                </td>
                <td class="text-right">
                    {{ $t['horas_promedio'] !== null ? number_format($t['horas_promedio'], 1) . ' h' : '—' }}
                </td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="6">No hay órdenes asignadas en el período.</td></tr>
        @endforelse
        </tbody>
        @if ($tecnicos->isNotEmpty())
            <tfoot>
            <tr>
                <td>Total</td>
                <td class="text-right">{{ number_format($tecnicos->sum('asignadas')) }}</td>
                <td class="text-right">{{ number_format($tecnicos->sum('cerradas')) }}</td>
                <td class="text-right">{{ number_format($tecnicos->sum('rechazadas')) }}</td>
                <td colspan="2"></td>
            </tr>
            </tfoot>
        @endif
    </table>

    @if ($resumen['sin_asignar'] > 0)
        <div class="note">
            <strong>{{ $resumen['sin_asignar'] }}</strong>
            {{ $resumen['sin_asignar'] === 1 ? 'orden creada en el período no tiene' : 'órdenes creadas en el período no tienen' }}
            técnico asignado, por lo que no figuran en el cuadro anterior.
        </div>
    @endif

    <div class="section-title">Volumen por período</div>
    <table class="data">
        <colgroup><col style="width: 40%;"><col style="width: 30%;"><col style="width: 30%;"></colgroup>
        <thead>
        <tr>
            <th>Período</th>
            <th class="text-right">Creadas</th>
            <th class="text-right">Cerradas</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($series['labels'] as $i => $etiqueta)
            <tr>
                <td>{{ $etiqueta }}</td>
                <td class="text-right">{{ number_format($series['creadas'][$i]) }}</td>
                <td class="text-right">{{ number_format($series['cerradas'][$i]) }}</td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="3">Sin órdenes en el período.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Tipos de trabajo</div>
    <table class="data">
        <colgroup>
            <col style="width: 28%;"><col style="width: 16%;"><col style="width: 14%;">
            <col style="width: 14%;"><col style="width: 14%;"><col style="width: 14%;">
        </colgroup>
        <thead>
        <tr>
            <th>Trabajo</th>
            <th>Tipo</th>
            <th class="text-right">Total</th>
            <th class="text-right">Cerradas</th>
            <th class="text-right">Abiertas</th>
            <th class="text-right">Resolución</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($detalleEstado as $d)
            <tr>
                <td>{{ $d['etiqueta'] }}</td>
                <td>{{ $d['tipo'] }}</td>
                <td class="text-right strong">{{ number_format($d['total']) }}</td>
                <td class="text-right positive">{{ number_format($d['cerradas']) }}</td>
                <td class="text-right {{ $d['abiertas'] > 0 ? 'negative' : 'muted' }}">
                    {{ number_format($d['abiertas']) }}
                </td>
                <td class="text-right">
                    {{ $d['horas_promedio'] !== null ? number_format($d['horas_promedio'], 1) . ' h' : '—' }}
                </td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="6">Sin órdenes en el período.</td></tr>
        @endforelse
        </tbody>
    </table>

    @include('gestisp.reports.pdf.partials.bars', [
        'filas' => $detalles->map(fn ($d) => [
            'etiqueta' => $d['etiqueta'],
            'valor' => $d['total'],
            'color' => $d['color'],
        ])->all(),
    ])

    <div class="section-title">Distribución por tipo y estado</div>
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 8px; border: none;">
                @include('gestisp.reports.pdf.partials.bars', [
                    'filas' => $tipos->map(fn ($t) => [
                        'etiqueta' => $t['etiqueta'],
                        'valor' => $t['total'],
                        'color' => '#1F4E79',
                    ])->all(),
                ])
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 8px; border: none;">
                @include('gestisp.reports.pdf.partials.bars', [
                    'filas' => $estados->map(fn ($e) => [
                        'etiqueta' => $e['etiqueta'],
                        'valor' => $e['total'],
                        'color' => '#17a2b8',
                    ])->all(),
                ])
            </td>
        </tr>
    </table>

    @if ($verificaciones->isNotEmpty())
        <div class="section-title">Verificaciones de supervisión</div>
        <table class="data">
            <thead><tr><th>Resultado</th><th class="text-right">Total</th></tr></thead>
            <tbody>
            @foreach ($verificaciones as $v)
                <tr>
                    <td>{{ $v['etiqueta'] }}</td>
                    <td class="text-right strong">{{ number_format($v['total']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="note">
        El tiempo de resolución se calcula entre la creación de la orden y su última modificación,
        considerando solo las órdenes cerradas: el sistema no registra una fecha de cierre propia.
        Es una medida válida para comparar técnicos entre sí, pero editar una orden ya cerrada
        aumenta el tiempo que se le atribuye. El desglose de trabajos sale del detalle de cada orden;
        las variantes del mismo concepto se unifican, de modo que las instalaciones creadas
        automáticamente al firmar un contrato cuentan junto a las registradas a mano.
    </div>
@endsection
