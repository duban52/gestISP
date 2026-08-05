{{-- ============================================================
     Ficha de un cable

     Los hilos se agrupan por buffer porque es como está el cable de
     verdad y como lo abre el técnico: primero destapa el buffer azul,
     luego el naranja. Una lista corrida del 1 al 48 obligaría a hacer
     la cuenta de cabeza cada vez.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Cable ' . $cable->code)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-grip-lines mr-2"></i>{{ $cable->code }}
            @if($cable->name)
                <small class="text-muted">— {{ $cable->name }}</small>
            @endif
        </h1>
        <div>
            <button type="button" class="btn btn-outline-danger" id="btnImpacto"
                    data-url="{{ route('cables.impact', $cable) }}">
                <i class="fas fa-exclamation-triangle"></i> ¿A quién afecta cortarlo?
            </button>
            <a href="{{ route('cables.edit', $cable) }}" class="btn btn-outline-primary">
                <i class="fas fa-cog"></i> Editar
            </a>
            <a href="{{ route('cables.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $ocupacion['capacidad'] }}</h3>
                    <p class="mb-0">Hilos</p>
                </div>
                <div class="icon"><i class="fas fa-grip-lines"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $ocupacion['en_uso'] }}</h3>
                    <p class="mb-0">Conectados</p>
                </div>
                <div class="icon"><i class="fas fa-link"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ $ocupacion['libres'] }}</h3>
                    <p class="mb-0">Vírgenes</p>
                </div>
                <div class="icon"><i class="fas fa-plus-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $ocupacion['inutilizables'] > 0 ? 'bg-gradient-danger' : 'bg-gradient-secondary' }}">
                <div class="inner">
                    <h3>{{ $ocupacion['inutilizables'] }}</h3>
                    <p class="mb-0">Dañados o reservados</p>
                </div>
                <div class="icon"><i class="fas fa-ban"></i></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body py-3">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width: 35%;">Red</td>
                            <td><a href="{{ route('networks.show', $cable->network) }}">{{ $cable->network->name }}</a></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Tipo</td>
                            <td>{{ $cable->tipo_legible }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Capacidad</td>
                            <td>{{ $cable->capacidad_legible }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Recorrido</td>
                            <td>{{ $cable->desde_legible }} <i class="fas fa-arrow-right text-muted"></i> {{ $cable->hasta_legible }}</td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width: 35%;">Longitud</td>
                            <td>{{ $cable->length_m ? number_format($cable->length_m) . ' m' : '—' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Instalación</td>
                            <td>{{ \App\Models\FiberCable::INSTALACIONES[$cable->installation] ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Propietario</td>
                            <td>{{ $cable->owner ?: '—' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Zona</td>
                            <td>
                                @if($cable->zone)
                                    <span class="badge" style="background: {{ $cable->zone->color }}; color:#fff;">
                                        {{ $cable->zone->name }}
                                    </span>
                                @else
                                    <span class="text-muted">Sin zona</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            @if($cable->notes)
                <div class="alert alert-light border mt-2 mb-0 py-2 small">{{ $cable->notes }}</div>
            @endif
        </div>
    </div>

    {{-- ============================================================
         Los hilos, buffer por buffer
         ============================================================ --}}
    @foreach($porBuffer as $numeroBuffer => $hilos)
        @php
            $colorBuffer = $hilos->first()->buffer_color;
        @endphp
        <div class="card shadow-sm">
            <div class="card-header py-2">
                <h3 class="card-title mb-0">
                    <span class="badge mr-1"
                          style="background: {{ \App\Support\FiberColors::hex($numeroBuffer) }}; color: {{ \App\Support\FiberColors::textoSobre($colorBuffer) }};">
                        &nbsp;&nbsp;
                    </span>
                    Buffer {{ $numeroBuffer }} — {{ $colorBuffer }}
                    <small class="text-muted ml-1">{{ $hilos->count() }} hilos</small>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th style="width: 60px;">N.º</th>
                            <th style="width: 180px;">Color</th>
                            <th>Conectado a</th>
                            <th class="text-center" style="width: 110px;">Estado</th>
                            <th class="text-center" style="width: 150px;">Marcar</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($hilos as $hilo)
                            <tr>
                                <td class="text-muted">{{ $hilo->number }}</td>
                                <td>
                                    <span class="badge"
                                          style="background: {{ $hilo->color_hex }}; color: {{ \App\Support\FiberColors::textoSobre($hilo->strand_color) }}; font-size: .8rem;">
                                        H{{ $hilo->strand_number }} {{ $hilo->strand_color }}
                                    </span>
                                </td>
                                <td>
                                    @php
                                        $conexiones = collect();

                                        foreach ($fusionesPorHilo[$hilo->id] ?? [] as $fusion) {
                                            $otro = $fusion->otroExtremo($hilo);
                                            $conexiones->push([
                                                'icono' => 'fa-link',
                                                'texto' => 'Fusionado con ' . ($otro?->etiqueta_completa ?? '—')
                                                    . ' en la mufla ' . ($fusion->closure?->code ?? '—'),
                                                'url' => $fusion->closure ? route('closures.show', $fusion->closure) : null,
                                            ]);
                                        }

                                        if ($hilo->splitterEntrada) {
                                            $conexiones->push([
                                                'icono' => 'fa-code-branch',
                                                'texto' => 'Entra al splitter ' . $hilo->splitterEntrada->ratio,
                                                'url' => route('closures.show', $hilo->splitterEntrada->splice_closure_id),
                                            ]);
                                        }

                                        if ($hilo->splitterSalida) {
                                            $conexiones->push([
                                                'icono' => 'fa-code-branch',
                                                'texto' => 'Salida ' . $hilo->splitterSalida->number
                                                    . ' del splitter ' . $hilo->splitterSalida->splitter->ratio,
                                                'url' => route('closures.show', $hilo->splitterSalida->splitter->splice_closure_id),
                                            ]);
                                        }

                                        if ($hilo->napBox) {
                                            $conexiones->push([
                                                'icono' => 'fa-box',
                                                'texto' => 'Alimenta la caja ' . $hilo->napBox->code,
                                                'url' => route('naps.show', $hilo->napBox),
                                            ]);
                                        }
                                    @endphp

                                    @forelse($conexiones as $conexion)
                                        <div class="small">
                                            <i class="fas {{ $conexion['icono'] }} text-muted"></i>
                                            @if($conexion['url'])
                                                <a href="{{ $conexion['url'] }}">{{ $conexion['texto'] }}</a>
                                            @else
                                                {{ $conexion['texto'] }}
                                            @endif
                                        </div>
                                    @empty
                                        <span class="text-muted small">Sin conectar</span>
                                    @endforelse
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-{{ $hilo->estado_color }}">{{ $hilo->estado_legible }}</span>
                                </td>
                                <td class="text-center">
                                    {{-- Solo se puede marcar dañado o reservado: "en uso"
                                         no es un estado que se ponga a mano, se deduce de
                                         las conexiones. --}}
                                    <form method="POST" action="{{ route('cables.strand.update', $hilo) }}"
                                          class="form-inline justify-content-center">
                                        @csrf @method('PUT')
                                        <select name="status" class="form-control form-control-sm mr-1"
                                                onchange="this.form.submit()" style="width: 110px;">
                                            @foreach(\App\Models\CableStrand::estadosEditables() as $clave => $texto)
                                                <option value="{{ $clave }}" @selected($hilo->status === $clave)>{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach

    {{-- Modal de impacto --}}
    <div class="modal fade" id="modalImpacto" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header py-2 bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-1"></i> Si se corta este cable</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="cuerpoImpacto">
                    <div class="text-center py-4 text-muted">
                        <span class="spinner-border spinner-border-sm"></span> Recorriendo la red…
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js')
    <script>
        $(function () {
            /* El recorrido del grafo se pide al pulsar, no al cargar la
               ficha: en una red grande cuesta unos milisegundos que no
               tienen por qué retrasar la pantalla de todos los días. */
            $('#btnImpacto').on('click', function () {
                $('#cuerpoImpacto').html(
                    '<div class="text-center py-4 text-muted">' +
                    '<span class="spinner-border spinner-border-sm"></span> Recorriendo la red…</div>'
                );
                $('#modalImpacto').modal('show');

                fetch($(this).data('url'))
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(pintarImpacto)
                    .catch(() => $('#cuerpoImpacto').html(
                        '<div class="alert alert-light border mb-0">No se pudo calcular el impacto.</div>'
                    ));
            });

            function pintarImpacto(d) {
                if (d.total_cajas === 0) {
                    $('#cuerpoImpacto').html(
                        '<p class="mb-0"><i class="fas fa-check-circle text-success"></i> ' +
                        'Ningún cliente depende de este cable ahora mismo.</p>'
                    );
                    return;
                }

                let html = '<div class="row text-center mb-3">' +
                    '<div class="col-6"><div class="h1 mb-0 text-danger">' + d.total_clientes + '</div>' +
                    '<div class="text-muted text-uppercase small">clientes sin servicio</div></div>' +
                    '<div class="col-6"><div class="h1 mb-0">' + d.total_cajas + '</div>' +
                    '<div class="text-muted text-uppercase small">de ' + d.cajas_en_la_red + ' cajas</div></div>' +
                    '</div>';

                if (d.contratos.length > 0) {
                    html += '<table class="table table-sm"><thead class="thead-light"><tr>' +
                        '<th>Contrato</th><th>Cliente</th><th>Teléfono</th><th>Caja</th></tr></thead><tbody>';

                    d.contratos.forEach(function (c) {
                        html += '<tr>' +
                            '<td>' + escapar(c.numero) + '</td>' +
                            '<td>' + escapar(c.cliente || '—') + '</td>' +
                            '<td>' + escapar(c.telefono || '—') + '</td>' +
                            '<td><small>' + escapar(c.caja) + ' / P' + c.puerto + '</small></td>' +
                            '</tr>';
                    });

                    html += '</tbody></table>';
                } else {
                    html += '<p class="text-muted">Las cajas afectadas todavía no tienen clientes conectados.</p>';
                }

                $('#cuerpoImpacto').html(html);
            }

            function escapar(v) {
                return $('<div>').text(v == null ? '' : v).html();
            }
        });
    </script>
@endsection
