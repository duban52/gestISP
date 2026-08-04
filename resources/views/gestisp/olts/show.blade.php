{{-- ============================================================
     Ficha de una OLT

     Responde de un vistazo lo que se pregunta quien opera la red:
     cuántos clientes cuelgan de este equipo, cuántos están arriba,
     y —sobre todo— cómo anda la potencia óptica, que es lo que
     predice las fallas antes de que el cliente llame.

     Las cifras salen de la BASE (las mantienen al día `onts:poll` y
     `onts:sync-power`): pedírselas a la OLT en cada visita serían
     decenas de consultas SNMP y medio minuto de espera. Solo el
     estado del chasis se consulta en vivo, y en segundo plano.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'OLT ' . $olt->name)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-server mr-2"></i>{{ $olt->name }}
        </h1>
        <div>
            <a href="{{ route('onts.authorized') }}?olt={{ $olt->id }}" class="btn btn-outline-info">
                <i class="fas fa-network-wired"></i> Ver sus ONTs
            </a>
            <a href="{{ route('olts.edit', $olt) }}" class="btn btn-outline-primary">
                <i class="fas fa-cog"></i> Configuración
            </a>
            <a href="{{ route('olts.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')

@php
    $conteos = $resumen['conteos'];
    $potencia = $resumen['potencia'];
    $calidad = $resumen['calidad'];
    $puertos = $resumen['puertos'];
    $peores = $resumen['peores'];

    // Cuántas ONTs tienen alguna señal de alarma. Es el número que
    // decide si hay que salir a la calle hoy o no.
    $conProblema = $calidad['debil']['cantidad']
        + $calidad['critica']['cantidad']
        + $calidad['saturacion']['cantidad'];
@endphp

    {{-- ---------- Identidad y estado del equipo ---------- --}}
    <div class="card card-outline card-primary shadow-sm">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width: 130px;">Dirección IP</td>
                            <td><code>{{ $olt->ip_address }}</code></td>
                            <td class="text-muted" style="width: 90px;">Marca</td>
                            <td>{{ $olt->brand ?? '—' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Modelo</td>
                            <td>{{ $olt->model ?? '—' }}</td>
                            <td class="text-muted">Puerto SSH</td>
                            <td>{{ $olt->ssh_port }}</td>
                        </tr>
                    </table>
                </div>

                {{-- Estado en vivo: se pide al cargar la página, no
                     bloquea el resto del contenido. --}}
                <div class="col-md-5 border-left" id="estadoVivo"
                     data-url="{{ route('api.olts.status', $olt) }}">
                    <div class="text-center text-muted py-2">
                        <span class="spinner-border spinner-border-sm"></span>
                        Consultando el equipo...
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ---------- Cifras principales ---------- --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $conteos['total'] }}</h3>
                    <p class="mb-0">ONTs registradas</p>
                </div>
                <div class="icon"><i class="fas fa-network-wired"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ $conteos['en_linea'] }}</h3>
                    <p class="mb-0">En línea</p>
                </div>
                <div class="icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $conteos['caidas'] > 0 ? 'bg-gradient-danger' : 'bg-gradient-secondary' }}">
                <div class="inner">
                    <h3>{{ $conteos['caidas'] }}</h3>
                    <p class="mb-0">Caídas</p>
                </div>
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $conteos['disponibilidad'] }}<sup style="font-size:1.2rem">%</sup></h3>
                    <p class="mb-0">Disponibilidad</p>
                </div>
                <div class="icon"><i class="fas fa-heartbeat"></i></div>
            </div>
        </div>
    </div>

    {{-- La disponibilidad se calcula sobre las ONTs que DEBERÍAN estar
         dando servicio. Una cortada por facturación no es una falla de
         red, y contarla hundiría el indicador sin que nada esté mal. --}}
    @if($conteos['deshabilitadas'] > 0 || $conteos['sin_contrato'] > 0)
        <div class="alert alert-light border py-2">
            <i class="fas fa-info-circle text-muted"></i>
            @if($conteos['deshabilitadas'] > 0)
                <strong>{{ $conteos['deshabilitadas'] }}</strong> ONT(s) deshabilitadas a propósito
                (no cuentan como caídas ni bajan la disponibilidad).
            @endif
            @if($conteos['sin_contrato'] > 0)
                <strong>{{ $conteos['sin_contrato'] }}</strong> sin contrato asociado.
            @endif
        </div>
    @endif

    <div class="row">
        {{-- ---------- Potencia óptica ---------- --}}
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header py-2">
                    <h3 class="card-title">
                        <i class="fas fa-signal mr-1"></i> Calidad de la señal óptica
                    </h3>
                </div>
                <div class="card-body">
                    @if($potencia['medidas'] === 0)
                        <p class="text-muted mb-0 text-center py-4">
                            Todavía no hay lecturas de potencia.
                            Se toman con <code>php artisan onts:sync-power</code>.
                        </p>
                    @else
                        <div class="row text-center mb-3">
                            <div class="col-4">
                                <div class="text-muted small text-uppercase">Promedio</div>
                                <div class="h4 mb-0">{{ number_format($potencia['promedio'], 2) }} <small>dBm</small></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small text-uppercase">Mejor</div>
                                <div class="h4 mb-0 text-success">{{ number_format($potencia['mejor'], 2) }}</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small text-uppercase">Peor</div>
                                <div class="h4 mb-0 text-danger">{{ number_format($potencia['peor'], 2) }}</div>
                            </div>
                        </div>

                        {{-- Barra de reparto: de un vistazo se ve si la
                             red está sana o hay una franja en rojo. --}}
                        <div class="progress mb-3" style="height: 26px;">
                            @foreach($calidad as $banda)
                                @if($banda['cantidad'] > 0)
                                    <div class="progress-bar bg-{{ $banda['color'] }}"
                                         style="width: {{ round($banda['cantidad'] / $potencia['medidas'] * 100, 1) }}%"
                                         title="{{ $banda['etiqueta'] }}: {{ $banda['cantidad'] }} ONT(s)">
                                        {{ $banda['cantidad'] }}
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <table class="table table-sm mb-0">
                            <tbody>
                            @foreach($calidad as $banda)
                                <tr>
                                    <td style="width: 30px;">
                                        <span class="badge badge-{{ $banda['color'] }}">&nbsp;</span>
                                    </td>
                                    <td>{{ $banda['etiqueta'] }}</td>
                                    <td class="text-muted small">{{ $banda['rango'] }}</td>
                                    <td class="text-right"><strong>{{ $banda['cantidad'] }}</strong></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        <small class="text-muted d-block mt-2">
                            Sobre {{ $potencia['medidas'] }} ONT(s) en línea con lectura.
                            Rangos de ópticas clase B+/C+; el receptor deja de funcionar
                            cerca de −28 dBm.
                        </small>
                    @endif
                </div>
            </div>
        </div>

        {{-- ---------- Ocupación de los puertos PON ---------- --}}
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-project-diagram mr-1"></i> Puertos PON</h3>
                </div>
                <div class="card-body py-2" style="max-height: 420px; overflow-y: auto;">
                    @forelse($puertos as $puerto)
                        <div class="d-flex justify-content-between align-items-center py-1">
                            <span><code>{{ $puerto['puerto'] }}</code></span>
                            <span>
                                <span class="text-success">{{ $puerto['en_linea'] }}</span>
                                <span class="text-muted">/ {{ $puerto['total'] }}</span>
                                {{-- Un puerto GPON admite hasta 128 ONTs por
                                     norma, pero se reparte entre 32 y 64 para
                                     no quedarse sin ancho de banda. --}}
                                @if($puerto['total'] >= 64)
                                    <span class="badge badge-warning ml-1" title="Conviene balancear antes de seguir instalando">
                                        Cargado
                                    </span>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="text-muted mb-0 text-center py-4">
                            No hay ONTs registradas en esta OLT.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ---------- ONTs a revisar ---------- --}}
    @if($peores->isNotEmpty())
        <div class="card shadow-sm">
            <div class="card-header py-2">
                <h3 class="card-title">
                    <i class="fas fa-tools mr-1"></i> ONTs con peor señal
                    @if($conProblema > 0)
                        <span class="badge badge-warning ml-1">{{ $conProblema }} fuera de rango</span>
                    @endif
                </h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>Potencia</th>
                        <th>Serial</th>
                        <th>Ubicación</th>
                        <th>Contrato</th>
                        <th>Cliente</th>
                        <th class="text-center">Ver</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($peores as $ont)
                        @php
                            $banda = \App\Services\OltStatistics::bandaDe((float) $ont->rx_power);
                            $color = $calidad[$banda]['color'] ?? 'secondary';
                        @endphp
                        <tr>
                            <td>
                                <span class="badge badge-{{ $color }}" style="font-size: .9rem;">
                                    {{ number_format((float) $ont->rx_power, 2) }} dBm
                                </span>
                            </td>
                            <td><code>{{ $ont->sn }}</code></td>
                            <td>{{ $ont->slot }}/{{ $ont->port }}/{{ $ont->onu_id }}</td>
                            <td>{{ $ont->contract?->numero_visible ?? '—' }}</td>
                            <td>
                                {{ trim(($ont->contract?->client?->name ?? '') . ' ' . ($ont->contract?->client?->last_name ?? '')) ?: '—' }}
                            </td>
                            <td class="text-center">
                                <a href="{{ route('onts.show', $ont) }}" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer py-2 text-muted small">
                Ordenadas de peor a mejor. Una señal débil falla con lluvia o con calor:
                son las quejas de "se me va a ratos".
            </div>
        </div>
    @endif

@endsection

@section('js')
    <script>
        $(function () {
            /* El estado del chasis se consulta aparte para que la
               ficha aparezca completa aunque la OLT no responda. */
            const $caja = $('#estadoVivo');

            fetch($caja.data('url'))
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(function (datos) {
                    $caja.html(
                        '<div class="row text-center">' +
                        '  <div class="col-4">' +
                        '    <div class="text-muted small text-uppercase">Estado</div>' +
                        '    <span class="badge badge-' + (datos.conectada ? 'success' : 'danger') + '" style="font-size:.9rem;">' +
                        datos.status_text + '</span>' +
                        '  </div>' +
                        '  <div class="col-4">' +
                        '    <div class="text-muted small text-uppercase">Temperatura</div>' +
                        '    <div>' + (datos.temperature && datos.temperature !== 'N/A' ? datos.temperature : '—') + '</div>' +
                        '  </div>' +
                        '  <div class="col-4">' +
                        '    <div class="text-muted small text-uppercase">Uptime</div>' +
                        '    <div>' + (datos.uptime && datos.uptime !== 'N/A' ? datos.uptime : '—') + '</div>' +
                        '  </div>' +
                        '</div>'
                    );
                })
                .catch(function () {
                    $caja.html(
                        '<div class="text-center text-muted py-2">' +
                        '  <i class="fas fa-plug"></i> No se pudo consultar el equipo.' +
                        '</div>'
                    );
                });
        });
    </script>
@endsection
