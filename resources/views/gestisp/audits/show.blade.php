@extends('adminlte::page')

@section('title', 'Detalle de trazabilidad')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Registro N.º {{ $audit->id }}</h1>
        <a href="{{ url()->previous() }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>
@stop

@section('content')
    <div class="row">
        {{-- ---------- Qué ocurrió ---------- --}}
        <div class="col-lg-7">
            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-info-circle mr-1"></i> Qué ocurrió</h3>
                </div>
                <div class="card-body">
                    <p class="lead mb-3">{{ $audit->description ?? $audit->action }}</p>

                    <table class="table table-sm">
                        <tr>
                            <th style="width: 190px;">Acción</th>
                            <td>{{ $audit->accion_legible }} <code class="ml-1">{{ $audit->action }}</code></td>
                        </tr>
                        <tr>
                            <th>Módulo</th>
                            <td>{{ $audit->categoria_legible }}</td>
                        </tr>
                        <tr>
                            <th>Fecha y hora</th>
                            <td>{{ $audit->created_at->format('d/m/Y H:i:s') }}</td>
                        </tr>
                        @if($audit->auditable_type)
                            <tr>
                                <th>Registro afectado</th>
                                <td>
                                    {{ class_basename($audit->auditable_type) }}
                                    @if($audit->auditable_id)
                                        N.º {{ $audit->auditable_id }}
                                    @endif
                                </td>
                            </tr>
                        @endif
                        @if($audit->fallo)
                            <tr>
                                <th>Resultado</th>
                                <td><span class="badge badge-warning">La acción falló</span></td>
                            </tr>
                        @endif
                    </table>
                </div>
            </div>

            {{-- ---------- Cambios ---------- --}}
            @if(!empty($audit->cambios))
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exchange-alt mr-1"></i> Datos modificados</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>Campo</th>
                                    <th>Antes</th>
                                    <th>Después</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($audit->cambios as $cambio)
                                    <tr>
                                        <td><strong>{{ $cambio['campo'] }}</strong></td>
                                        <td class="text-muted">
                                            {{ is_scalar($cambio['antes']) || is_null($cambio['antes'])
                                                ? ($cambio['antes'] ?? '—')
                                                : json_encode($cambio['antes'], JSON_UNESCAPED_UNICODE) }}
                                        </td>
                                        <td>
                                            {{ is_scalar($cambio['despues']) || is_null($cambio['despues'])
                                                ? ($cambio['despues'] ?? '—')
                                                : json_encode($cambio['despues'], JSON_UNESCAPED_UNICODE) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ---------- Datos de la acción ---------- --}}
            @if(!empty($audit->context))
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-code mr-1"></i> Datos enviados</h3>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="max-height: 320px; overflow:auto;">{{ json_encode($audit->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            @endif
        </div>

        {{-- ---------- Quién y desde dónde ---------- --}}
        <div class="col-lg-5">
            <div class="card card-outline card-secondary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user mr-1"></i> Quién lo hizo</h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 130px;">Usuario</th>
                            <td>{{ $audit->user_name ?? ($audit->user?->name ?? 'Sistema') }}</td>
                        </tr>
                        <tr>
                            <th>Rol usado</th>
                            <td>{{ $audit->role_name ? ucfirst($audit->role_name) : '—' }}</td>
                        </tr>
                        <tr>
                            <th>Sucursal</th>
                            <td>{{ $audit->branch?->name ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Dirección IP</th>
                            <td>{{ $audit->ip ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Navegador</th>
                            <td><small>{{ $audit->user_agent ?? '—' }}</small></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-link mr-1"></i> Desde dónde</h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 130px;">Pantalla</th>
                            <td>{{ $audit->route_name ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Método</th>
                            <td>{{ $audit->method ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Dirección</th>
                            <td><small class="text-break">{{ $audit->url ?? '—' }}</small></td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Todo lo que pasó en la misma operación --}}
            @if($relacionados->isNotEmpty())
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-project-diagram mr-1"></i> En la misma operación</h3>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            @foreach($relacionados as $rel)
                                <li class="list-group-item">
                                    <a href="{{ route('audits.show', $rel) }}">
                                        {{ $rel->description ?? $rel->action }}
                                    </a>
                                    <small class="d-block text-muted">{{ $rel->accion_legible }}</small>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>
@stop
