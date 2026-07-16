@extends('adminlte::page')

@section('title', 'Procesar Orden Técnica')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <h2 class="mb-0">VER Y PROCESAR ORDEN {{ $technicalOrder->id }}</h2>
        <a href="{{ route('technicals_orders.my_technical_orders') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $error)
                {{ $error }}<br>
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
            <p><strong>Detalle de orden:</strong> {{ $technicalOrder->detail }}</p>
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

             El técnico reporta su trabajo (observaciones, solución,
             evidencia) y el material usado. El JS order_process.js
             (cargado vía Vite) maneja el modal de materiales y agrega
             las filas con inputs material_id[]/quantity[]/serial_number[].
             ============================================================ --}}
        <div class="card mt-1 p-3 col-md-6">
            <h3>Procesamiento de orden</h3>
            <form action="{{ route('technicals_orders.process', $technicalOrder->id) }}" method="post"
                  enctype="multipart/form-data">
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
                    <input class="form-control" type="file" name="images[]" id="images"
                           multiple accept="image/*">
                </div>

                <div class="form-group">
                    <button type="button" class="btn btn-primary col-md-4" id="open-modal-btn">
                        <i class="fas fa-plus"></i> Agregar Material
                    </button>
                </div>

                {{-- Materiales reportados (filas dinámicas de order_process.js) --}}
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
                        {{-- Filas agregadas dinámicamente --}}
                        </tbody>
                    </table>

                    <input type="submit" value="Procesar orden" class="col-md-4 btn btn-success"
                           onclick="return confirmProcess();">
                </div>
            </form>
        </div>
    </div>

    {{-- ============================================================
         Modal para agregar material

         data-warehouse-id: el JS lo usa para consultar disponibilidad
         y seriales del almacén personal del técnico vía AJAX.
         ============================================================ --}}
    <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel"
         aria-hidden="true" data-warehouse-id="{{ $warehouse->id ?? '' }}">
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
                                    {{ $material->name }} (Disp: {{ $material->total_quantity }})
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

                    {{-- Solo visible para equipos: selección del serial --}}
                    <div id="modal-serial-numbers-container" style="display:none;">
                        <label for="serial-number-select">Números de Serie Disponibles</label>
                        <select id="serial-number-select" class="form-control" multiple>
                            {{-- Seriales cargados por AJAX --}}
                        </select>
                        <ul id="serial-number-list" style="list-style-type: none; padding: 0; margin-top: 10px;">
                            {{-- Seriales seleccionados --}}
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
@endsection

@section('css')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
@endsection

@section('js')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    {{-- CORRECCIÓN: la versión anterior cargaba
         /resources/js/technical_orders/order_process.js — esa ruta
         no existe en el navegador (resources/ no es pública) y falla
         en producción. Con Vite el archivo se compila y versiona. --}}
    @vite('resources/js/technical_orders/order_process.js')

    <script>
        function confirmProcess() {
            return confirm('¿Está seguro de procesar la orden?');
        }
    </script>
@endsection
