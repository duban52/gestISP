{{-- ============================================================
     Listado de cables

     La cifra que importa es la de hilos LIBRES: es la que responde si
     una derivación nueva se puede hacer con lo que hay o toca tirar
     cable, que no se hace en una tarde.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Cables de fibra')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-grip-lines mr-2"></i>Cables de fibra</h1>
        <div>
            <a href="{{ route('closures.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-box-open"></i> Muflas
            </a>
            <a href="{{ route('cables.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nuevo cable
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
                    <p class="mb-0">Tramos</p>
                </div>
                <div class="icon"><i class="fas fa-grip-lines"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $resumen['en_uso'] }}<sup style="font-size:1.2rem">/{{ $resumen['hilos'] }}</sup></h3>
                    <p class="mb-0">Hilos conectados</p>
                </div>
                <div class="icon"><i class="fas fa-link"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ $resumen['libres'] }}</h3>
                    <p class="mb-0">Hilos vírgenes</p>
                </div>
                <div class="icon"><i class="fas fa-plus-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-secondary">
                <div class="inner">
                    <h3>{{ number_format($resumen['metros'] / 1000, 1) }}<sup style="font-size:1.2rem">km</sup></h3>
                    <p class="mb-0">Tendido</p>
                </div>
                <div class="icon"><i class="fas fa-road"></i></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header py-2">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4 form-group mb-0">
                    <label class="mb-1 small">Buscar</label>
                    <input type="text" name="q" value="{{ $filtros['q'] ?? '' }}"
                           class="form-control form-control-sm" placeholder="Código o nombre…">
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
                        @foreach(\App\Models\FiberCable::TIPOS as $clave => $texto)
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
                        <th>Cable</th>
                        <th>Recorrido</th>
                        <th>Capacidad</th>
                        <th class="text-right">Longitud</th>
                        <th style="width: 200px;">Hilos en uso</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($cables as $cable)
                        @php
                            $oc = $cable->ocupacion();
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('cables.show', $cable) }}" class="font-weight-bold">{{ $cable->code }}</a>
                                <span class="badge badge-light border ml-1">{{ $cable->tipo_legible }}</span>
                                @if($cable->name)
                                    <small class="d-block text-muted">{{ $cable->name }}</small>
                                @endif
                            </td>
                            <td>
                                <small>{{ $cable->desde_legible }} <i class="fas fa-arrow-right text-muted"></i> {{ $cable->hasta_legible }}</small>
                            </td>
                            <td><small>{{ $cable->capacidad_legible }}</small></td>
                            <td class="text-right">
                                {{ $cable->length_m ? number_format($cable->length_m) . ' m' : '—' }}
                            </td>
                            <td>
                                <div class="progress" style="height: 18px;">
                                    <div class="progress-bar bg-{{ $cable->color_ocupacion }}"
                                         style="width: {{ min($oc['porcentaje'], 100) }}%;">
                                        {{ $oc['en_uso'] }}/{{ $oc['capacidad'] }}
                                    </div>
                                </div>
                                <small class="text-muted">{{ $oc['libres'] }} vírgenes</small>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Todavía no hay cables registrados.
                                <a href="{{ route('cables.create') }}">Registre el primero</a>.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
