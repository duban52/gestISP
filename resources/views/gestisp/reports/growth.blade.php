@extends('adminlte::page')
@section('title', 'Crecimiento de contratos')

@section('content_header')
    <div class="card p-3 mb-2">
        <h2 class="mb-0">CRECIMIENTO DE CONTRATOS</h2>
        <span class="text-muted small">{{ $ambito }} · {{ $period->etiquetaRango() }}</span>
    </div>
@endsection

@section('content')

    @include('gestisp.reports.partials.filters', ['rutaPdf' => route('reports.growth.pdf')])

    <div class="row">
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Contratos vigentes', 'valor' => number_format($resumen['vigentes']),
                'icono' => 'fa-file-signature', 'color' => 'info', 'pie' => 'A hoy',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Altas', 'valor' => number_format($resumen['altas']),
                'icono' => 'fa-user-plus', 'color' => 'success',
                'variacion' => $resumen['variacion_altas'],
                'pie' => 'Período anterior: ' . $resumen['altas_previas'],
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Bajas', 'valor' => number_format($resumen['bajas']),
                'icono' => 'fa-user-minus', 'color' => 'danger',
                'variacion' => $resumen['variacion_bajas'], 'invertir' => true,
                'pie' => 'Período anterior: ' . $resumen['bajas_previas'],
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Crecimiento neto',
                'valor' => ($resumen['neto'] >= 0 ? '+' : '') . number_format($resumen['neto']),
                'icono' => 'fa-chart-line',
                'color' => $resumen['neto'] >= 0 ? 'success' : 'danger',
                'pie' => 'Churn ' . number_format($resumen['churn'], 2) . '%',
            ])
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-chart-area mr-1"></i> Altas, bajas y base acumulada</h3>
                </div>
                <div class="card-body">
                    <div style="height: 320px;"><canvas id="graficaCrecimiento"></canvas></div>
                    <p class="text-muted small mb-0 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        El alta usa la fecha de activación y, cuando no está registrada, la de creación
                        del contrato. La baja se aproxima con la última modificación del contrato retirado.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-chart-pie mr-1"></i> Distribución por estado</h3>
                </div>
                <div class="card-body">
                    <div style="height: 260px;"><canvas id="graficaEstados"></canvas></div>
                    <table class="table table-sm mb-0 mt-2">
                        <tbody>
                        @foreach ($estados as $e)
                            <tr>
                                <td class="py-1">
                                    <span class="badge" style="background: {{ $e['color'] }};">&nbsp;</span>
                                    {{ $e['etiqueta'] }}
                                </td>
                                <td class="py-1 text-right"><strong>{{ number_format($e['total']) }}</strong></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-layer-group mr-1"></i> Contratos e ingreso por plan</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Plan</th>
                                <th class="text-right">Contratos</th>
                                <th class="text-right">Precio</th>
                                <th class="text-right">Ingreso mensual</th>
                                <th class="text-right">Participación</th>
                            </tr>
                            </thead>
                            <tbody>
                            @php $mrrTotal = $planes->sum('mrr'); @endphp
                            @forelse ($planes as $p)
                                <tr>
                                    <td>{{ $p['plan'] }}</td>
                                    <td class="text-right">{{ number_format($p['contratos']) }}</td>
                                    <td class="text-right">${{ number_format($p['precio'], 0, ',', '.') }}</td>
                                    <td class="text-right"><strong>${{ number_format($p['mrr'], 0, ',', '.') }}</strong></td>
                                    <td class="text-right">
                                        {{ $mrrTotal > 0 ? number_format($p['mrr'] / $mrrTotal * 100, 1) : '0,0' }}%
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">
                                    No hay contratos vigentes con plan asignado.
                                </td></tr>
                            @endforelse
                            </tbody>
                            @if ($planes->isNotEmpty())
                                <tfoot>
                                <tr class="bg-light">
                                    <th>Total</th>
                                    <th class="text-right">{{ number_format($planes->sum('contratos')) }}</th>
                                    <th></th>
                                    <th class="text-right">${{ number_format($mrrTotal, 0, ',', '.') }}</th>
                                    <th class="text-right">100,0%</th>
                                </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
@stop

@section('js')
    @include('gestisp.reports.partials.charts-js')
    <script>
        graficaSeries('graficaCrecimiento',
            @json($series['labels']),
            [
                { label: 'Base acumulada', data: @json($series['base']), borderColor: COLORES.primario, backgroundColor: 'rgba(31,78,121,.10)', fill: true },
                { label: 'Altas', data: @json($series['altas']), borderColor: COLORES.exito },
                { label: 'Bajas', data: @json($series['bajas']), borderColor: COLORES.peligro },
                { label: 'Neto', data: @json($series['neto']), borderColor: COLORES.aviso, borderDash: [5, 4] },
            ]
        );

        graficaAnillo('graficaEstados',
            @json($estados->pluck('etiqueta')),
            @json($estados->pluck('total')),
            @json($estados->pluck('color'))
        );
    </script>
@stop
