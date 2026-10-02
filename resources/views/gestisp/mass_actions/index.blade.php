@extends('adminlte::page')

@section('title', 'Acciones masivas')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-layer-group mr-2"></i>Acciones masivas</h1>
@endsection

@section('content')
    {{--
        Historial de todo lo que se ejecutó sobre muchos registros a la
        vez: importaciones, cortes y corridas de facturación.

        Pantalla del superadministrador. Lo que se ve aquí no se filtra
        por la empresa del contexto activo, a propósito: quien responde
        por el sistema entero tiene que verlo entero.
    --}}
    <div class="callout callout-info py-2">
        Cada fila es una operación que tocó varios registros. Desde el detalle se puede
        <strong>revertir</strong> si la operación lo admite y nadie ha cambiado los
        registros después.
    </div>

    <div class="card">
        <div class="card-body py-2">
            <form method="GET" class="form-row align-items-end">
                <div class="form-group col-md-3 mb-2">
                    <label class="mb-1">Buscar</label>
                    <input type="text" name="buscar" class="form-control form-control-sm"
                           value="{{ request('buscar') }}" placeholder="Descripción…">
                </div>
                <div class="form-group col-md-2 mb-2">
                    <label class="mb-1">Tipo</label>
                    <select name="tipo" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($tipos as $tipo)
                            <option value="{{ $tipo->value }}" @selected(request('tipo') === $tipo->value)>
                                {{ $tipo->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-2 mb-2">
                    <label class="mb-1">Estado</label>
                    <select name="estado" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($estados as $estado)
                            <option value="{{ $estado->value }}" @selected(request('estado') === $estado->value)>
                                {{ $estado->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-2 mb-2">
                    <label class="mb-1">Usuario</label>
                    <select name="usuario" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($usuarios as $usuario)
                            <option value="{{ $usuario->id }}" @selected(request('usuario') == $usuario->id)>
                                {{ trim($usuario->name . ' ' . $usuario->last_name) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-1 mb-2">
                    <label class="mb-1">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm" value="{{ request('desde') }}">
                </div>
                <div class="form-group col-md-1 mb-2">
                    <label class="mb-1">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm" value="{{ request('hasta') }}">
                </div>
                <div class="form-group col-md-1 mb-2">
                    <button class="btn btn-primary btn-sm btn-block"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Tipo</th>
                        <th>Descripción</th>
                        <th class="text-center">Registros</th>
                        <th class="text-center">Correctos</th>
                        <th class="text-center">Errores</th>
                        <th>Estado</th>
                        <th class="text-center">Acción</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($acciones as $accion)
                        <tr>
                            <td><strong>{{ $accion->id }}</strong></td>
                            <td>
                                {{ $accion->created_at->format('d/m/Y H:i') }}
                                @if($accion->duracion())
                                    <small class="d-block text-muted">{{ $accion->duracion() }}</small>
                                @endif
                            </td>
                            <td>{{ $accion->user ? trim($accion->user->name . ' ' . $accion->user->last_name) : 'Sistema' }}</td>
                            <td>
                                <i class="fas {{ $accion->type->icono() }} mr-1 text-muted"></i>
                                {{ $accion->type->label() }}
                                @if($accion->esUnaReversion())
                                    <small class="d-block text-muted">
                                        de la #{{ $accion->reverses_mass_action_id }}
                                    </small>
                                @endif
                            </td>
                            <td>{{ $accion->description }}</td>
                            <td class="text-center">{{ number_format($accion->total_items) }}</td>
                            <td class="text-center text-success">{{ number_format($accion->ok_items) }}</td>
                            <td class="text-center {{ $accion->failed_items > 0 ? 'text-danger font-weight-bold' : 'text-muted' }}">
                                {{ number_format($accion->failed_items) }}
                            </td>
                            <td>
                                <span class="badge badge-{{ $accion->status->color() }}">
                                    {{ $accion->status->label() }}
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="{{ route('mass_actions.show', $accion) }}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No hay acciones masivas registradas con esos criterios.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($acciones->hasPages())
            <div class="card-footer py-2">{{ $acciones->links() }}</div>
        @endif
    </div>
@endsection
