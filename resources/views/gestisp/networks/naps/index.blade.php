{{-- ============================================================
     Listado de cajas NAP / CTO

     El filtro "con cupo" es el que más se usa: es la pregunta del de
     ventas y del instalador, "¿dónde puedo conectar?".
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Cajas NAP')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-box mr-2"></i>Cajas NAP / CTO</h1>
        <div>
            <a href="{{ route('naps.map') }}" class="btn btn-outline-info">
                <i class="fas fa-map-marked-alt"></i> Ver en el mapa
            </a>
            @can('naps.create')
                <a href="{{ route('naps.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nueva caja
                </a>
            @endcan
        </div>
    </div>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    {{-- ---------- Cifras ---------- --}}
    <div class="row">
        <div class="col-6 col-md-3">
            <div class="info-box shadow-sm">
                <span class="info-box-icon bg-primary"><i class="fas fa-box"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Cajas</span>
                    <span class="info-box-number">{{ $resumen['total'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="info-box shadow-sm">
                <span class="info-box-icon bg-success"><i class="fas fa-plug"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Puertos libres</span>
                    <span class="info-box-number">{{ $resumen['disponibles'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="info-box shadow-sm">
                <span class="info-box-icon bg-info"><i class="fas fa-users"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Ocupados</span>
                    <span class="info-box-number">{{ $resumen['ocupados'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="info-box shadow-sm">
                <span class="info-box-icon bg-secondary"><i class="fas fa-layer-group"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Capacidad total</span>
                    <span class="info-box-number">{{ $resumen['capacidad'] }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ---------- Filtros ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header">
            <form method="GET" action="{{ route('naps.index') }}">
                <div class="row align-items-end">
                    <div class="col-md-4 form-group mb-2">
                        <label class="mb-1">Buscar</label>
                        <input type="text" name="q" class="form-control"
                               value="{{ $filtros['q'] ?? '' }}"
                               placeholder="Código, nombre o dirección…">
                    </div>
                    <div class="col-md-3 form-group mb-2">
                        <label class="mb-1">Red</label>
                        <select name="network_id" class="form-control">
                            <option value="">Todas</option>
                            @foreach($redes as $red)
                                <option value="{{ $red->id }}"
                                    @selected((string) ($filtros['network_id'] ?? '') === (string) $red->id)>
                                    {{ $red->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 form-group mb-2">
                        <label class="mb-1">Disponibilidad</label>
                        <select name="cupo" class="form-control">
                            <option value="">Todas</option>
                            <option value="si" @selected(($filtros['cupo'] ?? '') === 'si')>Solo con cupo</option>
                            <option value="no" @selected(($filtros['cupo'] ?? '') === 'no')>Solo llenas</option>
                        </select>
                    </div>
                    <div class="col-md-2 form-group mb-2">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table id="napsTable" class="table table-hover" style="width:100%">
                    <thead class="thead-light">
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Dirección</th>
                        <th>Zona</th>
                        <th>Puerto PON</th>
                        <th>Ocupación</th>
                        <th class="text-center">Libres</th>
                        <th class="text-center">Mapa</th>
                        <th class="text-center">Ver</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($cajas as $caja)
                        @php
                            $oc = $caja->ocupacion();
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('naps.show', $caja) }}"><strong>{{ $caja->code }}</strong></a>
                                @if($caja->status !== 'operativa')
                                    <span class="badge badge-warning">{{ $caja->estado_legible }}</span>
                                @endif
                            </td>
                            <td>{{ $caja->name ?? '—' }}</td>
                            <td><small>{{ $caja->address ?? '—' }}</small></td>
                            <td>
                                @if($caja->zone)
                                    <span class="badge" style="background: {{ $caja->zone->color }}; color:#fff;">
                                        {{ $caja->zone->name }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <code>{{ $caja->ponPort?->etiqueta ?? '—' }}</code>
                                <small class="d-block text-muted">{{ $caja->ponPort?->olt?->name }}</small>
                            </td>
                            <td style="min-width: 140px;" data-order="{{ $oc['porcentaje'] }}">
                                <div class="progress" style="height: 18px;">
                                    <div class="progress-bar bg-{{ $caja->color_ocupacion }}"
                                         style="width: {{ min($oc['porcentaje'], 100) }}%">
                                        {{ $oc['ocupados'] }}/{{ $oc['capacidad'] }}
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-{{ $oc['disponibles'] > 0 ? 'success' : 'secondary' }}"
                                      style="font-size: .9rem;">
                                    {{ $oc['disponibles'] }}
                                </span>
                            </td>
                            <td class="text-center">
                                @if($caja->estaGeorreferenciada())
                                    <i class="fas fa-map-marker-alt text-success" title="Ubicada"></i>
                                @else
                                    <i class="fas fa-map-marker-alt text-muted" title="Sin coordenadas"></i>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('naps.show', $caja) }}" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(function () {
            $('#napsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay cajas que coincidan con el filtro.'
                },
                pageLength: 50,
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [7, 8] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });
    </script>
@endsection
