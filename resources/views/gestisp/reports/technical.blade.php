@extends('adminlte::page')
@section('title', 'Órdenes técnicas')

@section('content_header')
    <div class="card p-3 mb-2">
        <h2 class="mb-0">ÓRDENES TÉCNICAS Y RENDIMIENTO</h2>
        <span class="text-muted small">{{ $ambito }} · {{ $period->etiquetaRango() }}</span>
    </div>
@endsection

@section('content')

    @include('gestisp.reports.partials.filters', ['rutaPdf' => route('reports.technical.pdf')])

    <div class="row">
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Órdenes creadas', 'valor' => number_format($resumen['creadas']),
                'icono' => 'fa-clipboard-list', 'color' => 'info',
                'pie' => 'Período anterior: ' . $resumen['creadas_previas'],
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Órdenes cerradas', 'valor' => number_format($resumen['cerradas']),
                'icono' => 'fa-clipboard-check', 'color' => 'success',
                'pie' => 'Tasa de cierre ' . number_format($resumen['tasa_cierre'], 1) . '%',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Abiertas hoy', 'valor' => number_format($resumen['abiertas']),
                'icono' => 'fa-hourglass-half', 'color' => 'warning',
                'pie' => $resumen['sin_asignar'] . ' sin técnico asignado',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Resolución promedio',
                'valor' => $resumen['horas_promedio'] !== null ? number_format($resumen['horas_promedio'], 1) . ' h' : '—',
                'icono' => 'fa-stopwatch', 'color' => 'primary',
                'pie' => 'Solo órdenes cerradas',
            ])
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-chart-line mr-1"></i> Órdenes creadas y cerradas</h3>
                </div>
                <div class="card-body">
                    <div style="height: 300px;"><canvas id="graficaOrdenes"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-tags mr-1"></i> Por estado</h3>
                </div>
                <div class="card-body">
                    <div style="height: 300px;"><canvas id="graficaEstados"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ TIPOS DE TRABAJO ============ --}}
    <div class="row">
        <div class="col-12">
            <div class="card card-outline card-primary">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-tasks mr-1"></i> Tipos de trabajo</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12 col-lg-5">
                            <div style="height: 300px;"><canvas id="graficaDetalles"></canvas></div>
                        </div>
                        <div class="col-12 col-lg-7">
                            <div class="table-responsive">
                                <table class="table table-hover table-striped mb-0">
                                    <thead class="thead-light">
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
                                            <td>
                                                <span class="badge" style="background: {{ $d['color'] }};">&nbsp;</span>
                                                {{ $d['etiqueta'] }}
                                            </td>
                                            <td>
                                                <span class="badge badge-{{ $d['tipo'] === 'Incidencia' ? 'warning' : 'info' }}">
                                                    {{ $d['tipo'] }}
                                                </span>
                                            </td>
                                            <td class="text-right"><strong>{{ number_format($d['total']) }}</strong></td>
                                            <td class="text-right text-success">{{ number_format($d['cerradas']) }}</td>
                                            <td class="text-right {{ $d['abiertas'] > 0 ? 'text-warning' : 'text-muted' }}">
                                                {{ number_format($d['abiertas']) }}
                                            </td>
                                            <td class="text-right">
                                                {{ $d['horas_promedio'] !== null ? number_format($d['horas_promedio'], 1) . ' h' : '—' }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-center text-muted py-4">
                                            No hay órdenes en este período.
                                        </td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer py-2 small text-muted">
                    <i class="fas fa-info-circle mr-1"></i>
                    El desglose sale del detalle de cada orden. Las variantes del mismo trabajo se
                    unifican: las instalaciones que crea el sistema al firmar un contrato cuentan
                    junto a las que se registran a mano.
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-user-cog mr-1"></i> Rendimiento por técnico</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="thead-light">
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
                                    <td class="text-right"><strong>{{ number_format($t['cerradas']) }}</strong></td>
                                    <td class="text-right {{ $t['rechazadas'] > 0 ? 'text-danger' : 'text-muted' }}">
                                        {{ number_format($t['rechazadas']) }}
                                    </td>
                                    <td class="text-right">
                                        <span class="badge badge-{{ $t['efectividad'] >= 80 ? 'success' : ($t['efectividad'] >= 50 ? 'warning' : 'danger') }}">
                                            {{ number_format($t['efectividad'], 1) }}%
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        {{ $t['horas_promedio'] !== null ? number_format($t['horas_promedio'], 1) . ' h' : '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">
                                    No hay órdenes asignadas en este período.
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer py-2 small text-muted">
                    <i class="fas fa-info-circle mr-1"></i>
                    La resolución se calcula entre la creación y la última modificación de las órdenes
                    cerradas. No existe una fecha de cierre propia, así que editar una orden ya cerrada
                    alarga su tiempo medido.
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-list mr-1"></i> Por tipo</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @forelse ($tipos as $t)
                            <tr>
                                <td>{{ $t['etiqueta'] }}</td>
                                <td class="text-right"><strong>{{ number_format($t['total']) }}</strong></td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-3">Sin datos.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-clipboard-check mr-1"></i> Verificaciones</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @forelse ($verificaciones as $v)
                            <tr>
                                <td>{{ $v['etiqueta'] }}</td>
                                <td class="text-right"><strong>{{ number_format($v['total']) }}</strong></td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-3">Sin verificaciones registradas.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('gestisp.reports.partials.charts-js')
    <script>
        graficaSeries('graficaOrdenes',
            @json($series['labels']),
            [
                { label: 'Creadas', data: @json($series['creadas']), borderColor: COLORES.info, backgroundColor: 'rgba(23,162,184,.12)', fill: true },
                { label: 'Cerradas', data: @json($series['cerradas']), borderColor: COLORES.exito },
            ]
        );

        graficaAnillo('graficaEstados',
            @json($estados->pluck('etiqueta')),
            @json($estados->pluck('total')),
            ['#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6c757d']
        );

        graficaBarrasH('graficaDetalles',
            @json($detalles->pluck('etiqueta')),
            @json($detalles->pluck('total')),
            @json($detalles->pluck('color'))
        );
    </script>
@stop
