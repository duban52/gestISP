{{-- ============================================================
     Ficha de una red óptica

     Reúne las tres capas de arriba de la jerarquía: qué OLTs la
     alimentan, qué puertos PON salen de ellas y en qué zonas se
     agrupan. Las cajas viven en su propia pantalla porque son muchas
     más y tienen mapa.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Red ' . $network->name)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-sitemap mr-2"></i>{{ $network->name }}</h1>
        <div>
            <a href="{{ route('naps.index', ['network_id' => $network->id]) }}" class="btn btn-outline-primary">
                <i class="fas fa-box"></i> Cajas de esta red
            </a>
            @can('networks.edit')
                <a href="{{ route('networks.edit', $network) }}" class="btn btn-outline-secondary">
                    <i class="fas fa-cog"></i> Configurar
                </a>
            @endcan
            <a href="{{ route('networks.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    {{-- ---------- Cifras de la red ---------- --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $resumen['cajas'] }}</h3>
                    <p class="mb-0">Cajas NAP</p>
                </div>
                <div class="icon"><i class="fas fa-box"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ $resumen['disponibles'] }}</h3>
                    <p class="mb-0">Puertos libres</p>
                </div>
                <div class="icon"><i class="fas fa-plug"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $resumen['ocupados'] }}<sup style="font-size:1.2rem">/{{ $resumen['capacidad'] }}</sup></h3>
                    <p class="mb-0">Puertos ocupados</p>
                </div>
                <div class="icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $resumen['sin_ubicar'] > 0 ? 'bg-gradient-warning' : 'bg-gradient-secondary' }}">
                <div class="inner">
                    <h3>{{ $resumen['sin_ubicar'] }}</h3>
                    <p class="mb-0">Cajas sin ubicar</p>
                </div>
                <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
            </div>
        </div>
    </div>

    @if($resumen['sin_ubicar'] > 0)
        <div class="alert alert-warning py-2">
            <i class="fas fa-map-marked-alt"></i>
            Hay <strong>{{ $resumen['sin_ubicar'] }}</strong> caja(s) sin coordenadas: no aparecen en el mapa
            ni se pueden encontrar por cercanía a una dirección.
        </div>
    @endif

    <div class="row">
        {{-- ============================================================
             OLTs de la red
             ============================================================ --}}
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-server mr-1"></i> OLTs</h3>
                </div>
                <div class="card-body py-2">
                    @forelse($network->olts as $olt)
                        <div class="d-flex justify-content-between align-items-center py-1">
                            <span>
                                <a href="{{ route('olts.show', $olt) }}">{{ $olt->name }}</a>
                                <small class="d-block text-muted">{{ $olt->ip_address }}</small>
                            </span>
                            @can('networks.edit')
                                <form method="POST" action="{{ route('networks.olts.detach', [$network, $olt]) }}"
                                      onsubmit="return confirm('¿Quitar {{ $olt->name }} de esta red?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-link text-danger" title="Quitar de la red">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            @endcan
                        </div>
                    @empty
                        <p class="text-muted mb-0 py-2">
                            Ninguna OLT asignada. Sin OLT no se pueden registrar puertos PON.
                        </p>
                    @endforelse

                    @can('networks.edit')
                        @if($oltsLibres->isNotEmpty())
                            <form method="POST" action="{{ route('networks.olts.attach', $network) }}"
                                  class="form-inline mt-2">
                                @csrf
                                <select name="olt_id" class="form-control form-control-sm mr-1" required>
                                    <option value="">Agregar OLT…</option>
                                    @foreach($oltsLibres as $olt)
                                        <option value="{{ $olt->id }}">{{ $olt->name }}</option>
                                    @endforeach
                                </select>
                                <button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i></button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>

            {{-- ============================================================
                 Zonas

                 Es la capa con la que se PLANEA: una caja llena se
                 resuelve con otra caja; una zona llena se resuelve con
                 otro puerto PON, que es una obra distinta.
                 ============================================================ --}}
            <div class="card shadow-sm">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-layer-group mr-1"></i> Zonas</h3>
                </div>
                <div class="card-body py-2">
                    @forelse($network->zones as $zona)
                        @php
                            $oc = $zona->ocupacion();
                        @endphp
                        <div class="py-2 {{ !$loop->first ? 'border-top' : '' }}">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <span class="badge" style="background: {{ $zona->color }}; color:#fff;">&nbsp;</span>
                                    <strong>{{ $zona->name }}</strong>
                                </span>
                                <span>
                                    <span class="badge badge-{{ \App\Models\PonPort::colorDeOcupacion($oc['porcentaje']) }}">
                                        {{ $oc['porcentaje'] }}%
                                    </span>
                                    @can('networks.edit')
                                        <form method="POST" action="{{ route('zones.destroy', $zona) }}" class="d-inline"
                                              onsubmit="return confirm('¿Eliminar la zona {{ $zona->name }}? Sus cajas quedarán sin zona.');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-link text-danger p-0 ml-1"><i class="fas fa-times"></i></button>
                                        </form>
                                    @endcan
                                </span>
                            </div>
                            <small class="text-muted">
                                {{ $oc['ocupados'] }} de {{ $oc['capacidad'] }} puertos ·
                                {{ $zona->napBoxes->count() }} caja(s)
                            </small>
                        </div>
                    @empty
                        <p class="text-muted mb-0 py-2">
                            Sin zonas. Son opcionales, pero permiten ver la saturación por sector.
                        </p>
                    @endforelse

                    @can('networks.edit')
                        <form method="POST" action="{{ route('zones.store', $network) }}" class="mt-3 border-top pt-2">
                            @csrf
                            <div class="form-row">
                                <div class="col-7">
                                    <input type="text" name="name" class="form-control form-control-sm"
                                           placeholder="Nueva zona" required>
                                </div>
                                <div class="col-3">
                                    <input type="color" name="color" class="form-control form-control-sm p-0"
                                           value="#3388ff" title="Color en el mapa">
                                </div>
                                <div class="col-2">
                                    <button class="btn btn-sm btn-primary btn-block"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                        </form>
                    @endcan
                </div>
            </div>
        </div>

        {{-- ============================================================
             Puertos PON
             ============================================================ --}}
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0"><i class="fas fa-plug mr-1"></i> Puertos PON</h3>
                    @can('networks.edit')
                        <div>
                            @if($network->olts->isNotEmpty())
                                {{-- Documentar a mano una red ya tendida es
                                     tedioso y se hace mal: las ONTs ya saben
                                     de qué puerto cuelgan. --}}
                                <form method="POST" action="{{ route('pon_ports.detect', $network) }}" class="form-inline d-inline">
                                    @csrf
                                    <select name="olt_id" class="form-control form-control-sm mr-1" required>
                                        <option value="">Detectar en…</option>
                                        @foreach($network->olts as $olt)
                                            <option value="{{ $olt->id }}">{{ $olt->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-outline-info" title="Registra los puertos que ya tienen ONTs conectadas">
                                        <i class="fas fa-magic"></i> Detectar
                                    </button>
                                </form>
                            @endif
                            <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modalPuertoPon">
                                <i class="fas fa-plus"></i> Agregar
                            </button>
                        </div>
                    @endcan
                </div>

                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>Puerto</th>
                            <th>OLT</th>
                            <th>Zona</th>
                            <th>Splitter</th>
                            <th>Ocupación</th>
                            <th>Cajas</th>
                            @can('networks.edit')<th></th>@endcan
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($network->ponPorts as $puerto)
                            @php
                                $oc = $puerto->ocupacion();
                            @endphp
                            <tr>
                                <td><code>{{ $puerto->etiqueta }}</code></td>
                                <td>{{ $puerto->olt?->name ?? '—' }}</td>
                                <td>
                                    @if($puerto->zone)
                                        <span class="badge" style="background: {{ $puerto->zone->color }}; color:#fff;">
                                            {{ $puerto->zone->name }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $puerto->splitter_ratio ?? '—' }}</td>
                                <td style="min-width: 150px;">
                                    <div class="progress" style="height: 16px;">
                                        <div class="progress-bar bg-{{ \App\Models\PonPort::colorDeOcupacion($oc['porcentaje']) }}"
                                             style="width: {{ min($oc['porcentaje'], 100) }}%">
                                            {{ $oc['conectadas'] }}/{{ $oc['maximo'] }}
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $puerto->napBoxes->count() }}</td>
                                @can('networks.edit')
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('pon_ports.destroy', $puerto) }}" class="d-inline"
                                              onsubmit="return confirm('¿Eliminar el puerto {{ $puerto->etiqueta }}?');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-link text-danger p-0"><i class="fas fa-times"></i></button>
                                        </form>
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    Sin puertos PON registrados.
                                    @if($network->olts->isNotEmpty())
                                        Use <strong>Detectar</strong> para sembrar los que ya están en uso.
                                    @else
                                        Agregue primero una OLT a la red.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ---------- Modal: nuevo puerto PON ---------- --}}
    @can('networks.edit')
        <div class="modal fade" id="modalPuertoPon" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <form method="POST" action="{{ route('pon_ports.store', $network) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="fas fa-plug mr-1"></i> Nuevo puerto PON</h5>
                            <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label>OLT <span class="text-danger">*</span></label>
                                <select name="olt_id" class="form-control" required>
                                    <option value="">Seleccione…</option>
                                    @foreach($network->olts as $olt)
                                        <option value="{{ $olt->id }}">{{ $olt->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="form-row">
                                <div class="col-4 form-group">
                                    <label>Frame</label>
                                    <input type="number" name="frame" class="form-control" value="0" min="0" required>
                                </div>
                                <div class="col-4 form-group">
                                    <label>Tarjeta (slot) <span class="text-danger">*</span></label>
                                    <input type="number" name="slot" class="form-control" min="0" required>
                                </div>
                                <div class="col-4 form-group">
                                    <label>Puerto <span class="text-danger">*</span></label>
                                    <input type="number" name="port" class="form-control" min="0" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-6 form-group">
                                    <label>Zona</label>
                                    <select name="network_zone_id" class="form-control">
                                        <option value="">Sin zona</option>
                                        @foreach($network->zones as $zona)
                                            <option value="{{ $zona->id }}">{{ $zona->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-3 form-group">
                                    <label>Splitter</label>
                                    <input type="text" name="splitter_ratio" class="form-control" placeholder="1:8">
                                </div>
                                <div class="col-3 form-group">
                                    <label>Máx. ONTs</label>
                                    <input type="number" name="max_onts" class="form-control" value="64" min="1" required>
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <label>Descripción</label>
                                <input type="text" name="description" class="form-control"
                                       placeholder="Hacia dónde va este troncal">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar puerto</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endcan
@endsection
