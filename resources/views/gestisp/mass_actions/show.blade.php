@extends('adminlte::page')

@section('title', 'Acción masiva #' . $accion->id)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas {{ $accion->type->icono() }} mr-2"></i>
            Acción masiva #{{ $accion->id }}
        </h1>
        <div>
            @if($accion->sePuedeRevertir() && auth()->user()->can('revert', $accion))
                <a href="{{ route('mass_actions.confirm', $accion) }}" class="btn btn-warning">
                    <i class="fas fa-undo"></i> Revertir acción
                </a>
            @endif
            <a href="{{ route('mass_actions.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')
    @include('gestisp.partials.resultado-accion')

    {{-- La cadena de la reversión: desde cualquiera de las dos acciones
         se llega a la otra. Es la pregunta de quien mira esto tres
         meses después: «¿esto se deshizo? ¿quién?». --}}
    @if($accion->reverted_by_mass_action_id)
        <div class="callout callout-success py-2">
            Esta acción fue revertida por la
            <a href="{{ route('mass_actions.show', $accion->reverted_by_mass_action_id) }}">
                acción #{{ $accion->reverted_by_mass_action_id }}</a>.
        </div>
    @endif

    @if($accion->esUnaReversion())
        <div class="callout callout-info py-2">
            Esta acción deshace la
            <a href="{{ route('mass_actions.show', $accion->reverses_mass_action_id) }}">
                acción #{{ $accion->reverses_mass_action_id }}</a>.
        </div>
    @endif

    <div class="row">
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-info-circle mr-1"></i> Información general</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <tr><th style="width:45%">Tipo</th><td>{{ $accion->type->label() }}</td></tr>
                        <tr><th>Descripción</th><td>{{ $accion->description }}</td></tr>
                        <tr>
                            <th>Usuario</th>
                            <td>{{ $accion->user ? trim($accion->user->name . ' ' . $accion->user->last_name) : 'Sistema' }}</td>
                        </tr>
                        <tr><th>Sucursal</th><td>{{ $accion->branch?->name ?? '—' }}</td></tr>
                        <tr><th>Inicio</th><td>{{ $accion->started_at?->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
                        <tr><th>Fin</th><td>{{ $accion->finished_at?->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
                        <tr><th>Duración</th><td>{{ $accion->duracion() ?? '—' }}</td></tr>
                        <tr>
                            <th>Estado</th>
                            <td>
                                <span class="badge badge-{{ $accion->status->color() }}">
                                    {{ $accion->status->label() }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Reversible</th>
                            <td>
                                @if($accion->sePuedeRevertir())
                                    <span class="badge badge-success">Sí</span>
                                @else
                                    <span class="badge badge-secondary">No</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            @if($accion->summary)
                <div class="card">
                    <div class="card-header py-2">
                        <h3 class="card-title mb-0"><i class="fas fa-clipboard-list mr-1"></i> Datos de la operación</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped mb-0">
                            @foreach($accion->summary as $clave => $valor)
                                <tr>
                                    <th style="width:45%">{{ ucfirst(str_replace('_', ' ', $clave)) }}</th>
                                    <td>{{ is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : $valor }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-chart-bar mr-1"></i> Resumen</h3>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col"><h4 class="mb-0">{{ number_format($accion->total_items) }}</h4><small class="text-muted">Procesados</small></div>
                        <div class="col"><h4 class="mb-0 text-success">{{ number_format($accion->ok_items) }}</h4><small class="text-muted">Correctos</small></div>
                        <div class="col"><h4 class="mb-0 text-secondary">{{ number_format($accion->skipped_items) }}</h4><small class="text-muted">Omitidos</small></div>
                        <div class="col"><h4 class="mb-0 text-danger">{{ number_format($accion->failed_items) }}</h4><small class="text-muted">Errores</small></div>
                        <div class="col"><h4 class="mb-0 text-info">{{ number_format($accion->reverted_items) }}</h4><small class="text-muted">Revertidos</small></div>
                        <div class="col"><h4 class="mb-0 text-warning">{{ number_format($accion->conflict_items) }}</h4><small class="text-muted">Conflictos</small></div>
                    </div>
                </div>
            </div>

            @if($estrategia)
                <div class="callout callout-warning py-2">
                    <strong>Si se revierte:</strong> {{ $estrategia->advertencia() }}
                </div>
            @else
                <div class="callout callout-secondary py-2">
                    Esta operación <strong>no se revierte automáticamente</strong>. Queda registrada
                    para saber qué se hizo y cuándo.
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0"><i class="fas fa-list mr-1"></i> Registros afectados</h3>
            <form method="GET" class="form-inline">
                <select name="estado_item" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    @foreach($estadosItem as $estado)
                        <option value="{{ $estado->value }}" @selected(request('estado_item') === $estado->value)>
                            {{ $estado->label() }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>Registro</th>
                        <th>Antes</th>
                        <th>Después</th>
                        <th>Estado</th>
                        <th>Detalle</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <strong>{{ $item->label ?? '—' }}</strong>
                                @if($item->subject_type)
                                    <small class="d-block text-muted">
                                        {{ class_basename($item->subject_type) }} #{{ $item->subject_id }}
                                    </small>
                                @endif
                            </td>
                            {{-- Antes y después, campo por campo: es lo que
                                 permite ver de un vistazo qué cambió, sin
                                 abrir el registro. --}}
                            <td>
                                @foreach(($item->before ?? []) as $campo => $valor)
                                    <small class="d-block"><span class="text-muted">{{ $campo }}:</span> {{ is_scalar($valor) ? $valor : json_encode($valor) }}</small>
                                @endforeach
                            </td>
                            <td>
                                @foreach(($item->after ?? []) as $campo => $valor)
                                    <small class="d-block"><span class="text-muted">{{ $campo }}:</span> {{ is_scalar($valor) ? $valor : json_encode($valor) }}</small>
                                @endforeach
                            </td>
                            <td>
                                <span class="badge badge-{{ $item->status->color() }}">{{ $item->status->label() }}</span>
                                @if($item->reverted_at)
                                    <small class="d-block text-muted">{{ $item->reverted_at->format('d/m/Y H:i') }}</small>
                                @endif
                            </td>
                            <td>
                                <small>{{ $item->message }}</small>
                                @if($item->conflict_reason)
                                    <small class="d-block text-warning">
                                        <i class="fas fa-exclamation-triangle"></i> {{ $item->conflict_reason }}
                                    </small>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Sin registros.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($items->hasPages())
            <div class="card-footer py-2">{{ $items->links() }}</div>
        @endif
    </div>
@endsection
