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

{{-- Sin esto, un "descubrir puertos" que falla recarga la página en
     silencio y parece que el botón no hace nada: el mensaje que
     explica POR QUÉ falló se pierde. --}}
@include('gestisp.networks.partials.alertas')

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

        {{-- ---------- Resumen rápido de puertos ---------- --}}
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-project-diagram mr-1"></i> Puertos PON</h3>
                </div>
                <div class="card-body py-3">
                    @php
                        $ponArriba = $ponPorts->where('oper_status', 'up')->count();
                        $ponConCajas = $ponPorts->where('nap_boxes_count', '>', 0)->count();
                        $ponLibres = $ponPorts->filter(fn ($p) => ($p->onts_total ?? 0) === 0)->count();
                    @endphp

                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="text-muted small">Documentados</div>
                            <div class="h3 mb-0">{{ $ponPorts->count() }}</div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="text-muted small">Activos</div>
                            <div class="h3 mb-0 text-success">{{ $ponArriba }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Sin ninguna ONT</div>
                            <div class="h3 mb-0 {{ $ponLibres > 0 ? 'text-info' : 'text-muted' }}">{{ $ponLibres }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Con cajas</div>
                            <div class="h3 mb-0">{{ $ponConCajas }}</div>
                        </div>
                    </div>

                    @if($olt->optical_network_id)
                        <div class="mt-3 pt-2 border-top text-center">
                            <a href="{{ route('networks.show', $olt->optical_network_id) }}"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-sitemap"></i> Ver la red de esta OLT
                            </a>
                        </div>
                    @else
                        {{-- Los puertos se descubren y se ven igual sin
                             red: son del equipo. Lo que hace falta una
                             red es DOCUMENTARLOS —colgarles cajas y
                             repartirlos en zonas—, y eso es lo que dice
                             este aviso, sin bloquear nada. --}}
                        <div class="alert alert-light border mt-3 mb-0 py-2 small">
                            Esta OLT no pertenece a ninguna red documentada. Sus puertos se
                            descubren y se consultan igual, pero para colgarles cajas NAP o
                            repartirlos en zonas hay que
                            <a href="{{ route('networks.index') }}">asignarla a una red</a>.
                            Al hacerlo, los puertos ya descubiertos la adoptan solos.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Puertos PON, por tarjeta

         Una MA5800 puede pasar de los doscientos puertos: una rejilla
         plana de doscientas casillas no hay quien la lea. Se agrupa por
         tarjeta porque es como está el equipo en el rack y como habla
         de él la gente de planta ("la del slot 3").

         Cada casilla dice de un vistazo lo único que se mira desde
         lejos: si el puerto está arriba y cuántas ONTs tiene. Lo demás
         —potencia, tráfico, cajas— se abre en el modal, para no cargar
         la serie de tráfico de doscientos puertos al abrir la ficha.
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h3 class="card-title mb-0">
                    <i class="fas fa-th mr-1"></i> Tarjetas y puertos
                    @if($tarjetas->isNotEmpty())
                        <span class="badge badge-secondary ml-1">{{ $tarjetas->count() }} tarjeta(s)</span>
                    @endif
                </h3>

                <form method="POST" action="{{ route('olts.discover_ports', $olt) }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary" id="btnDescubrir">
                        <i class="fas fa-sync"></i> Descubrir puertos
                    </button>
                </form>
            </div>
        </div>

        {{-- ============================================================
             Puertos con clientes que NO están en el inventario.

             Son la señal de que alguien conectó ONTs a un puerto que
             nadie registró: existe en el equipo y da servicio, pero no
             se puede documentar una caja ahí ni aparece al planear.
             Es exactamente lo que el descubrimiento viene a resolver.
             ============================================================ --}}
        @php
            $documentados = $ponPorts->map(fn ($p) => $p->slot . '/' . $p->port)->all();
            $enUsoSinInventario = collect($puertos)
                ->reject(fn ($p) => in_array($p['puerto'], $documentados, true))
                ->values();
        @endphp

        @if($enUsoSinInventario->isNotEmpty())
            <div class="card-body pb-0">
                <div class="alert alert-warning py-2 mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    Hay <strong>{{ $enUsoSinInventario->count() }}</strong> puerto(s) con ONTs conectadas
                    que no están en el inventario:
                    @foreach($enUsoSinInventario as $suelto)
                        <code class="ml-1">{{ $suelto['puerto'] }}</code>@if(!$loop->last),@endif
                    @endforeach
                    <br>
                    <small>Pulse <strong>Descubrir puertos</strong> para traerlos del equipo.</small>
                </div>
            </div>
        @endif

        @if($ponPorts->isEmpty())
            <div class="card-body text-center py-4">
                <p class="text-muted mb-2">
                    Todavía no se han descubierto los puertos de esta OLT.
                </p>
                <p class="text-muted small mb-0">
                    Pulse <strong>Descubrir puertos</strong> para que el equipo diga qué tarjetas
                    y qué puertos PON tiene — incluidos los que están vacíos, que son
                    justamente los que sirven para planear dónde crecer.
                </p>
            </div>
        @else
            <div class="card-header py-2 bg-light">
                <div class="row align-items-end">
                    <div class="col-md-4 form-group mb-0">
                        <label class="mb-1 small">Buscar</label>
                        <input type="text" id="buscarPuerto" class="form-control form-control-sm"
                               placeholder="0/1/3, una zona, una descripción…">
                    </div>
                    <div class="col-md-3 form-group mb-0">
                        <label class="mb-1 small">Mostrar</label>
                        <select id="filtroPuerto" class="form-control form-control-sm">
                            <option value="">Todos los puertos</option>
                            <option value="ocupados">Con ONTs</option>
                            <option value="libres">Sin ninguna ONT</option>
                            <option value="cargados">Cargados (sobre el tope)</option>
                            <option value="caidos">Caídos o deshabilitados</option>
                            <option value="sin_documentar">Sin cajas documentadas</option>
                        </select>
                    </div>
                    <div class="col-md-5 text-md-right">
                        <small class="text-muted d-block mb-1">Ocupación del puerto</small>
                        <span class="badge badge-success">&nbsp;</span> <small>Holgado</small>
                        <span class="badge badge-info ml-1">&nbsp;</span> <small>Medio</small>
                        <span class="badge badge-warning ml-1">&nbsp;</span> <small>Cargado</small>
                        <span class="badge badge-danger ml-1">&nbsp;</span> <small>Al tope</small>
                        <span class="badge badge-secondary ml-1">&nbsp;</span> <small>Caído</small>
                    </div>
                </div>
            </div>

            <div class="card-body pt-2">
                @foreach($puertosPorTarjeta as $posicion => $puertosTarjeta)
                    @php
                        $tarjeta = $tarjetas->first(fn ($t) => $t->posicion === $posicion);
                        $ontsTarjeta = $puertosTarjeta->sum(fn ($p) => $p->onts_total ?? 0);
                    @endphp

                    <div class="tarjeta-olt mb-3">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
                            <h6 class="mb-0">
                                <i class="fas fa-microchip text-muted mr-1"></i>
                                {{ $tarjeta?->etiqueta ?? 'Tarjeta ' . $posicion }}
                                <small class="text-muted ml-1">{{ $posicion }}</small>
                            </h6>
                            <small class="text-muted">
                                {{ $puertosTarjeta->count() }} puerto(s) · {{ $ontsTarjeta }} ONT(s)
                            </small>
                        </div>

                        <div class="d-flex flex-wrap">
                            @foreach($puertosTarjeta as $puerto)
                                @php
                                    $total = $puerto->onts_total ?? 0;
                                    $tope = max($puerto->max_onts ?: 64, 1);
                                    $ocupacion = round($total / $tope * 100, 1);

                                    // El color habla de CAPACIDAD, no de salud: dice
                                    // si todavía se puede instalar ahí. Un puerto
                                    // caído se pinta gris porque en ese momento la
                                    // capacidad no es la pregunta.
                                    $color = match (true) {
                                        $puerto->oper_status === 'down' => 'secondary',
                                        $ocupacion >= 100 => 'danger',
                                        $ocupacion >= 80 => 'warning',
                                        $ocupacion >= 40 => 'info',
                                        default => 'success',
                                    };

                                    $etiquetas = collect([
                                        $puerto->etiqueta,
                                        $puerto->description,
                                        $puerto->zone?->name,
                                    ])->filter()->implode(' ');
                                @endphp

                                <button type="button"
                                        class="btn btn-{{ $color }} puerto-pon m-1"
                                        data-puerto="{{ $puerto->id }}"
                                        data-url="{{ route('api.pon_ports.show', $puerto->id) }}"
                                        data-buscar="{{ Str::lower($etiquetas) }}"
                                        data-onts="{{ $total }}"
                                        data-ocupacion="{{ $ocupacion }}"
                                        data-cajas="{{ $puerto->nap_boxes_count }}"
                                        data-estado="{{ $puerto->oper_status }}"
                                        title="{{ $puerto->etiqueta }} — {{ $puerto->estado_legible }}, {{ $total }} de {{ $tope }} ONTs">
                                    <span class="d-block puerto-numero">{{ $puerto->port }}</span>
                                    <span class="d-block puerto-onts">{{ $total }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <p id="sinPuertos" class="text-muted text-center py-3 mb-0" style="display:none;">
                    Ningún puerto coincide con lo buscado.
                </p>
            </div>

            <div class="card-footer py-2 text-muted small">
                <i class="fas fa-mouse-pointer"></i>
                Haga clic en un puerto para ver su potencia, su tráfico y las cajas que cuelgan de él.
                @if($ponPorts->whereNull('discovered_at')->isNotEmpty())
                    <span class="text-warning ml-2">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ $ponPorts->whereNull('discovered_at')->count() }} puerto(s) documentados que el equipo
                        nunca reportó: revise el slot y el puerto.
                    </span>
                @endif
            </div>
        @endif
    </div>

    {{-- ============================================================
         Uplinks

         Por aquí sale TODO el tráfico del equipo. Cuando "está lento
         el internet" y las potencias están bien, esto es lo siguiente
         que hay que mirar: un puerto de 1 G moviendo 950 Mbps explica
         de golpe la queja de todos los clientes de esta OLT.
         ============================================================ --}}
    {{-- La tarjeta se muestra SIEMPRE, también vacía. Esconderla cuando
         no hay uplinks hace que "no se detectó ninguno" se vea igual que
         "esta versión no está desplegada", y no hay forma de distinguir
         un fallo de una ausencia. --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-arrow-up mr-1"></i> Puertos de subida</h3>
        </div>

        @if($uplinks->isEmpty())
            <div class="card-body text-center py-4">
                <p class="text-muted mb-1">No se ha detectado ningún puerto de subida.</p>
                <p class="text-muted small mb-0">
                    Se descubren junto con los puertos PON. Si la OLT sí los tiene,
                    es que este equipo los nombra de una forma que el patrón no reconoce:
                    compruébelo con <code>php artisan olt:probe-ports {{ $olt->id }} --interfaces</code>.
                </p>
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>Puerto</th>
                            <th>Descripción</th>
                            <th class="text-center">Estado</th>
                            <th class="text-right">Velocidad</th>
                            <th class="text-right">Bajada</th>
                            <th class="text-right">Subida</th>
                            <th style="width: 180px;">Uso</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($uplinks as $uplink)
                            <tr>
                                <td><code>{{ $uplink->name }}</code></td>
                                <td><small class="text-muted">{{ $uplink->description ?: '—' }}</small></td>
                                <td class="text-center">
                                    <span class="badge badge-{{ $uplink->estaArriba() ? 'success' : 'danger' }}">
                                        {{ $uplink->estaArriba() ? 'Arriba' : 'Abajo' }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    {{ $uplink->speed_mbps ? number_format($uplink->speed_mbps) . ' Mbps' : '—' }}
                                </td>
                                <td class="text-right">{{ \App\Services\OltStatistics::formatoBps($uplink->in_bps) }}</td>
                                <td class="text-right">{{ \App\Services\OltStatistics::formatoBps($uplink->out_bps) }}</td>
                                <td>
                                    @if($uplink->uso === null)
                                        <small class="text-muted">Sin medir</small>
                                    @else
                                        <div class="progress" style="height: 18px;">
                                            <div class="progress-bar bg-{{ $uplink->color_uso }}"
                                                 style="width: {{ min($uplink->uso, 100) }}%;">
                                                {{ $uplink->uso }}%
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer py-2 text-muted small">
                El uso se mide sobre la dirección más cargada, no sobre la suma: un enlace
                full-duplex da su velocidad completa en cada sentido, así que lo que satura
                es la peor de las dos.
                @if($uplinks->max('measured_at'))
                    <span class="ml-2">Última medida {{ $uplinks->max('measured_at')->diffForHumans() }}.</span>
                @endif
            </div>
        @endif
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

    {{-- ============================================================
         Modal de un puerto

         Se llena por AJAX al abrirlo. Un solo modal reutilizado para
         los doscientos puertos: pintar doscientos modales ocultos en el
         HTML pesaría más que la propia ficha.
         ============================================================ --}}
    <div class="modal fade" id="modalPuerto" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">
                        <i class="fas fa-project-diagram mr-1"></i>
                        Puerto <span id="mpEtiqueta">—</span>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="mpCuerpo">
                    <div class="text-center py-4 text-muted">
                        <span class="spinner-border spinner-border-sm"></span> Consultando el puerto…
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('css')
    <style>
        /* Casilla de puerto: cuadrada y compacta, para que quepan
           veinte por fila sin que la tarjeta ocupe media pantalla. */
        .puerto-pon {
            width: 52px;
            padding: .25rem 0;
            line-height: 1.1;
        }

        .puerto-pon .puerto-numero {
            font-weight: 700;
            font-size: .95rem;
        }

        .puerto-pon .puerto-onts {
            font-size: .65rem;
            opacity: .85;
        }
    </style>
@endsection

@section('js')
    {{-- Chart.js dibuja el tráfico del modal de puerto. Es la misma
         versión que usa la ficha de la ONT. --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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

            /* ============================================================
               Buscador y filtros de la rejilla de puertos

               Se filtra en el navegador y no en el servidor: los puertos
               ya están todos en la página, y una OLT no pasa de unos
               cientos. Ir al servidor por cada letra sería más lento y
               perdería el scroll.
               ============================================================ */
            const $puertos = $('.puerto-pon');

            function filtrarPuertos() {
                const texto = ($('#buscarPuerto').val() || '').toLowerCase().trim();
                const criterio = $('#filtroPuerto').val();
                let visibles = 0;

                $puertos.each(function () {
                    const $p = $(this);
                    const onts = parseInt($p.data('onts'), 10) || 0;
                    const ocupacion = parseFloat($p.data('ocupacion')) || 0;
                    const cajas = parseInt($p.data('cajas'), 10) || 0;
                    const estado = $p.data('estado');

                    let coincide = texto === '' || String($p.data('buscar')).indexOf(texto) !== -1;

                    if (coincide && criterio === 'ocupados') coincide = onts > 0;
                    if (coincide && criterio === 'libres') coincide = onts === 0;
                    if (coincide && criterio === 'cargados') coincide = ocupacion >= 80;
                    if (coincide && criterio === 'caidos') coincide = estado !== 'up';
                    if (coincide && criterio === 'sin_documentar') coincide = cajas === 0;

                    $p.toggle(coincide);
                    if (coincide) visibles++;
                });

                // Una tarjeta sin ningún puerto visible se esconde
                // entera: si no, quedan encabezados sueltos sin nada
                // debajo y parece que la pantalla se rompió.
                $('.tarjeta-olt').each(function () {
                    $(this).toggle($(this).find('.puerto-pon:visible').length > 0);
                });

                $('#sinPuertos').toggle(visibles === 0);
            }

            $('#buscarPuerto').on('input', filtrarPuertos);
            $('#filtroPuerto').on('change', filtrarPuertos);

            /* ============================================================
               Modal de un puerto
               ============================================================ */
            let graficaTrafico = null;

            $puertos.on('click', function () {
                const url = $(this).data('url');

                $('#mpEtiqueta').text('—');
                $('#mpCuerpo').html(
                    '<div class="text-center py-4 text-muted">' +
                    '<span class="spinner-border spinner-border-sm"></span> Consultando el puerto…' +
                    '</div>'
                );
                $('#modalPuerto').modal('show');

                fetch(url)
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(pintarPuerto)
                    .catch(function () {
                        $('#mpCuerpo').html(
                            '<div class="alert alert-light border mb-0">' +
                            '<i class="fas fa-plug text-muted"></i> No se pudo consultar el puerto.' +
                            '</div>'
                        );
                    });
            });

            // Al cerrar se destruye la gráfica: Chart.js deja el canvas
            // ocupado y el siguiente puerto no dibujaría nada.
            $('#modalPuerto').on('hidden.bs.modal', function () {
                if (graficaTrafico) {
                    graficaTrafico.destroy();
                    graficaTrafico = null;
                }
            });

            function formatoBps(bps) {
                if (bps === null || bps === undefined) return '—';
                // Múltiplos de 1000, no de 1024: el ancho de banda de
                // red se mide en unidades decimales.
                if (bps >= 1e9) return (bps / 1e9).toFixed(2) + ' Gbps';
                if (bps >= 1e6) return (bps / 1e6).toFixed(1) + ' Mbps';
                if (bps >= 1e3) return Math.round(bps / 1e3) + ' kbps';
                return bps + ' bps';
            }

            function pintarPuerto(d) {
                const p = d.puerto;
                const o = d.onts;

                $('#mpEtiqueta').text(p.etiqueta);

                let html = '<div class="row">';

                // ---- Estado y ópticas ----
                html += '<div class="col-md-6">';
                html += '<table class="table table-sm mb-3"><tbody>';
                html += fila('Estado', '<span class="badge badge-' + p.color_estado + '">' + escapar(p.estado) + '</span>');
                html += fila('Potencia Tx del puerto', p.tx_power !== null
                    ? '<strong>' + p.tx_power.toFixed(2) + '</strong> dBm'
                    : '<span class="text-muted">Sin dato</span>');
                html += fila('Bajada ahora', '<strong>' + formatoBps(p.in_bps) + '</strong>');
                html += fila('Subida ahora', '<strong>' + formatoBps(p.out_bps) + '</strong>');
                if (p.medido_en) {
                    html += fila('Última medida', '<small class="text-muted">' + escapar(p.medido_en) + '</small>');
                }
                html += '</tbody></table>';
                html += '</div>';

                // ---- Clientes del puerto ----
                html += '<div class="col-md-6">';
                html += '<table class="table table-sm mb-3"><tbody>';
                html += fila('ONTs', '<strong>' + o.total + '</strong> ' +
                    '<span class="text-success">' + o.en_linea + ' en línea</span>' +
                    (o.fuera > 0 ? ' · <span class="text-danger">' + o.fuera + ' fuera</span>' : ''));
                if (o.ocupacion !== null) {
                    html += fila('Ocupación', barra(o.ocupacion) +
                        '<small class="text-muted">' + o.total + ' de ' + p.max_onts + '</small>');
                }
                html += fila('Potencia media', o.potencia_media !== null
                    ? o.potencia_media.toFixed(2) + ' dBm'
                    : '<span class="text-muted">Sin lecturas</span>');
                html += fila('Peor señal', o.peor !== null
                    ? '<span class="text-warning">' + o.peor.toFixed(2) + '</span> dBm'
                    : '<span class="text-muted">—</span>');
                if (p.zona) html += fila('Zona', escapar(p.zona));
                if (p.splitter) html += fila('Splitter', escapar(p.splitter));
                html += '</tbody></table>';
                html += '</div>';
                html += '</div>';

                // ---- Gráfica ----
                if (d.trafico.muestras.length > 1) {
                    html += '<h6 class="text-uppercase text-muted small">' +
                        'Tráfico de las últimas ' + d.trafico.horas + ' horas</h6>';
                    html += '<canvas id="graficaPuerto" height="90"></canvas>';
                } else {
                    html += '<div class="alert alert-light border py-2 small mb-3">' +
                        '<i class="fas fa-chart-line text-muted"></i> ' +
                        'Todavía no hay suficientes muestras para dibujar el tráfico. ' +
                        'Se toman cada cinco minutos con <code>olt:poll-ports</code>.' +
                        '</div>';
                }

                // ---- Cajas colgadas ----
                if (d.cajas.length > 0) {
                    html += '<h6 class="text-uppercase text-muted small mt-3">Cajas que cuelgan del puerto</h6>';
                    html += '<div class="d-flex flex-wrap">';
                    d.cajas.forEach(function (c) {
                        html += '<a href="' + c.url + '" class="btn btn-sm btn-outline-secondary m-1">' +
                            '<strong>' + escapar(c.codigo) + '</strong> ' +
                            '<span class="badge badge-light border ml-1">' + c.ocupacion.porcentaje + '%</span>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // ---- Peores ONTs ----
                if (d.peores_onts.length > 0) {
                    html += '<h6 class="text-uppercase text-muted small mt-3">ONTs con peor señal aquí</h6>';
                    html += '<table class="table table-sm mb-0"><tbody>';
                    d.peores_onts.forEach(function (ont) {
                        html += '<tr>' +
                            '<td><code>' + escapar(ont.sn) + '</code></td>' +
                            '<td>' + escapar(ont.cliente || ont.contrato || '—') + '</td>' +
                            '<td class="text-right"><strong>' + ont.rx_power.toFixed(2) + '</strong> dBm</td>' +
                            '<td class="text-center"><a href="' + ont.url + '" class="btn btn-xs btn-outline-info">' +
                            '<i class="fas fa-eye"></i></a></td>' +
                            '</tr>';
                    });
                    html += '</tbody></table>';
                }

                $('#mpCuerpo').html(html);

                if (d.trafico.muestras.length > 1) {
                    dibujarTrafico(d.trafico.muestras);
                }
            }

            function dibujarTrafico(muestras) {
                const lienzo = document.getElementById('graficaPuerto');

                if (!lienzo || typeof Chart === 'undefined') {
                    return;
                }

                graficaTrafico = new Chart(lienzo, {
                    type: 'line',
                    data: {
                        labels: muestras.map(m => m.momento),
                        datasets: [
                            {
                                label: 'Bajada',
                                data: muestras.map(m => m.in),
                                borderColor: '#17a2b8',
                                backgroundColor: 'rgba(23,162,184,.15)',
                                fill: true,
                                tension: .3,
                                pointRadius: 0,
                            },
                            {
                                label: 'Subida',
                                data: muestras.map(m => m.out),
                                borderColor: '#28a745',
                                backgroundColor: 'rgba(40,167,69,.12)',
                                fill: true,
                                tension: .3,
                                pointRadius: 0,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: c => c.dataset.label + ': ' + formatoBps(c.parsed.y),
                                },
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { callback: v => formatoBps(v) },
                            },
                        },
                    },
                });
            }

            function fila(titulo, valor) {
                return '<tr><td class="text-muted" style="width:45%">' + titulo + '</td><td>' + valor + '</td></tr>';
            }

            function barra(porcentaje) {
                const color = porcentaje >= 100 ? 'danger'
                    : porcentaje >= 80 ? 'warning'
                        : porcentaje >= 40 ? 'info' : 'success';

                return '<div class="progress mb-1" style="height:14px;">' +
                    '<div class="progress-bar bg-' + color + '" style="width:' + Math.min(porcentaje, 100) + '%">' +
                    porcentaje + '%</div></div>';
            }

            function escapar(v) {
                return $('<div>').text(v == null ? '' : v).html();
            }

            /* El descubrimiento puede tardar unos segundos en una OLT
               con muchas interfaces: se avisa para que nadie pulse dos
               veces creyendo que no pasó nada. */
            $('#btnDescubrir').closest('form').on('submit', function () {
                $('#btnDescubrir')
                    .prop('disabled', true)
                    .html('<span class="spinner-border spinner-border-sm"></span> Consultando el equipo…');
            });
        });
    </script>
@endsection
