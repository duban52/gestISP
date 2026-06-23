@extends('adminlte::page')

@section('title', 'Registrar Movimiento de Material')

@section('content_header')
    <div class="card p-3">
        <h2>REGISTRAR MOVIMIENTO DE MATERIAL</h2>
    </div>
@endsection

@section('content')
    @if(session('success-create'))
        <div class="alert alert-info">
            {{ session('success-create') }}
        </div>
    @elseif(session('success-update'))
        <div class="alert alert-info">
            {{ session('success-update') }}
        </div>
    @elseif(session('success-delete'))
        <div class="alert alert-info">
            {{ session('success-delete') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $error)
                {{ $error }}<br>
            @endforeach
        </div>
    @endif

    <div class="card p-3">
        <form action="{{ route('movements.store') }}" method="POST" id="movementForm">
            @csrf

            <div class="row">
                <div class="form-group col-md-4">
                    <label for="type">Tipo de Movimiento</label>
                    <select name="type" id="type" class="form-control" required>
                        <option value="">Seleccione...</option>
                        <option value="Entrada">Entrada</option>
                        <option value="Salida">Salida</option>
                        <option value="Transferencia">Transferencia</option>
                    </select>
                </div>

                <div class="form-group col-md-4" id="warehouse-origin-group" style="display: none;">
                    <label for="warehouse_origin_id">Almacén de Origen</label>
                    <select name="warehouse_origin_id" id="warehouse_origin_id" class="form-control">
                        <option value="">Seleccione...</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->description }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group col-md-4" id="warehouse-destination-group" style="display: none;">
                    <label for="warehouse_destination_id">Almacén de Destino</label>
                    <select name="warehouse_destination_id" id="warehouse_destination_id" class="form-control">
                        <option value="">Seleccione...</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->description }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group col-md-12">
                    <label for="reason">Motivo del movimiento</label>
                    <select name="reason" id="reason" class="form-control" required>
                        <option value="">Seleccione...</option>
                        <option value="Compra" class="option-Entrada">Entrada por Compra de materiales</option>
                        <option value="Inicial" class="option-Entrada">Entrada por Inventario inicial</option>
                        <option value="Devolucion" class="option-Entrada">Entrada por Devolución de materiales</option>
                        <option value="Deterioro" class="option-Salida">Salida por deterioro</option>
                        <option value="Venta" class="option-Salida">Salida por venta</option>
                        <option value="Orden" class="option-Salida">Salida por orden técnica</option>
                        <option value="Transferencia" class="option-Transferencia">Transferencia entre almacenes</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <button type="button" class="btn btn-primary" id="open-modal-btn">
                    <i class="fas fa-plus"></i> Agregar Material
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered" id="materials-table">
                    <thead>
                    <tr>
                        <th>Material</th>
                        <th>Cantidad</th>
                        <th>Unidad de Medida</th>
                        <th>Números de Serie</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <!-- Filas de materiales agregados dinámicamente -->
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-success">Registrar Movimiento</button>
        </form>
    </div>

    <!-- Modal para agregar material -->
    <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="materialModalLabel">Agregar Material</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Material</label>
                        <select id="modal-material-select" class="form-control material-select select2" required>
                            <option value="">Seleccione un material</option>
                            @foreach ($materials as $material)
                                <option value="{{ $material->id }}"
                                        data-is-equipment="{{ $material->is_equipment }}"
                                        data-name="{{ $material->name }}">
                                    {{ $material->name }}
                                </option>
                            @endforeach
                        </select>
                        <small id="available-quantity-text" class="text-info mt-1" style="display: none;">
                            Cantidad disponible: <span id="available-quantity">0</span>
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Cantidad</label>
                        <input type="number" id="modal-quantity" class="form-control quantity-input" required min="1">
                    </div>

                    <div class="form-group">
                        <label>Unidad de Medida</label>
                        <select id="modal-unit-of-measurement" class="form-control" required>
                            <option value="">Seleccione...</option>
                            <option value="Unidades">Unidades</option>
                            <option value="Metros">Metros</option>
                            <option value="Litros">Litros</option>
                            <option value="Paquetes">Paquetes</option>
                        </select>
                    </div>

                    <div id="modal-serial-numbers-container" style="display:none;">
                        <label for="serial-number-select">Números de Serie Disponibles</label>
                        <select id="serial-number-select" class="form-control" multiple>
                            <!-- Números de serie disponibles se agregarán aquí -->
                        </select>
                        <ul id="serial-number-list" style="list-style-type: none; padding: 0; margin-top: 10px;">
                            <!-- Lista de números de serie disponibles se agregarán aquí -->
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" id="add-material-modal-btn">Agregar Material</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para mostrar el PDF -->
    @if(session('pdfPath'))
        <div class="modal fade" id="pdfModal" tabindex="-1" role="dialog" aria-labelledby="pdfModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pdfModalLabel">Resumen del Movimiento</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <iframe src="{{ asset('storage/' . basename(session('pdfPath'))) }}" width="100%" height="500px"></iframe>
                    </div>
                    <div class="modal-footer">
                        <a href="{{ asset('storage/' . basename(session('pdfPath'))) }}" class="btn btn-primary" target="_blank">Guardar</a>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@section('css')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
@endsection

@section('js')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="@vite('resources/js/movements/movements.js')"></script>

    <script>
        @if(session('pdfPath'))
        $(document).ready(function() {
            $('#pdfModal').modal('show');
        });
        @endif
    </script>
@endsection
