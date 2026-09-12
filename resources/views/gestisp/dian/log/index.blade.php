@extends('adminlte::page')

@section('title', 'Documentos ante la DIAN')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-file-invoice mr-2"></i>Documentos ante la DIAN</h1>

        {{-- La otra mitad de la pregunta: aqui se ven los documentos que
             existen; alli, los CONSECUTIVOS que la DIAN autorizo y que
             no acabaron en documento — que por definicion no pueden
             aparecer en esta lista. --}}
        <a href="{{ route('dian.log.consecutivos') }}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-list-ol mr-1"></i>Consecutivos autorizados
        </a>
    </div>
@stop

@section('content')

    {{-- ============================================================
         El resumen va ARRIBA y es lo primero que se lee.

         Si hay rechazados, eso es lo urgente: son facturas sin valor
         fiscal. Si hay documentos esperando envío desde hace rato, es
         que algo no está corriendo. Ninguna de las dos cosas avisa
         sola.
         ============================================================ --}}
    <div class="row">
        <div class="col-md-3 col-6">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ $resumen['aceptados'] }}</h3>
                    <p class="mb-0">Aceptados por la DIAN</p>
                </div>
                <div class="icon"><i class="fas fa-check-circle"></i></div>
                <a href="{{ request()->fullUrlWithQuery(['estado' => 'accepted']) }}" class="small-box-footer">
                    Ver <i class="fas fa-arrow-circle-right"></i>
                </a>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="small-box {{ $resumen['rechazados'] > 0 ? 'bg-danger' : 'bg-light' }}">
                <div class="inner">
                    <h3>{{ $resumen['rechazados'] }}</h3>
                    <p class="mb-0">Rechazados</p>
                </div>
                <div class="icon"><i class="fas fa-times-circle"></i></div>
                <a href="{{ request()->fullUrlWithQuery(['estado' => 'rejected']) }}" class="small-box-footer">
                    Ver por qué <i class="fas fa-arrow-circle-right"></i>
                </a>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="small-box {{ $resumen['esperando'] > 0 ? 'bg-warning' : 'bg-light' }}">
                <div class="inner">
                    <h3>{{ $resumen['esperando'] }}</h3>
                    <p class="mb-0">Esperando envío</p>
                </div>
                <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                <a href="{{ request()->fullUrlWithQuery(['estado' => 'signed']) }}" class="small-box-footer">
                    Ver <i class="fas fa-arrow-circle-right"></i>
                </a>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="small-box {{ $resumen['sin_entregar'] > 0 ? 'bg-info' : 'bg-light' }}">
                <div class="inner">
                    <h3>{{ $resumen['sin_entregar'] }}</h3>
                    <p class="mb-0">Validados sin entregar</p>
                </div>
                <div class="icon"><i class="fas fa-envelope"></i></div>
                <a href="{{ request()->fullUrlWithQuery(['sin_entregar' => 1]) }}" class="small-box-footer">
                    Ver <i class="fas fa-arrow-circle-right"></i>
                </a>
            </div>
        </div>
    </div>

    @if($resumen['esperando'] > 0)
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            Hay <strong>{{ $resumen['esperando'] }}</strong> documento(s) firmados que todavía no se han
            enviado a la DIAN. Si llevan ahí más de una hora, revise que el procesador de la cola esté
            corriendo, o fuércelos con <code>php artisan dian:transmitir</code>.
        </div>
    @endif

    @if($resumen['sin_entregar'] > 0)
        <div class="alert alert-info">
            <i class="fas fa-info-circle mr-1"></i>
            Hay <strong>{{ $resumen['sin_entregar'] }}</strong> factura(s) que la DIAN validó y que
            todavía no se le han entregado al cliente. Que la DIAN la valide y que el cliente la tenga
            son dos cosas distintas: <code>php artisan dian:recuperar-acuses --entregar</code>.
        </div>
    @endif

    {{-- ==================== Filtros ==================== --}}
    <div class="card card-outline card-primary shadow-sm">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-filter mr-1"></i> Filtros</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('dian.log.index') }}">
                <div class="row">
                    <div class="form-group col-md-3">
                        <label for="buscar">Buscar</label>
                        <input type="text" name="buscar" id="buscar" class="form-control"
                               value="{{ request('buscar') }}"
                               placeholder="Número, CUFE, motivo del rechazo...">
                    </div>
                    <div class="form-group col-md-2">
                        <label for="estado">Estado</label>
                        <select name="estado" id="estado" class="form-control">
                            <option value="">Todos</option>
                            @foreach($estados as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected(request('estado') === $valor)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label for="ambiente">Ambiente</label>
                        <select name="ambiente" id="ambiente" class="form-control">
                            <option value="">Todos</option>
                            <option value="1" @selected(request('ambiente') === '1')>Producción</option>
                            <option value="2" @selected(request('ambiente') === '2')>Pruebas</option>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label for="desde">Desde</label>
                        <input type="date" name="desde" id="desde" class="form-control" value="{{ request('desde') }}">
                    </div>
                    <div class="form-group col-md-2">
                        <label for="hasta">Hasta</label>
                        <input type="date" name="hasta" id="hasta" class="form-control" value="{{ request('hasta') }}">
                    </div>
                    <div class="form-group col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                @if(request()->hasAny(['buscar', 'estado', 'ambiente', 'desde', 'hasta', 'sin_entregar']))
                    <a href="{{ route('dian.log.index') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-times mr-1"></i>Quitar filtros
                    </a>
                @endif
            </form>
        </div>
    </div>

    {{-- ==================== El listado ==================== --}}
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="card-title">
                {{ $documentos->total() }} documento(s)
                @if($usandoRangoPorDefecto)
                    <small class="text-muted">— últimos {{ $diasPorDefecto }} días</small>
                @endif
            </h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Cliente</th>
                        <th>Estado</th>
                        <th class="text-center">Intentos</th>
                        <th>Emitido</th>
                        <th>Entregado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($documentos as $documento)
                    <tr>
                        <td>
                            <strong>{{ $documento->invoice?->full_number ?? $documento->note?->full_number ?? '—' }}</strong>
                            @if($documento->note)
                                <span class="badge badge-secondary">Nota</span>
                            @endif
                            @if((string) $documento->environment_code === '2')
                                <span class="badge badge-warning">Pruebas</span>
                            @endif
                        </td>
                        <td>{{ $documento->invoice?->contract?->client?->fullName() ?? '—' }}</td>
                        <td>
                            @include('gestisp.dian.log.partials.estado', ['documento' => $documento, 'estados' => $estados])
                        </td>
                        <td class="text-center">{{ $documento->attempts }}</td>
                        <td><small>{{ $documento->created_at?->format('Y-m-d H:i') }}</small></td>
                        <td>
                            @if($documento->delivered_at)
                                <small class="text-success">
                                    <i class="fas fa-check mr-1"></i>{{ $documento->delivered_at->format('Y-m-d H:i') }}
                                </small>
                            @elseif($documento->status === \App\Models\ElectronicDocument::ACEPTADO)
                                <small class="text-muted">Todavía no</small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td class="text-right">
                            <a href="{{ route('dian.log.show', $documento) }}" class="btn btn-xs btn-outline-primary">
                                Detalle
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No hay documentos electrónicos con esos filtros.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($documentos->hasPages())
            <div class="card-footer">
                {{ $documentos->links() }}
            </div>
        @endif
    </div>
@stop
