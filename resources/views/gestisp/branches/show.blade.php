@extends('adminlte::page')

@section('title', 'Detalles de Sucursal')

@section('content')
    {{-- ============================================================
         Alertas de sesión (por si la eliminación falla por
         registros relacionados u otra validación)
         ============================================================ --}}
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Encabezado con título y botón de regreso
         ============================================================ --}}
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="mb-0">Sucursal — {{ $branch->name }}</h2>
            <a href="{{ route('branches.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <div class="row">
        {{-- ============================================================
             Columna izquierda: logo y acciones
             ============================================================ --}}
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    {{-- Logo de la sucursal con imagen por defecto si no tiene --}}
                    @if($branch->image)
                        <img src="{{ asset('/storage/' . $branch->image) }}"
                             alt="Logo de {{ $branch->name }}"
                             class="img-fluid rounded mb-3"
                             style="max-width: 200px;">
                    @else
                        <div class="bg-light rounded d-flex align-items-center justify-content-center mb-3"
                             style="height: 200px;">
                            <i class="fas fa-building fa-4x text-muted"></i>
                        </div>
                    @endif

                    <h4>{{ $branch->name }}</h4>
                    <p class="text-muted mb-0">NIT: {{ $branch->nit }}</p>
                </div>

                {{-- Acciones sobre la sucursal --}}
                <div class="card-footer text-center">
                    <a href="{{ route('branches.edit', $branch) }}" class="btn btn-warning">
                        <i class="fas fa-pencil-alt"></i> Editar
                    </a>
                    <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#modalEliminarBranch">
                        <i class="fas fa-trash"></i> Eliminar
                    </button>
                </div>
            </div>

            {{-- ============================================================
                 Resumen de registros asociados
                 Da contexto de qué depende de esta sucursal antes
                 de intentar eliminarla
                 ============================================================ --}}
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <i class="fas fa-chart-pie"></i> Registros asociados
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th><i class="fas fa-users text-primary"></i> Clientes</th>
                            <td class="text-right">{{ $branch->clients()->count() }}</td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-file-contract text-info"></i> Contratos</th>
                            <td class="text-right">{{ $branch->contracts()->count() }}</td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-server text-success"></i> OLTs</th>
                            <td class="text-right">{{ $branch->olts()->count() }}</td>
                        </tr>
                        <tr>
                            <th><i class="fas fa-broadcast-tower text-warning"></i> ONTs</th>
                            <td class="text-right">{{ $branch->onts()->count() }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- ============================================================
             Columna derecha: información detallada
             ============================================================ --}}
        <div class="col-md-8">
            {{-- Datos de ubicación y contacto --}}
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-map-marker-alt"></i> Ubicación y Contacto
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:35%">País</th>
                            <td>{{ $branch->country }}</td>
                        </tr>
                        <tr>
                            <th>Departamento</th>
                            <td>{{ $branch->department }}</td>
                        </tr>
                        <tr>
                            <th>Municipio</th>
                            <td>{{ $branch->municipality }}</td>
                        </tr>
                        <tr>
                            <th>Dirección</th>
                            <td>{{ $branch->address }}</td>
                        </tr>
                        <tr>
                            <th>Teléfono</th>
                            <td>
                                <i class="fas fa-phone text-success"></i>
                                {{ $branch->number_phone }}
                            </td>
                        </tr>
                        <tr>
                            <th>Teléfono adicional</th>
                            <td>
                                @if($branch->additional_number)
                                    <i class="fas fa-phone text-success"></i>
                                    {{ $branch->additional_number }}
                                @else
                                    <span class="text-muted">No registrado</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Configuración de precios de la sucursal --}}
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-dollar-sign"></i> Tarifas
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:35%">Precio de traslado</th>
                            <td>
                                @if($branch->moving_price)
                                    ${{ number_format($branch->moving_price, 0, ',', '.') }}
                                @else
                                    <span class="text-muted">No configurado</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Precio de reconexión</th>
                            <td>
                                @if($branch->reconnection_price)
                                    ${{ number_format($branch->reconnection_price, 0, ',', '.') }}
                                @else
                                    <span class="text-muted">No configurado</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Textos personalizados de la sucursal --}}
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-comment-alt"></i> Mensajes y Observaciones
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:35%">Mensaje en factura</th>
                            <td>{{ $branch->message_custom_invoice ?: 'Sin mensaje personalizado' }}</td>
                        </tr>
                        <tr>
                            <th>Observaciones</th>
                            <td>{{ $branch->observation ?: 'Sin observaciones' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modal de confirmación de eliminación

         Reemplaza el confirm() nativo del navegador por un modal
         Bootstrap consistente con el resto del sistema, mostrando
         las consecuencias antes de confirmar.
         ============================================================ --}}
    <div class="modal fade" id="modalEliminarBranch" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Sucursal</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar la sucursal <strong>{{ $branch->name }}</strong>?</p>

                    {{-- Advertencia con el resumen de dependencias --}}
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Esta acción no se puede deshacer.</strong>
                        <br>
                        La sucursal tiene
                        {{ $branch->clients()->count() }} clientes,
                        {{ $branch->contracts()->count() }} contratos,
                        {{ $branch->olts()->count() }} OLTs y
                        {{ $branch->onts()->count() }} ONTs asociados.
                        Si existen registros relacionados, la eliminación puede fallar.
                    </div>
                </div>
                <div class="modal-footer">
                    <form action="{{ route('branches.destroy', $branch) }}" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Sí, eliminar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
