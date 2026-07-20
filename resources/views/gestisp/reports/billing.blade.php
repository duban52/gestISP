@extends('adminlte::page')
@section('title', 'Facturación y recaudo')

@section('content_header')
    <div class="card p-3 mb-2">
        <h2 class="mb-0">FACTURACIÓN Y RECAUDO</h2>
        <span class="text-muted small">{{ $ambito }} · {{ $period->etiquetaRango() }}</span>
    </div>
@endsection

@section('content')

    @include('gestisp.reports.partials.filters', ['rutaPdf' => route('reports.billing.pdf')])

    <div class="row">
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Facturado',
                'valor' => '$' . number_format($resumen['facturado'], 0, ',', '.'),
                'icono' => 'fa-file-invoice', 'color' => 'info',
                'pie' => 'Ticket promedio $' . number_format($resumen['ticket_promedio'], 0, ',', '.'),
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Recaudado',
                'valor' => '$' . number_format($resumen['recaudado'], 0, ',', '.'),
                'icono' => 'fa-hand-holding-usd', 'color' => 'success',
                'pie' => 'Tasa de recaudo ' . number_format($resumen['tasa_recaudo'], 1) . '%',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Cartera total',
                'valor' => '$' . number_format($resumen['cartera'], 0, ',', '.'),
                'icono' => 'fa-wallet', 'color' => 'warning',
                'pie' => 'Saldo pendiente vivo',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Cartera vencida',
                'valor' => '$' . number_format($resumen['cartera_vencida'], 0, ',', '.'),
                'icono' => 'fa-exclamation-triangle', 'color' => 'danger',
                'pie' => $resumen['cartera'] > 0
                    ? number_format($resumen['cartera_vencida'] / $resumen['cartera'] * 100, 1) . '% de la cartera'
                    : 'Sin cartera',
            ])
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
                    <p class="text-muted small mb-0 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Se factura por fecha de emisión y se recauda por fecha de pago. Un período puede
                        superar el 100% de recaudo si en él se cobraron facturas de meses anteriores.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-hourglass-half mr-1"></i> Cartera por antigüedad</h3>
                </div>
                <div class="card-body">
                    <div style="height: 240px;"><canvas id="graficaCartera"></canvas></div>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Tramo</th>
                                <th class="text-right">Facturas</th>
                                <th class="text-right">Saldo</th>
                                <th class="text-right">%</th>
                            </tr>
                            </thead>
                            <tbody>
                            @php $carteraTotal = $cartera->sum('total'); @endphp
                            @foreach ($cartera as $c)
                                <tr>
                                    <td>
                                        <span class="badge" style="background: {{ $c['color'] }};">&nbsp;</span>
                                        {{ $c['etiqueta'] }}
                                    </td>
                                    <td class="text-right">{{ number_format($c['facturas']) }}</td>
                                    <td class="text-right"><strong>${{ number_format($c['total'], 0, ',', '.') }}</strong></td>
                                    <td class="text-right">
                                        {{ $carteraTotal > 0 ? number_format($c['total'] / $carteraTotal * 100, 1) : '0,0' }}%
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-cash-register mr-1"></i> Recaudo por método</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="thead-light">
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
                                <td class="text-right"><strong>${{ number_format($m['total'], 0, ',', '.') }}</strong></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">Sin pagos en el período.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-tags mr-1"></i> Facturas emitidas por estado</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @forelse ($estados as $e)
                            <tr>
                                <td>{{ $e['etiqueta'] }}</td>
                                <td class="text-right">{{ number_format($e['total']) }}</td>
                                <td class="text-right text-muted">${{ number_format($e['monto'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-3">Sin facturas en el período.</td></tr>
                        @endforelse
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
                    <h3 class="card-title"><i class="fas fa-user-clock mr-1"></i> Mayores saldos pendientes</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="thead-light">
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
                                    <td class="text-muted">{{ $d['documento'] }}</td>
                                    <td class="text-center">#{{ $d['contrato'] }}</td>
                                    <td><span class="badge badge-secondary">{{ $d['estado'] }}</span></td>
                                    <td class="text-right">{{ $d['facturas'] }}</td>
                                    <td class="text-right">
                                        <span class="badge badge-{{ $d['dias'] > 90 ? 'danger' : ($d['dias'] > 30 ? 'warning' : 'info') }}">
                                            {{ $d['dias'] }} días
                                        </span>
                                    </td>
                                    <td class="text-right"><strong>${{ number_format($d['saldo'], 0, ',', '.') }}</strong></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">
                                    No hay saldos pendientes.
                                </td></tr>
                            @endforelse
                            </tbody>
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
        graficaSeries('graficaDinero',
            @json($series['labels']),
            [
                { label: 'Facturado', data: @json($series['facturado']), backgroundColor: COLORES.primario },
                { label: 'Recaudado', data: @json($series['recaudado']), backgroundColor: COLORES.exito },
            ],
            { tipo: 'bar', dinero: true }
        );

        graficaBarrasH('graficaCartera',
            @json($cartera->pluck('etiqueta')),
            @json($cartera->pluck('total')),
            @json($cartera->pluck('color')),
            true
        );
    </script>
@stop
