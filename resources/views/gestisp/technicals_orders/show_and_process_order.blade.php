@extends('adminlte::page')

@section('title', 'Procesar Orden Técnica')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center mb-0">
        <h2 class="mb-0">VER Y PROCESAR ORDEN {{ $technicalOrder->id }}</h2>
        <a href="{{ route('technicals_orders.my_technical_orders') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
            <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
            @foreach($errors->all() as $error)
                <div><i class="fas fa-exclamation-triangle mr-1"></i> {{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="row">
        {{-- ============================================================
             Columna izquierda: información de la orden y el cliente
             ============================================================ --}}
        <div class="card p-3 mt-1 col-md-6">
            <h3>Datos del cliente</h3>
            <p><strong>Número de contrato:</strong> {{ $technicalOrder->contract->id }}</p>
            <p><strong>Identificación del cliente:</strong> {{ $technicalOrder->contract->client->identity_number }}</p>
            <p><strong>Nombre y apellido:</strong>
                {{ $technicalOrder->contract->client->name }}
                {{ $technicalOrder->contract->client->last_name }}
            </p>
            <p><strong>Teléfonos:</strong>
                {{ $technicalOrder->contract->client->number_phone }}{{ $technicalOrder->contract->client->aditional_phone ? ', ' . $technicalOrder->contract->client->aditional_phone : '' }}
            </p>
            <hr>
            <h3>Residencia</h3>
            <p><strong>Barrio:</strong> {{ $technicalOrder->contract->neighborhood }}</p>
            <p><strong>Dirección:</strong> {{ $technicalOrder->contract->address }}</p>
            <hr>
            <h3>Datos de la orden</h3>
            <p><strong>Tipo de orden:</strong> {{ $technicalOrder->type }}</p>
            <p>
                <strong>Detalle de orden:</strong> {{ $technicalOrder->detail }}
                @if($requiresMaterial)
                    <span class="badge badge-warning ml-1">Requiere material</span>
                @endif
            </p>
            <p><strong>Comentario inicial:</strong> {{ $technicalOrder->initial_comment ?? '—' }}</p>
            <p>
                <strong>Creada el:</strong> {{ $technicalOrder->created_at?->format('Y-m-d H:i') ?? 'N/A' }}
                <strong>Por:</strong>
                {{ $technicalOrder->createdBy->name ?? 'Sistema' }}
                {{ $technicalOrder->createdBy->last_name ?? '' }}
            </p>
        </div>

        {{-- ============================================================
             Columna derecha: formulario de procesamiento
             ============================================================ --}}
        <div class="card mt-1 p-3 col-md-6">
            <h3>Procesamiento de orden</h3>
            <form action="{{ route('technicals_orders.process', $technicalOrder->id) }}" method="post"
                  enctype="multipart/form-data" id="process-order-form"
                  data-requires-material="{{ $requiresMaterial ? '1' : '0' }}">
                @csrf
                <div class="form-group">
                    <label for="observations_technical">Comentario del técnico</label>
                    <textarea class="form-control" name="observations_technical"
                              id="observations_technical" required>{{ old('observations_technical') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="client_observation">Comentario del usuario</label>
                    <textarea class="form-control" name="client_observation"
                              id="client_observation" required>{{ old('client_observation') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="solution">Solución aplicada</label>
                    <textarea class="form-control" name="solution"
                              id="solution" required>{{ old('solution') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="images">Selecciona imágenes (evidencia):</label>
                    <input class="form-control-file" type="file" name="images[]" id="images"
                           multiple accept="image/*">
                </div>

                {{-- Materiales -------------------------------------------------- --}}
                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                    <label class="mb-0 font-weight-bold">Materiales utilizados</label>
                    <button type="button" class="btn btn-primary btn-sm" id="open-modal-btn">
                        <i class="fas fa-plus"></i> Agregar Material
                    </button>
                </div>

                @if($requiresMaterial)
                    <div class="alert alert-info py-2 px-3 small mb-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Esta orden de <strong>instalación</strong> requiere registrar el material y
                        los equipos instalados antes de procesarla.
                    </div>
                @endif

                {{-- Materiales reportados (filas dinámicas de order_process.js) --}}
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle" id="materials-table">
                        <thead class="thead-light">
                        <tr>
                            <th>Material</th>
                            <th class="text-center" style="width: 90px;">Cantidad</th>
                            <th>Unidad</th>
                            <th>Números de Serie</th>
                            <th class="text-center" style="width: 70px;">Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        {{-- Fila vacía inicial (la gestiona el JS) --}}
                        <tr id="no-materials-row">
                            <td colspan="5" class="text-center text-muted py-3">
                                <i class="fas fa-box-open mr-1"></i> Aún no se ha agregado material
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-success mt-2">
                    <i class="fas fa-check mr-1"></i> Procesar orden
                </button>
            </form>
        </div>
    </div>

    {{-- ============================================================
         Modal para agregar material

         Cada opción trae la disponibilidad y los seriales incrustados
         (data-*), así el modal no depende de ninguna llamada AJAX.
         ============================================================ --}}
    <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="materialModalLabel">
                        <i class="fas fa-box mr-1"></i> Agregar Material
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Material</label>
                        <select id="modal-material-select" class="form-control material-select" required>
                            <option value="">Seleccione un material</option>
                            @foreach ($materials as $material)
                                <option value="{{ $material->id }}"
                                        data-is-equipment="{{ $material->is_equipment ? '1' : '0' }}"
                                        data-name="{{ $material->name }}"
                                        data-available="{{ $material->total_quantity }}"
                                        data-serials='@json($material->serial_numbers)'>
                                    {{ $material->name }} (Disp: {{ $material->total_quantity }})
                                </option>
                            @endforeach
                        </select>
                        <small id="available-quantity-text" class="form-text text-muted" style="display: none;">
                            Disponible en tu almacén:
                            <span class="badge badge-info" id="available-quantity">0</span>
                        </small>
                    </div>

                    {{-- Consumibles: cantidad manual --}}
                    <div class="form-group" id="modal-quantity-group">
                        <label>Cantidad</label>
                        <input type="number" id="modal-quantity" class="form-control quantity-input" min="1" value="1">
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

                    {{-- Equipos: selección de seriales (la cantidad la
                         determina cuántos seriales se marquen) --}}
                    <div class="form-group" id="modal-serial-numbers-container" style="display:none;">
                        <label for="serial-number-select">Números de Serie a instalar</label>
                        <select id="serial-number-select" class="form-control" multiple>
                            {{-- Opciones incrustadas desde data-serials --}}
                        </select>
                        <small class="form-text text-muted">
                            La cantidad se calcula según los seriales seleccionados.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-1"></i> Cerrar
                    </button>
                    <button type="button" class="btn btn-primary" id="add-material-modal-btn">
                        <i class="fas fa-plus mr-1"></i> Agregar
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* El desplegable del modal debe quedar por encima del backdrop */
        .select2-container { z-index: 1060; }
        #materials-table td, #materials-table th { vertical-align: middle; }
        .serial-badge {
            display: inline-block;
            background: #e9f2ff;
            color: #0b57d0;
            border: 1px solid #b6d4fe;
            border-radius: 12px;
            padding: 1px 8px;
            margin: 1px 2px;
            font-size: .78rem;
        }
        /* Diálogos SweetAlert con los botones de Bootstrap del sistema */
        .swal2-popup .swal2-styled.swal2-confirm { font-weight: 500; }
    </style>
@endsection

@section('js')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    {{-- CORRECCIÓN: la versión anterior cargaba
         /resources/js/technical_orders/order_process.js — esa ruta
         no existe en el navegador (resources/ no es pública) y falla
         en producción. Con Vite el archivo se compila y versiona. --}}
    @vite('resources/js/technical_orders/order_process.js')
@endsection
