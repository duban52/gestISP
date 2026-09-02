@extends('adminlte::page')
@section('title', 'Grupos de afinidad')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-layer-group mr-2"></i>Grupos de afinidad</h1>
        <div class="acciones-movil">
            @can('affinity_groups.create')
                <a href="{{ route('affinity_groups.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nuevo grupo
                </a>
            @endcan
        </div>
    </div>
@endsection

@section('content')
    @foreach(['success-create', 'success-update', 'success-delete'] as $clave)
        @if(session($clave))
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session($clave) }}
            </div>
        @endif
    @endforeach

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('error') }}
        </div>
    @endif

    <div class="callout callout-info">
        El grupo clasifica los contratos de la empresa y decide <strong>cómo se
        factura</strong> cada uno: con <strong>factura electrónica</strong> o con
        <strong>documento interno</strong>. Los grupos son de la <strong>empresa</strong>,
        así que se usan en todas sus sucursales.
    </div>

    {{-- Sin grupo predeterminado, los contratos nuevos nacen sin
         clasificar y eso solo se descubriría al facturar. Se avisa
         arriba y no en una columna, porque es un problema de la
         empresa entera y no de un grupo concreto. --}}
    @if($grupos->isNotEmpty() && !$grupos->contains('is_default', true))
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <strong>No hay grupo predeterminado.</strong>
            Los contratos que se den de alta sin elegir grupo quedarán sin clasificar.
            Marque uno con el botón <i class="fas fa-thumbtack"></i>.
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>Grupo</th>
                        <th>Facturación</th>
                        <th class="text-center">Contratos</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($grupos as $grupo)
                        <tr>
                            <td class="celda-principal" data-label="">
                                <span class="font-weight-bold">
                                    <code>{{ $grupo->code }}</code> {{ $grupo->name }}
                                </span>
                                @if($grupo->is_default)
                                    <span class="badge badge-primary ml-1" title="Los contratos sin grupo reciben este">
                                        Predeterminado
                                    </span>
                                @endif
                                @if($grupo->description)
                                    <small class="d-block text-muted">{{ $grupo->description }}</small>
                                @endif
                            </td>

                            <td data-label="Facturación">
                                @if($grupo->requires_electronic_invoicing)
                                    <span class="badge badge-success">
                                        <i class="fas fa-file-invoice mr-1"></i>Electrónica
                                    </span>
                                @else
                                    <span class="badge badge-secondary">
                                        <i class="fas fa-file-alt mr-1"></i>Interna
                                    </span>
                                @endif
                                @if($grupo->requires_client_tax_data)
                                    <small class="d-block text-muted">Exige datos fiscales del cliente</small>
                                @endif
                            </td>

                            <td class="text-center" data-label="Contratos">{{ $grupo->contracts_count }}</td>

                            <td class="text-center" data-label="Estado">
                                <span class="badge badge-{{ $grupo->active ? 'success' : 'secondary' }}">
                                    {{ $grupo->active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>

                            <td class="text-center celda-acciones" data-label="">
                                @can('affinity_groups.edit')
                                    @unless($grupo->is_default)
                                        {{-- Marcar el predeterminado es la operación más
                                             frecuente: se hace desde aquí sin entrar a
                                             editar. --}}
                                        <form action="{{ route('affinity_groups.default', $grupo) }}"
                                              method="POST" class="d-inline"
                                              onsubmit="return confirm('¿Marcar «{{ $grupo->name }}» como grupo predeterminado? Los contratos que se den de alta sin elegir grupo lo recibirán.');">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                                    title="Marcar como predeterminado"
                                                    @disabled(!$grupo->active)>
                                                <i class="fas fa-thumbtack"></i>
                                                <span class="d-md-none ml-1">Predeterminado</span>
                                            </button>
                                        </form>
                                    @endunless

                                    <a href="{{ route('affinity_groups.edit', $grupo) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-edit"></i><span class="d-md-none ml-1">Editar</span>
                                    </a>
                                @endcan

                                @can('affinity_groups.destroy')
                                    @if($grupo->sePuedeEliminar())
                                        <form action="{{ route('affinity_groups.destroy', $grupo) }}"
                                              method="POST" class="d-inline"
                                              onsubmit="return confirm('¿Eliminar el grupo «{{ $grupo->name }}»?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i><span class="d-md-none ml-1">Eliminar</span>
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">
                            Esta empresa todavía no tiene grupos de afinidad.
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
