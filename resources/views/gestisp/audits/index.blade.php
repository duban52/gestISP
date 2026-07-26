@extends('adminlte::page')

@section('title', 'Trazabilidad del sistema')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0"><i class="fas fa-user-secret mr-2"></i>Trazabilidad del sistema</h1>
        <span class="badge badge-danger">Solo superadministrador</span>
    </div>
@stop

@section('content')
    {{-- ============================================================
         Filtros

         Sin rango de fechas se muestran los últimos días: la bitácora
         crece sin límite y volcarla entera no sería útil ni rápido.
         ============================================================ --}}
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
            <form method="GET" action="{{ route('audits.index') }}">
                <div class="row">
                    <div class="form-group col-md-3">
                        <label for="buscar">Buscar</label>
                        <input type="text" name="buscar" id="buscar" class="form-control"
                               value="{{ request('buscar') }}"
                               placeholder="Descripción, usuario, IP...">
                    </div>
                    <div class="form-group col-md-2">
                        <label for="usuario">Usuario</label>
                        <select name="usuario" id="usuario" class="form-control">
                            <option value="">Todos</option>
                            @foreach($usuarios as $u)
                                <option value="{{ $u->id }}" @selected(request('usuario') == $u->id)>
                                    {{ $u->name }} {{ $u->last_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label for="categoria">Módulo</label>
                        <select name="categoria" id="categoria" class="form-control">
                            <option value="">Todos</option>
                            @foreach($categorias as $clave => $nombre)
                                <option value="{{ $clave }}" @selected(request('categoria') === $clave)>
                                    {{ $nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label for="accion">Acción</label>
                        <select name="accion" id="accion" class="form-control">
                            <option value="">Todas</option>
                            <option value="created" @selected(request('accion') === 'created')>Creación</option>
                            <option value="updated" @selected(request('accion') === 'updated')>Modificación</option>
                            <option value="deleted" @selected(request('accion') === 'deleted')>Eliminación</option>
                            <option value="auth." @selected(request('accion') === 'auth.')>Accesos</option>
                            <option value="export" @selected(request('accion') === 'export')>Exportaciones</option>
                            <option value="pdf" @selected(request('accion') === 'pdf')>PDF</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="sucursal">Sucursal</label>
                        <select name="sucursal" id="sucursal" class="form-control">
                            <option value="">Todas</option>
                            @foreach($sucursales as $s)
                                <option value="{{ $s->id }}" @selected(request('sucursal') == $s->id)>
                                    {{ $s->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="form-group col-md-3">
                        <label for="desde">Desde</label>
                        <input type="date" name="desde" id="desde" class="form-control"
                               value="{{ request('desde', now()->subDays($diasPorDefecto)->toDateString()) }}">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="hasta">Hasta</label>
                        <input type="date" name="hasta" id="hasta" class="form-control"
                               value="{{ request('hasta') }}">
                    </div>
                    <div class="form-group col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary mr-2">
                            <i class="fas fa-search mr-1"></i> Filtrar
                        </button>
                        <a href="{{ route('audits.index') }}" class="btn btn-outline-secondary">
                            <i class="fas fa-eraser mr-1"></i> Limpiar
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if($usandoRangoPorDefecto)
        <div class="alert alert-info">
            <i class="fas fa-info-circle mr-1"></i>
            Mostrando la actividad de los últimos <strong>{{ $diasPorDefecto }} días</strong>.
            Use el filtro de fechas para consultar periodos anteriores.
        </div>
    @endif

    {{-- ============================================================
         Bitácora
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">
                <i class="fas fa-list mr-1"></i>
                {{ number_format($registros->total()) }} registro(s)
            </h3>
            <span class="text-muted small">Del más reciente al más antiguo</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th style="width: 145px;">Fecha y hora</th>
                        <th style="width: 160px;">Usuario</th>
                        <th style="width: 110px;">Módulo</th>
                        <th style="width: 120px;">Acción</th>
                        <th>Detalle</th>
                        <th style="width: 115px;">Origen</th>
                        <th style="width: 60px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($registros as $registro)
                        <tr @class(['table-warning' => $registro->fallo])>
                            <td class="text-nowrap">
                                {{ $registro->created_at->format('d/m/Y') }}
                                <span class="text-muted">{{ $registro->created_at->format('H:i:s') }}</span>
                            </td>
                            <td>
                                {{ $registro->user_name ?? ($registro->user?->name ?? 'Sistema') }}
                                @if($registro->role_name)
                                    <small class="d-block text-muted">{{ ucfirst($registro->role_name) }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-light border">{{ $registro->categoria_legible }}</span>
                            </td>
                            <td>
                                @php
                                    $color = match($registro->action) {
                                        'created' => 'success',
                                        'updated' => 'info',
                                        'deleted' => 'danger',
                                        'auth.login' => 'primary',
                                        'auth.logout' => 'secondary',
                                        'auth.failed' => 'warning',
                                        default => 'light',
                                    };
                                @endphp
                                <span class="badge badge-{{ $color }} {{ $color === 'light' ? 'border' : '' }}">
                                    {{ $registro->accion_legible }}
                                </span>
                            </td>
                            <td>
                                {{ $registro->description ?? '—' }}
                                @if($registro->fallo)
                                    <span class="badge badge-warning ml-1">falló</span>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                <small>
                                    {{ $registro->ip ?? '—' }}
                                    @if($registro->branch)
                                        <span class="d-block text-muted">{{ $registro->branch->name }}</span>
                                    @endif
                                </small>
                            </td>
                            <td class="text-center">
                                <a href="{{ route('audits.show', $registro) }}"
                                   class="btn btn-outline-primary btn-sm" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-inbox mr-1"></i>
                                No hay actividad registrada con los filtros aplicados.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($registros->hasPages())
            <div class="card-footer">
                {{ $registros->links() }}
            </div>
        @endif
    </div>
@stop
