{{-- ============================================================
     Ficha de una caja NAP / CTO

     Responde de un vistazo lo que se pregunta en el mostrador y en la
     calle: cuántos puertos quedan, quién ocupa cada uno y dónde está
     la caja.

     La rejilla de puertos es lo que más se mira: un puerto es un
     cuadro con su número y su color, para poder decir "conéctelo en
     el 5" sin leer una tabla.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', $nap->code)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-box mr-2"></i>{{ $nap->code }}
            @if($nap->name)<small class="text-muted">{{ $nap->name }}</small>@endif
        </h1>
        <div>
            @can('naps.edit')
                <a href="{{ route('naps.edit', $nap) }}" class="btn btn-outline-primary">
                    <i class="fas fa-edit"></i> Editar
                </a>
            @endcan
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <a href="{{ route('naps.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    <div class="row">
        {{-- ---------- Ocupación ---------- --}}
        <div class="col-12 col-lg-8">
            <div class="card card-outline card-{{ $nap->color_ocupacion }} shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plug mr-1"></i> Puertos</h3>
                    <div class="card-tools">
                        <span class="badge badge-{{ $nap->color_ocupacion }}" style="font-size: 1rem;">
                            {{ $ocupacion['porcentaje'] }}% ocupada
                        </span>
                    </div>
                </div>

                <div class="card-body">
                    {{-- Barra de un vistazo: ocupado / libre / inutilizable --}}
                    <div class="progress mb-2" style="height: 28px;">
                        @if($ocupacion['ocupados'] > 0)
                            <div class="progress-bar bg-primary"
                                 style="width: {{ $ocupacion['ocupados'] / max($ocupacion['capacidad'],1) * 100 }}%">
                                {{ $ocupacion['ocupados'] }} ocupados
                            </div>
                        @endif
                        @if($ocupacion['disponibles'] > 0)
                            <div class="progress-bar bg-success"
                                 style="width: {{ $ocupacion['disponibles'] / max($ocupacion['capacidad'],1) * 100 }}%">
                                {{ $ocupacion['disponibles'] }} libres
                            </div>
                        @endif
                        @if($ocupacion['inutilizables'] > 0)
                            <div class="progress-bar bg-danger"
                                 style="width: {{ $ocupacion['inutilizables'] / max($ocupacion['capacidad'],1) * 100 }}%">
                                {{ $ocupacion['inutilizables'] }}
                            </div>
                        @endif
                    </div>

                    <div class="row text-center mb-3">
                        <div class="col-3">
                            <div class="h4 mb-0">{{ $ocupacion['capacidad'] }}</div>
                            <small class="text-muted text-uppercase">Capacidad</small>
                        </div>
                        <div class="col-3">
                            <div class="h4 mb-0 text-primary">{{ $ocupacion['ocupados'] }}</div>
                            <small class="text-muted text-uppercase">Ocupados</small>
                        </div>
                        <div class="col-3">
                            <div class="h4 mb-0 text-success">{{ $ocupacion['disponibles'] }}</div>
                            <small class="text-muted text-uppercase">Libres</small>
                        </div>
                        <div class="col-3">
                            <div class="h4 mb-0 {{ $ocupacion['inutilizables'] > 0 ? 'text-danger' : 'text-muted' }}">
                                {{ $ocupacion['inutilizables'] }}
                            </div>
                            <small class="text-muted text-uppercase">Dañados/reserv.</small>
                        </div>
                    </div>

                    {{-- ---------- Rejilla de puertos ---------- --}}
                    <div class="rejilla-puertos">
                        @foreach($nap->ports as $puerto)
                            <div class="puerto puerto-{{ $puerto->estado_color }}"
                                 data-toggle="modal" data-target="#modalPuerto"
                                 data-numero="{{ $puerto->number }}"
                                 data-estado="{{ $puerto->status }}"
                                 data-ocupado="{{ $puerto->estaOcupado() ? 1 : 0 }}"
                                 data-notas="{{ $puerto->notes }}"
                                 data-url-estado="{{ route('naps.port.update', $puerto) }}"
                                 data-url-liberar="{{ route('naps.port.release', $puerto) }}"
                                 data-contrato="{{ $puerto->contract?->numero_visible }}"
                                 data-cliente="{{ $puerto->contract?->client ? trim($puerto->contract->client->name . ' ' . $puerto->contract->client->last_name) : '' }}"
                                 title="Puerto {{ $puerto->number }} — {{ $puerto->estado_legible }}">
                                <span class="numero">{{ $puerto->number }}</span>
                                @if($puerto->estaOcupado())
                                    <span class="ocupante">{{ $puerto->contract->numero_visible }}</span>

                                    {{-- La señal, debajo del contrato. Es lo que
                                         permite mirar la caja entera de un vistazo
                                         y ver si el problema es de un puerto o de
                                         todos: con la potencia solo en la tabla de
                                         abajo hay que ir cruzando fila por fila.

                                         El fondo del cuadro ya está pintado por el
                                         ESTADO del puerto, así que la banda de
                                         señal se marca con el color del texto, no
                                         con otro fondo que competiría con él. --}}
                                    @php
                                        $ontPuerto = $puerto->contract?->ont;
                                        $rx = ($ontPuerto && $ontPuerto->rx_power !== null && $ontPuerto->rx_power !== '')
                                            ? (float) $ontPuerto->rx_power
                                            : null;
                                        $bandaPuerto = $rx !== null
                                            ? \App\Services\OltStatistics::bandaDe($rx)
                                            : null;
                                    @endphp

                                    @if($rx !== null)
                                        <span class="senal senal-{{ $bandaPuerto }}"
                                              title="{{ \App\Services\OltStatistics::bandas()[$bandaPuerto]['etiqueta'] ?? '' }} — {{ $ontPuerto->sn }}">
                                            {{ number_format($rx, 1) }} dBm
                                        </span>
                                    @elseif($ontPuerto)
                                        <span class="senal senal-sin">sin lectura</span>
                                    @else
                                        <span class="senal senal-sin">sin ONT</span>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 small">
                        <span class="badge badge-success">&nbsp;</span> Libre
                        <span class="badge badge-primary ml-2">&nbsp;</span> Ocupado
                        <span class="badge badge-warning ml-2">&nbsp;</span> Reservado
                        <span class="badge badge-danger ml-2">&nbsp;</span> Dañado
                        <span class="text-muted ml-3">Haga clic en un puerto para ver o cambiar su estado.</span>
                    </div>
                </div>
            </div>

            {{-- ---------- Quién está conectado ---------- --}}
            <div class="card shadow-sm">
                <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <h3 class="card-title mb-0"><i class="fas fa-users mr-1"></i> Clientes conectados</h3>
                    @php
                        // Resumen de señal de la caja. Es lo que responde la
                        // pregunta que de verdad importa: si el promedio está
                        // mal y TODOS están mal, no es un cliente, es la caja
                        // o el hilo que la alimenta.
                        $potencias = $nap->ports
                            ->filter->estaOcupado()
                            ->map(fn ($p) => $p->contract?->ont?->rx_power)
                            ->filter(fn ($v) => $v !== null && $v !== '')
                            ->map(fn ($v) => (float) $v);
                    @endphp
                    @if($potencias->isNotEmpty())
                        <small class="text-muted">
                            Señal de la caja:
                            promedio <strong>{{ number_format($potencias->avg(), 2) }}</strong> dBm ·
                            peor <strong class="text-warning">{{ number_format($potencias->min(), 2) }}</strong> dBm
                            <span class="ml-1">({{ $potencias->count() }} con lectura)</span>
                        </small>
                    @endif
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th style="width: 70px;">Puerto</th>
                            <th>Contrato</th>
                            <th>Identificación</th>
                            <th>Cliente</th>
                            <th>Dirección</th>
                            <th>Estado</th>
                            {{-- La señal de la ONT del cliente. Es el dato que
                                 convierte esta pantalla en un diagnóstico: si
                                 TODOS los puertos de la caja están en rojo, el
                                 problema es de la caja o del hilo que la
                                 alimenta, no de un cliente suelto. --}}
                            <th class="text-center">Señal ONT</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($nap->ports->filter->estaOcupado() as $puerto)
                            @php
                                $contrato = $puerto->contract;
                            @endphp
                            <tr>
                                <td><span class="badge badge-primary">{{ $puerto->number }}</span></td>
                                <td>
                                    <a href="{{ route('contracts.show', $contrato) }}">
                                        <strong>{{ $contrato->numero_visible }}</strong>
                                    </a>
                                </td>
                                <td>{{ $contrato->client?->identity_number ?? '—' }}</td>
                                <td>{{ trim(($contrato->client?->name ?? '') . ' ' . ($contrato->client?->last_name ?? '')) ?: '—' }}</td>
                                <td><small>{{ $contrato->address }}</small></td>
                                <td>
                                    <span class="badge badge-{{ str_contains(strtolower($contrato->status ?? ''), 'activo') ? 'success' : 'warning' }}">
                                        {{ $contrato->status }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    @php
                                        $ont = $contrato->ont;
                                        $potencia = ($ont && $ont->rx_power !== null && $ont->rx_power !== '')
                                            ? (float) $ont->rx_power
                                            : null;
                                        $banda = $potencia !== null
                                            ? \App\Services\OltStatistics::bandaDe($potencia)
                                            : null;
                                        $bandas = \App\Services\OltStatistics::bandas();
                                    @endphp

                                    @if(!$ont)
                                        <span class="text-muted small">Sin ONT</span>
                                    @elseif($potencia === null)
                                        <span class="badge badge-light border" title="La ONT no tiene lectura de potencia">
                                            Sin lectura
                                        </span>
                                        <a href="{{ route('onts.show', $ont) }}" class="small d-block">{{ $ont->sn }}</a>
                                    @else
                                        <a href="{{ route('onts.show', $ont) }}"
                                           class="badge badge-{{ $bandas[$banda]['color'] ?? 'secondary' }}"
                                           title="{{ $bandas[$banda]['etiqueta'] ?? '' }} — {{ $ont->sn }}">
                                            {{ number_format($potencia, 2) }} dBm
                                        </a>
                                        @if((int) $ont->status !== 1)
                                            <small class="d-block text-danger">ONT caída</small>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    Ningún cliente conectado todavía en esta caja.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ---------- Datos y ubicación ---------- --}}
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-info-circle mr-1"></i> Datos de la caja</h3>
                </div>
                <div class="card-body py-2">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted">Estado</td>
                            <td>
                                <span class="badge badge-{{ $nap->status === 'operativa' ? 'success' : 'warning' }}">
                                    {{ $nap->estado_legible }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Red</td>
                            <td><a href="{{ route('networks.show', $nap->network) }}">{{ $nap->network->name }}</a></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Zona</td>
                            <td>
                                @if($nap->zone)
                                    <span class="badge" style="background: {{ $nap->zone->color }}; color:#fff;">
                                        {{ $nap->zone->name }}
                                    </span>
                                @else
                                    <span class="text-muted">Sin zona</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Puerto PON</td>
                            <td><code>{{ $nap->ponPort?->etiqueta ?? '—' }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">OLT</td>
                            <td>{{ $nap->ponPort?->olt?->name ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Splitter</td>
                            <td>{{ $nap->splitter_ratio ?? '—' }}</td>
                        </tr>
                        {{-- El hilo que la alimenta cierra la cadena
                             hasta la cabecera. Sin él, el análisis de
                             impacto de una mufla se corta justo antes de
                             esta caja y no llega a decir a qué clientes
                             afecta. --}}
                        <tr>
                            <td class="text-muted">Se alimenta de</td>
                            <td>
                                @if($nap->feedStrand)
                                    <a href="{{ route('cables.show', $nap->feedStrand->fiber_cable_id) }}">
                                        {{ $nap->feedStrand->cable->code }}
                                    </a>
                                    <span class="badge"
                                          style="background: {{ $nap->feedStrand->buffer_hex }}; color: {{ \App\Support\FiberColors::textoSobre($nap->feedStrand->buffer_color) }};">
                                        B{{ $nap->feedStrand->buffer_number }} {{ $nap->feedStrand->buffer_color }}
                                    </span>
                                    <span class="badge"
                                          style="background: {{ $nap->feedStrand->color_hex }}; color: {{ \App\Support\FiberColors::textoSobre($nap->feedStrand->strand_color) }};">
                                        H{{ $nap->feedStrand->strand_number }} {{ $nap->feedStrand->strand_color }}
                                    </span>
                                @else
                                    <span class="text-muted">Sin registrar</span>
                                    <a href="{{ route('naps.edit', $nap) }}" class="small ml-1">Anotarlo</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Dirección</td>
                            <td>{{ $nap->address ?? '—' }}</td>
                        </tr>
                        @if($nap->reference)
                            <tr>
                                <td class="text-muted">Referencia</td>
                                <td>{{ $nap->reference }}</td>
                            </tr>
                        @endif
                        @if($nap->notes)
                            <tr>
                                <td class="text-muted">Notas</td>
                                <td>{{ $nap->notes }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="text-muted">Registrada por</td>
                            <td><small>{{ $nap->user?->name }} {{ $nap->user?->last_name }}</small></td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- ============================================================
                 Por dónde le llega la fibra

                 Los tramos van de la cabecera al cliente, en el sentido
                 en que viaja la señal. Es lo que se recorre cuando hay
                 una atenuación alta y hay que decidir por dónde empezar
                 a medir.
                 ============================================================ --}}
            @if(!empty($ruta))
                <div class="card shadow-sm">
                    <div class="card-header py-2">
                        <h3 class="card-title"><i class="fas fa-route mr-1"></i> Por dónde le llega</h3>
                    </div>
                    <div class="card-body py-2">
                        @foreach($ruta as $tramo)
                            <div class="d-flex py-2 {{ !$loop->first ? 'border-top' : '' }}">
                                <div class="mr-2 text-muted">
                                    <i class="fas fa-{{ $loop->first ? 'server' : 'grip-lines' }}"></i>
                                </div>
                                <div>
                                    <strong>{{ $tramo['cable'] }}</strong>
                                    <span class="badge badge-light border">{{ $tramo['tipo'] }}</span>
                                    <small class="d-block text-muted">
                                        {{ $tramo['desde'] }} <i class="fas fa-arrow-right"></i> {{ $tramo['hasta'] }}
                                    </small>
                                    <small class="d-block text-muted">
                                        Hilo {{ $tramo['hilo'] }}
                                        @if($tramo['longitud_m'])
                                            · {{ number_format($tramo['longitud_m']) }} m
                                        @endif
                                    </small>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="card-footer py-2 text-muted small">
                        De la cabecera hacia el cliente, en el sentido en que viaja la señal.
                    </div>
                </div>
            @endif

            <div class="card shadow-sm">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-map-marker-alt mr-1"></i> Ubicación</h3>
                </div>
                <div class="card-body p-0">
                    @if($nap->estaGeorreferenciada())
                        <div id="mapaNap" style="height: 260px; z-index: 0;"></div>
                        <div class="p-2 text-center">
                            <a href="https://www.google.com/maps?q={{ $nap->latitude }},{{ $nap->longitude }}"
                               target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-directions"></i> Cómo llegar
                            </a>
                            <small class="d-block text-muted mt-1">
                                {{ $nap->latitude }}, {{ $nap->longitude }}
                            </small>
                        </div>
                    @else
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-map-marked-alt" style="font-size: 2rem;"></i>
                            <p class="mt-2 mb-2">Esta caja no está ubicada en el mapa.</p>
                            @can('naps.edit')
                                <a href="{{ route('naps.edit', $nap) }}" class="btn btn-sm btn-primary">
                                    Marcar su ubicación
                                </a>
                            @endcan
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ---------- Modal de puerto ---------- --}}
    <div class="modal fade" id="modalPuerto" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Puerto <span id="puertoNumero"></span></h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>

                <div class="modal-body">
                    {{-- Ocupado: se muestra quién y se ofrece liberarlo --}}
                    <div id="puertoOcupado" class="d-none">
                        <p>
                            Ocupado por el contrato <strong id="puertoContrato"></strong>
                            <span id="puertoCliente" class="d-block text-muted"></span>
                        </p>
                        @can('naps.edit')
                            <p class="text-muted small">
                                Liberarlo desconecta el contrato de este puerto. El servicio en la red
                                no se toca: es solo la documentación de dónde está conectado.
                            </p>
                        @endcan
                    </div>

                    {{-- Libre: se puede cambiar el estado --}}
                    <form method="POST" id="formEstadoPuerto" class="d-none">
                        @csrf
                        @method('PUT')
                        <div class="form-group">
                            <label>Estado del puerto</label>
                            <select name="status" id="puertoEstado" class="form-control">
                                @foreach(\App\Models\NapPort::estadosEditables() as $valor => $etiqueta)
                                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label>Nota</label>
                            <input type="text" name="notes" id="puertoNotas" class="form-control"
                                   placeholder="Ej.: conector quemado, reservado para instalación del viernes">
                        </div>
                    </form>
                </div>

                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    @can('naps.edit')
                        <div>
                            <form method="POST" id="formLiberarPuerto" class="d-inline d-none"
                                  onsubmit="return confirm('¿Liberar este puerto?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-unlink"></i> Liberar puerto
                                </button>
                            </form>
                            <button type="button" class="btn btn-primary d-none" id="btnGuardarEstado">
                                <i class="fas fa-save"></i> Guardar
                            </button>
                        </div>
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        /* Rejilla de puertos: cada puerto es un cuadro con su número.
           Se lee de un vistazo, que es lo que hace falta cuando hay
           que decir por radio "conéctelo en el 5". */
        .rejilla-puertos {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(78px, 1fr));
            gap: .5rem;
        }

        .puerto {
            border-radius: .35rem;
            padding: .5rem .25rem;
            text-align: center;
            cursor: pointer;
            color: #fff;
            transition: transform .1s;
        }

        .puerto:hover { transform: translateY(-2px); }
        .puerto .numero { display: block; font-size: 1.25rem; font-weight: 700; line-height: 1; }
        .puerto .ocupante { display: block; font-size: .65rem; opacity: .9; margin-top: .2rem; }

        /* La señal va debajo del contrato, en una franja propia sobre
           fondo translúcido: así se despega del color del cuadro (que
           indica el ESTADO del puerto) sin taparlo. */
        .puerto .senal {
            display: block;
            font-size: .62rem;
            font-weight: 700;
            margin-top: .2rem;
            padding: .05rem 0;
            border-radius: .2rem;
            background: rgba(255, 255, 255, .85);
        }

        /* Los mismos umbrales de siempre, pero en color de texto: sobre
           el cuadro azul de "ocupado" un segundo fondo de color no se
           distinguiría. */
        .puerto .senal-optima     { color: #1e7e34; }
        .puerto .senal-aceptable  { color: #117a8b; }
        .puerto .senal-debil      { color: #b8860b; }
        .puerto .senal-critica    { color: #bd2130; }
        .puerto .senal-saturacion { color: #bd2130; }

        .puerto .senal-sin {
            background: rgba(255, 255, 255, .25);
            color: #fff;
            font-weight: 400;
            font-style: italic;
        }

        .puerto-success { background: #28a745; }
        .puerto-primary { background: #007bff; }
        .puerto-warning { background: #ffc107; color: #212529; }
        .puerto-danger  { background: #dc3545; }

        @media print {
            .main-sidebar, .main-header, .btn, .card-tools { display: none !important; }
            .content-wrapper { margin-left: 0 !important; }
        }
    </style>
@endsection

@section('js')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        $(function () {
            @if($nap->estaGeorreferenciada())
                const mapa = L.map('mapaNap').setView([{{ $nap->latitude }}, {{ $nap->longitude }}], 17);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap',
                }).addTo(mapa);

                L.marker([{{ $nap->latitude }}, {{ $nap->longitude }}])
                    .addTo(mapa)
                    .bindPopup('{{ $nap->code }}');

                setTimeout(() => mapa.invalidateSize(), 200);
            @endif

            /* El modal se llena desde los data-* del cuadro pulsado:
               un solo modal sirve para los N puertos de la caja. */
            $('#modalPuerto').on('show.bs.modal', function (e) {
                const $p = $(e.relatedTarget);
                const ocupado = $p.data('ocupado') === 1;

                $('#puertoNumero').text($p.data('numero'));

                $('#puertoOcupado').toggleClass('d-none', !ocupado);
                $('#formEstadoPuerto').toggleClass('d-none', ocupado);
                $('#formLiberarPuerto').toggleClass('d-none', !ocupado).attr('action', $p.data('url-liberar'));
                $('#btnGuardarEstado').toggleClass('d-none', ocupado);

                if (ocupado) {
                    $('#puertoContrato').text($p.data('contrato'));
                    $('#puertoCliente').text($p.data('cliente'));
                } else {
                    $('#formEstadoPuerto').attr('action', $p.data('url-estado'));
                    $('#puertoEstado').val($p.data('estado'));
                    $('#puertoNotas').val($p.data('notas'));
                }
            });

            $('#btnGuardarEstado').on('click', () => $('#formEstadoPuerto').submit());
        });
    </script>
@endsection
