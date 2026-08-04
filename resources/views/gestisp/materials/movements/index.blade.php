@extends('adminlte::page')

@section('title', 'Registrar Movimiento de Material')

@section('content_header')
    <div class="card p-3">
        <h2>REGISTRAR MOVIMIENTO DE MATERIAL</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Alertas de sesión y errores de validación
         ============================================================ --}}
    @if(session('success-create'))
        <div class="alert alert-success">{{ session('success-create') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $error)
                {{ $error }}<br>
            @endforeach
        </div>
    @endif

    {{-- ============================================================
         Formulario principal de movimiento

         El flujo lo controla resources/js/movements/movements.js:
         - Según el tipo, muestra/oculta almacén origen y destino
           (Entrada: solo destino, Salida: solo origen,
            Transferencia: ambos)
         - Filtra los motivos según el tipo (clases option-*)
         - El modal agrega materiales a la tabla como inputs ocultos
           con la notación materials[i][campo]
         ============================================================ --}}
    <div class="card p-3">
        <form action="{{ route('movements.store') }}" method="POST" id="movementForm">
            @csrf

            <div class="row">
                {{-- Tipo de movimiento: determina qué almacenes se piden --}}
                <div class="form-group col-md-4">
                    <label for="type">Tipo de Movimiento</label>
                    <select name="type" id="type" class="form-control" required>
                        <option value="">Seleccione...</option>
                        <option value="Entrada">Entrada</option>
                        <option value="Salida">Salida</option>
                        <option value="Transferencia">Transferencia</option>
                    </select>
                </div>

                {{-- Almacén de origen (Salida y Transferencia) --}}
                <div class="form-group col-md-4" id="warehouse-origin-group" style="display: none;">
                    <label for="warehouse_origin_id">Almacén de Origen</label>
                    <select name="warehouse_origin_id" id="warehouse_origin_id" class="form-control">
                        <option value="">Seleccione...</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->description }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Almacén de destino (Entrada y Transferencia) --}}
                <div class="form-group col-md-4" id="warehouse-destination-group" style="display: none;">
                    <label for="warehouse_destination_id">Almacén de Destino</label>
                    <select name="warehouse_destination_id" id="warehouse_destination_id" class="form-control">
                        <option value="">Seleccione...</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->description }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Motivo: las opciones se filtran por tipo con las
                     clases option-Entrada / option-Salida / option-Transferencia --}}
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

            {{-- Abre el modal para agregar un material al movimiento --}}
            <div class="form-group">
                <button type="button" class="btn btn-primary" id="open-modal-btn">
                    <i class="fas fa-plus"></i> Agregar Material
                </button>
            </div>

            {{-- Tabla de materiales agregados (filas dinámicas desde movements.js) --}}
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
                    {{-- Filas agregadas dinámicamente por movements.js --}}
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> Registrar Movimiento
            </button>
        </form>
    </div>

    {{-- ============================================================
         Modal para agregar material al movimiento

         movements.js consulta vía AJAX:
         - /movements/quantity/{warehouse}/{material}: disponibilidad
         - /movements/serials/{warehouse}/{material}: seriales (equipos)
         ============================================================ --}}
    <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="materialModalLabel">
                        <i class="fas fa-boxes mr-1"></i> Agregar material al movimiento
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    {{-- Contexto: de qué almacén sale o a cuál entra.
                         Sin esto el operador no sabe contra qué stock
                         se está comparando la disponibilidad. --}}
                    <div class="alert alert-light border py-2 mb-3" id="modal-contexto"></div>

                    <div class="form-group">
                        <label for="modal-material-select">
                            Material <span class="text-danger">*</span>
                        </label>
                        <select id="modal-material-select" class="form-control material-select" required>
                            <option value=""></option>
                            @foreach ($materials as $material)
                                <option value="{{ $material->id }}"
                                        data-is-equipment="{{ $material->is_equipment ? 1 : 0 }}"
                                        data-category="{{ $material->category->name ?? 'Sin categoría' }}"
                                        data-name="{{ $material->name }}">
                                    {{ $material->name }}
                                </option>
                            @endforeach
                        </select>

                        {{-- Disponibilidad real en el almacén de origen --}}
                        <div class="mt-2 d-none" id="available-quantity-text">
                            <span class="badge badge-info" style="font-size: .9rem;">
                                Disponible: <strong id="available-quantity">0</strong>
                            </span>
                            <small class="text-muted ml-1" id="available-hint"></small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="form-group col-md-6">
                            <label for="modal-quantity">Cantidad <span class="text-danger">*</span></label>
                            <input type="number" id="modal-quantity" class="form-control quantity-input"
                                   min="1" step="1" placeholder="0">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="modal-unit-of-measurement">
                                Unidad de medida <span class="text-danger">*</span>
                            </label>
                            <select id="modal-unit-of-measurement" class="form-control">
                                <option value="">Seleccione...</option>
                                <option value="Unidades">Unidades</option>
                                <option value="Metros">Metros</option>
                                <option value="Litros">Litros</option>
                                <option value="Paquetes">Paquetes</option>
                            </select>
                        </div>
                    </div>

                    {{-- ============================================================
                         Seriales — solo para EQUIPOS.

                         Dos comportamientos según el tipo de movimiento:
                           · Entrada: los equipos aún no existen en el
                             sistema, así que se ESCRIBEN sus seriales.
                           · Salida/Transferencia: ya están en el almacén,
                             así que se ELIGEN de los disponibles.
                         ============================================================ --}}
                    <div id="modal-serial-numbers-container" class="d-none">
                        <hr>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="mb-0">
                                <i class="fas fa-barcode mr-1"></i> Números de serie
                            </label>
                            <span class="badge badge-secondary" id="serial-counter">0 de 0</span>
                        </div>

                        {{-- Salida / Transferencia: elegir de los que hay --}}
                        <div id="serial-picker" class="d-none">
                            <select id="serial-number-select" class="form-control" multiple></select>
                            <small class="form-text text-muted">
                                Escriba para filtrar. Debe elegir tantos seriales como unidades.
                            </small>
                            <div class="alert alert-warning py-2 mt-2 d-none" id="serial-vacio">
                                Este equipo no tiene unidades con serial en el almacén de origen.
                            </div>
                        </div>

                        {{-- Entrada: escribir los seriales que ingresan --}}
                        <div id="serial-inputs" class="d-none">
                            <ul id="serial-number-list" class="list-unstyled mb-0"></ul>
                            <small class="form-text text-muted">
                                Un serial por unidad. Se generan tantas casillas como cantidad indique.
                            </small>
                        </div>
                    </div>

                    {{-- Los errores se muestran aquí, junto al campo que
                         los causa, y no en una ventana emergente que tapa
                         el formulario y obliga a cerrarla para corregir. --}}
                    <div class="alert alert-danger mt-3 d-none" id="modal-error"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="add-material-modal-btn">
                        <i class="fas fa-plus"></i> Agregar al movimiento
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modal del PDF de resumen (aparece tras registrar)
         ============================================================ --}}
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
    {{-- @vite genera la etiqueta <script> completa; no debe ir
         dentro de un src="" --}}
    @vite('resources/js/movements/movements.js')

    <script>
        {{-- Mostrar automáticamente el PDF de resumen tras registrar --}}
        @if(session('pdfPath'))
        $(document).ready(function() {
            $('#pdfModal').modal('show');
        });
        @endif
    </script>
@endsection
