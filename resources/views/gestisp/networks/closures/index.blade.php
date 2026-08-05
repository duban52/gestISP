{{-- ============================================================
     Listado de muflas

     La mufla es el punto más delicado de la red: abrirla deja sin
     servicio a todo lo que cuelga de ella mientras dure el trabajo.
     Por eso el listado da la ocupación de fusiones de un vistazo: una
     mufla llena es una que no admite el siguiente empalme, y eso hay
     que saberlo ANTES de subir al poste.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Muflas')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-box-open mr-2"></i>Muflas y cajas de empalme</h1>
        <div>
            <a href="{{ route('naps.map') }}" class="btn btn-outline-primary">
                <i class="fas fa-map-marked-alt"></i> Verlas en el mapa
            </a>
            <a href="{{ route('cables.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-grip-lines"></i> Cables
            </a>
            <a href="{{ route('closures.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nueva mufla
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
                    <h3>{{ $resumen['total'] }}</h3>
                    <p class="mb-0">Muflas</p>
                </div>
                <div class="icon"><i class="fas fa-box-open"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $resumen['fusiones'] }}<sup style="font-size:1.2rem">/{{ $resumen['capacidad'] }}</sup></h3>
                    <p class="mb-0">Fusiones</p>
                </div>
                <div class="icon"><i class="fas fa-link"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ max($resumen['capacidad'] - $resumen['fusiones'], 0) }}</h3>
                    <p class="mb-0">Espacio libre</p>
                </div>
                <div class="icon"><i class="fas fa-plus-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-secondary">
                <div class="inner">
                    <h3>{{ $resumen['splitters'] }}</h3>
                    <p class="mb-0">Splitters</p>
                </div>
                <div class="icon"><i class="fas fa-code-branch"></i></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header py-2">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4 form-group mb-0">
                    <label class="mb-1 small">Buscar</label>
                    <input type="text" name="q" value="{{ $filtros['q'] ?? '' }}"
                           class="form-control form-control-sm"
                           placeholder="Código, nombre o dirección…">
                </div>
                <div class="col-md-3 form-group mb-0">
                    <label class="mb-1 small">Red</label>
                    <select name="network_id" class="form-control form-control-sm">
                        <option value="">Todas</option>
                        @foreach($redes as $red)
                            <option value="{{ $red->id }}" @selected((string) ($filtros['network_id'] ?? '') === (string) $red->id)>
                                {{ $red->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 form-group mb-0">
                    <label class="mb-1 small">Tipo</label>
                    <select name="type" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach(\App\Models\SpliceClosure::TIPOS as $clave => $texto)
                            <option value="{{ $clave }}" @selected(($filtros['type'] ?? '') === $clave)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 form-group mb-0">
                    <button class="btn btn-sm btn-primary btn-block"><i class="fas fa-search"></i> Filtrar</button>
                </div>
            </form>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>Mufla</th>
                        <th>Dónde está</th>
                        <th>Tipo</th>
                        <th class="text-center">Splitters</th>
                        <th style="width: 200px;">Fusiones</th>
                        <th class="text-center">Estado</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($muflas as $mufla)
                        @php
                            $oc = $mufla->ocupacion();
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('closures.show', $mufla) }}" class="font-weight-bold">
                                    {{ $mufla->code }}
                                </a>
                                @if($mufla->name)
                                    <small class="d-block text-muted">{{ $mufla->name }}</small>
                                @endif
                                @if($mufla->zone)
                                    <span class="badge" style="background: {{ $mufla->zone->color }}; color:#fff;">
                                        {{ $mufla->zone->name }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <small>{{ $mufla->address }}</small>
                                @if($mufla->reference)
                                    <small class="d-block text-muted">{{ $mufla->reference }}</small>
                                @endif
                            </td>
                            <td><small>{{ $mufla->tipo_legible }}</small></td>
                            <td class="text-center">
                                @if($mufla->splitters->count() > 0)
                                    <span class="badge badge-info">{{ $mufla->splitters->count() }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="progress" style="height: 18px;">
                                    <div class="progress-bar bg-{{ $mufla->color_ocupacion }}"
                                         style="width: {{ min($oc['porcentaje'], 100) }}%;">
                                        {{ $oc['usadas'] }}/{{ $oc['capacidad'] }}
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-{{ $mufla->status === 'operativa' ? 'success' : 'warning' }}">
                                    {{ $mufla->estado_legible }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Todavía no hay muflas registradas.
                                <a href="{{ route('closures.create') }}">Registre la primera</a>.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
