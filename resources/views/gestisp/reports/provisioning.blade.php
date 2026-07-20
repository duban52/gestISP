@extends('adminlte::page')
@section('title', 'Aprovisionamiento')

@section('content_header')
    <div class="card p-3 mb-2">
        <h2 class="mb-0">APROVISIONAMIENTO DE RED</h2>
        <span class="text-muted small">{{ $ambito }} · Estado a hoy</span>
    </div>
@endsection

@section('content')

    @include('gestisp.reports.partials.filters', ['rutaPdf' => route('reports.provisioning.pdf')])

    {{-- ================= EQUIPOS ================= --}}
    <div class="row">
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'ONTs registradas', 'valor' => number_format($resumen['onts']),
                'icono' => 'fa-hdd', 'color' => 'info',
                'pie' => $resumen['olts'] . ' OLT' . ($resumen['olts'] == 1 ? '' : 's') . ' en servicio',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'Cuentas PPPoE activas', 'valor' => number_format($resumen['pppoe_activas']),
                'icono' => 'fa-plug', 'color' => 'success',
                'pie' => number_format($resumen['pppoe_deshabilitadas']) . ' deshabilitadas',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'ONTs sin contrato', 'valor' => number_format($resumen['onts_sin_contrato']),
                'icono' => 'fa-unlink',
                'color' => $resumen['onts_sin_contrato'] > 0 ? 'warning' : 'secondary',
                'pie' => 'Equipos sin cliente asignado',
            ])
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
            @include('gestisp.reports.partials.kpi', [
                'titulo' => 'PPPoE sin contrato', 'valor' => number_format($resumen['pppoe_sin_contrato']),
                'icono' => 'fa-user-slash',
                'color' => $resumen['pppoe_sin_contrato'] > 0 ? 'danger' : 'secondary',
                'pie' => 'Cuentas sin cliente asignado',
            ])
        </div>
    </div>

    {{-- ================= COBERTURA ================= --}}
    <div class="card card-outline card-primary">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-project-diagram mr-1"></i> Cobertura de los contratos vigentes</h3>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Cruce entre lo que se factura y los equipos registrados. Un contrato sin equipo es un
                cliente al que se le cobra sin que el sistema sepa por dónde recibe el servicio;
                un equipo sin contrato es capacidad instalada que nadie está cobrando.
            </p>

            <div class="row text-center">
                @php
                    $tarjetas = [
                        ['Contratos vigentes', $cobertura['vigentes'], 'primary', 'fa-file-signature'],
                        ['Con ONT asignada', $cobertura['con_ont'], 'success', 'fa-hdd'],
                        ['Sin ONT', $cobertura['sin_ont'], $cobertura['sin_ont'] > 0 ? 'warning' : 'secondary', 'fa-times-circle'],
                        ['Con cuenta PPPoE', $cobertura['con_pppoe'], 'success', 'fa-plug'],
                        ['Sin PPPoE', $cobertura['sin_pppoe'], $cobertura['sin_pppoe'] > 0 ? 'warning' : 'secondary', 'fa-times-circle'],
                        ['Sin ningún equipo', $cobertura['sin_nada'], $cobertura['sin_nada'] > 0 ? 'danger' : 'secondary', 'fa-exclamation-triangle'],
                    ];
                @endphp
                @foreach ($tarjetas as [$titulo, $valor, $color, $icono])
                    <div class="col-6 col-md-4 col-lg-2 mb-2">
                        <div class="border rounded p-2 h-100">
                            <i class="fas {{ $icono }} text-{{ $color }} mb-1"></i>
                            <div class="h4 mb-0 text-{{ $color }}">{{ number_format($valor) }}</div>
                            <small class="text-muted">{{ $titulo }}</small>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($cobertura['sin_nada'] > 0)
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <strong>{{ number_format($cobertura['sin_nada']) }}</strong>
                    {{ $cobertura['sin_nada'] == 1 ? 'contrato vigente no tiene' : 'contratos vigentes no tienen' }}
                    ni ONT ni cuenta PPPoE registrada. Puede tratarse de clientes de otra tecnología o de
                    equipos que aún no se han vinculado en el sistema.
                </div>
            @endif
        </div>
    </div>

    {{-- ================= GRÁFICAS ================= --}}
    <div class="row">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-chart-line mr-1"></i> Altas de equipos por período</h3>
                </div>
                <div class="card-body">
                    <div style="height: 280px;"><canvas id="graficaAltas"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-signal mr-1"></i> Calidad óptica de las ONTs</h3>
                </div>
                <div class="card-body">
                    <div style="height: 280px;"><canvas id="graficaOptica"></canvas></div>
                </div>
                <div class="card-footer py-2 small text-muted">
                    Última lectura SNMP guardada de cada ONT. Por debajo de -27 dBm el enlace pierde
                    paquetes; por encima de -8 dBm satura el receptor.
                </div>
            </div>
        </div>
    </div>

    {{-- ================= EQUIPOS POR NODO ================= --}}
    <div class="row">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-server mr-1"></i> ONTs por OLT</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>OLT</th>
                                <th class="text-right">ONTs</th>
                                <th class="text-right">Puertos</th>
                                <th class="text-right">Sin contrato</th>
                                <th class="text-right">RX medio</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($olts as $o)
                                <tr>
                                    <td>{{ $o['olt'] }}<br><small class="text-muted">{{ $o['ip'] }}</small></td>
                                    <td class="text-right"><strong>{{ number_format($o['total']) }}</strong></td>
                                    <td class="text-right">{{ number_format($o['puertos']) }}</td>
                                    <td class="text-right {{ $o['sin_contrato'] > 0 ? 'text-warning' : 'text-muted' }}">
                                        {{ number_format($o['sin_contrato']) }}
                                    </td>
                                    <td class="text-right">
                                        {{ $o['rx_promedio'] !== null ? $o['rx_promedio'] . ' dBm' : '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">
                                    No hay ONTs registradas en esta sucursal.
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-router mr-1"></i> Cuentas PPPoE por router</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Router</th>
                                <th class="text-right">Cuentas</th>
                                <th class="text-right">Activas</th>
                                <th class="text-right">Deshabilitadas</th>
                                <th class="text-right">Sin contrato</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($routers as $r)
                                <tr>
                                    <td>{{ $r['router'] }}<br><small class="text-muted">{{ $r['ip'] }}</small></td>
                                    <td class="text-right"><strong>{{ number_format($r['total']) }}</strong></td>
                                    <td class="text-right text-success">{{ number_format($r['activas']) }}</td>
                                    <td class="text-right {{ $r['deshabilitadas'] > 0 ? 'text-danger' : 'text-muted' }}">
                                        {{ number_format($r['deshabilitadas']) }}
                                    </td>
                                    <td class="text-right {{ $r['sin_contrato'] > 0 ? 'text-warning' : 'text-muted' }}">
                                        {{ number_format($r['sin_contrato']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">
                                    No hay cuentas PPPoE registradas en esta sucursal.
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= PERFILES Y HUÉRFANAS ================= --}}
    <div class="row">
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-tachometer-alt mr-1"></i> Cuentas por perfil de velocidad</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 340px;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Perfil</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Activas</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($perfiles as $p)
                                <tr>
                                    <td>{{ $p['perfil'] }}</td>
                                    <td class="text-right"><strong>{{ number_format($p['total']) }}</strong></td>
                                    <td class="text-right text-success">{{ number_format($p['activas']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">Sin cuentas.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-unlink mr-1"></i> ONTs sin contrato asignado</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 340px;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Serial</th>
                                <th>Ubicación</th>
                                <th>OLT</th>
                                <th>Descripción en la OLT</th>
                                <th class="text-right">RX</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($huerfanas as $h)
                                <tr>
                                    <td><code>{{ $h['sn'] }}</code></td>
                                    <td class="text-nowrap">{{ $h['ubicacion'] }}</td>
                                    <td>{{ $h['olt'] }}</td>
                                    <td class="text-muted">{{ $h['descripcion'] ?: '—' }}</td>
                                    <td class="text-right">{{ $h['rx_power'] !== null ? $h['rx_power'] : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">
                                    Todas las ONTs están vinculadas a un contrato.
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
        graficaSeries('graficaAltas',
            @json($series['labels']),
            [
                { label: 'ONTs', data: @json($series['onts']), borderColor: COLORES.info, backgroundColor: 'rgba(23,162,184,.12)', fill: true },
                { label: 'Cuentas PPPoE', data: @json($series['pppoe']), borderColor: COLORES.exito },
            ]
        );

        graficaAnillo('graficaOptica',
            @json($optica->pluck('etiqueta')),
            @json($optica->pluck('total')),
            @json($optica->pluck('color'))
        );
    </script>
@stop
