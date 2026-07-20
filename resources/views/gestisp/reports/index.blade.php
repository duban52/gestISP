@extends('adminlte::page')
@section('title', 'Informes gerenciales')

@section('content_header')
    <div class="card p-3 mb-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h2 class="mb-0">TABLERO EJECUTIVO</h2>
                <span class="text-muted small">
                    {{ $ambito }} · {{ $period->etiquetaRango() }}
                </span>
            </div>
            <a href="{{ route('reports.summary.pdf') }}?{{ http_build_query(request()->query()) }}"
               class="btn btn-danger">
                <i class="fas fa-file-pdf mr-1"></i> Descargar resumen
            </a>
        </div>
    </div>
@endsection

@section('content')

    @include('gestisp.reports.partials.filters', ['rutaPdf' => route('reports.summary.pdf')])

    {{-- ================= INDICADORES ================= --}}
    <div class="row">
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Contratos vigentes',
                'valor' => number_format($crecimiento['vigentes']),
                'icono' => 'fa-file-signature',
                'color' => 'info',
                'pie' => 'Excluye retirados',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Altas del período',
                'valor' => number_format($crecimiento['altas']),
                'icono' => 'fa-user-plus',
                'color' => 'success',
                'variacion' => $crecimiento['variacion_altas'],
                'pie' => 'Sin período previo comparable',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Bajas del período',
                'valor' => number_format($crecimiento['bajas']),
                'icono' => 'fa-user-minus',
                'color' => 'warning',
                'variacion' => $crecimiento['variacion_bajas'],
                'invertir' => true,
                'pie' => 'Churn ' . number_format($crecimiento['churn'], 2) . '%',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Ingreso recurrente (MRR)',
                'valor' => '$' . number_format($crecimiento['mrr'], 0, ',', '.'),
                'icono' => 'fa-sync-alt',
                'color' => 'primary',
                'pie' => 'Planes de contratos vigentes',
            ])
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Facturado',
                'valor' => '$' . number_format($facturacion['facturado'], 0, ',', '.'),
                'icono' => 'fa-file-invoice',
                'color' => 'secondary',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Recaudado',
                'valor' => '$' . number_format($facturacion['recaudado'], 0, ',', '.'),
                'icono' => 'fa-hand-holding-usd',
                'color' => 'success',
                'pie' => 'Tasa de recaudo ' . number_format($facturacion['tasa_recaudo'], 1) . '%',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Cartera vencida',
                'valor' => '$' . number_format($facturacion['cartera_vencida'], 0, ',', '.'),
                'icono' => 'fa-exclamation-circle',
                'color' => 'danger',
                'pie' => 'De $' . number_format($facturacion['cartera'], 0, ',', '.') . ' total',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Órdenes abiertas',
                'valor' => number_format($tecnicas['abiertas']),
                'icono' => 'fa-tools',
                'color' => 'warning',
                'pie' => $tecnicas['sin_asignar'] . ' sin asignar',
            ])
        </div>
    </div>

    {{-- ================= GRÁFICAS ================= --}}
    <div class="row">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-chart-line mr-1"></i> Evolución de la base de contratos</h3>
                </div>
                <div class="card-body">
                    <div style="height: 300px;"><canvas id="graficaBase"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-chart-pie mr-1"></i> Estado de los contratos</h3>
                </div>
                <div class="card-body">
                    <div style="height: 300px;"><canvas id="graficaEstados"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-money-bill-wave mr-1"></i> Facturado frente a recaudado</h3>
                </div>
                <div class="card-body">
                    <div style="height: 300px;"><canvas id="graficaDinero"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= ACCESOS ================= --}}
    <div class="row">
        @php
            $accesos = [
                ['reports.growth', 'reports.growth', 'Crecimiento de contratos', 'fa-user-plus', 'Altas, bajas, churn e ingreso por plan'],
                ['reports.technical', 'reports.technical', 'Órdenes técnicas', 'fa-tools', 'Tipos de trabajo y rendimiento por técnico'],
                ['reports.billing', 'reports.billing', 'Facturación y recaudo', 'fa-file-invoice-dollar', 'Cartera por antigüedad y mayores deudores'],
                ['reports.provisioning', 'reports.provisioning', 'Aprovisionamiento', 'fa-network-wired', 'ONTs, cuentas PPPoE y cobertura de red'],
            ];
        @endphp
        @foreach ($accesos as [$permiso, $ruta, $titulo, $icono, $detalle])
            @can($permiso)
                <div class="col-12 col-md-4">
                    <a href="{{ route($ruta) }}?{{ http_build_query(request()->query()) }}"
                       class="card card-outline card-primary text-decoration-none h-100">
                        <div class="card-body text-center">
                            <i class="fas {{ $icono }} fa-2x text-primary mb-2"></i>
                            <h5 class="mb-1">{{ $titulo }}</h5>
                            <p class="text-muted small mb-0">{{ $detalle }}</p>
                        </div>
                    </a>
                </div>
            @endcan
        @endforeach
    </div>
@stop

@section('js')
    @include('gestisp.reports.partials.charts-js')
    <script>
        graficaSeries('graficaBase',
            @json($seriesCrecimiento['labels']),
            [
                { label: 'Base de contratos', data: @json($seriesCrecimiento['base']), borderColor: COLORES.primario, backgroundColor: 'rgba(31,78,121,.10)', fill: true },
                { label: 'Altas', data: @json($seriesCrecimiento['altas']), borderColor: COLORES.exito },
                { label: 'Bajas', data: @json($seriesCrecimiento['bajas']), borderColor: COLORES.peligro },
            ]
        );

        graficaAnillo('graficaEstados',
            @json($estadosContrato->pluck('etiqueta')),
            @json($estadosContrato->pluck('total')),
            @json($estadosContrato->pluck('color'))
        );

        graficaSeries('graficaDinero',
            @json($seriesFacturacion['labels']),
            [
                { label: 'Facturado', data: @json($seriesFacturacion['facturado']), backgroundColor: COLORES.primario },
                { label: 'Recaudado', data: @json($seriesFacturacion['recaudado']), backgroundColor: COLORES.exito },
            ],
            { tipo: 'bar', dinero: true }
        );
    </script>
@stop
