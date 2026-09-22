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
                            {{-- El dueno entre parentesis: un tecnico puede
                                 tener varios almacenes y hay que poder
                                 distinguir de cual se mueve el material. --}}
                            <option value="{{ $warehouse->id }}">{{ $warehouse->description }} ({{ $warehouse->duenoVisible() }})</option>
                        @endforeach
                    </select>
                </div>

                {{-- Almacén de destino (Entrada y Transferencia) --}}
                <div class="form-group col-md-4" id="warehouse-destination-group" style="display: none;">
                    <label for="warehouse_destination_id">Almacén de Destino</label>
                    <select name="warehouse_destination_id" id="warehouse_destination_id" class="form-control">
                        <option value="">Seleccione...</option>
                        @foreach ($warehouses as $warehouse)
                            {{-- El dueno entre parentesis: un tecnico puede
                                 tener varios almacenes y hay que poder
                                 distinguir de cual se mueve el material. --}}
                            <option value="{{ $warehouse->id }}">{{ $warehouse->description }} ({{ $warehouse->duenoVisible() }})</option>
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

            {{-- ============================================================
                 De quién se compró: solo en las entradas

                 Va en el movimiento y no en cada material porque una
                 compra es UNA factura. Sale después en el comprobante,
                 en el historial y en el Excel.
                 ============================================================ --}}
            <div class="row d-none" id="datos-compra">
                <div class="form-group col-md-5">
                    <label for="supplier">Proveedor</label>
                    <input type="text" name="supplier" id="supplier" class="form-control" maxlength="150"
                           list="proveedores-usados" value="{{ old('supplier') }}"
                           placeholder="A quién se le compró">
                    <datalist id="proveedores-usados">
                        @foreach($proveedores as $proveedor)
                            <option value="{{ $proveedor }}"></option>
                        @endforeach
                    </datalist>
                    <small class="form-text text-muted">Se proponen los ya usados: así el mismo proveedor no queda escrito de tres formas.</small>
                </div>
                <div class="form-group col-md-4">
                    <label for="invoice_number">Número de factura</label>
                    <input type="text" name="invoice_number" id="invoice_number" class="form-control" maxlength="60"
                           value="{{ old('invoice_number') }}" placeholder="La del proveedor">
                </div>
                <div class="form-group col-md-3">
                    <label for="invoice_date">Fecha de la factura</label>
                    <input type="date" name="invoice_date" id="invoice_date" class="form-control"
                           value="{{ old('invoice_date') }}">
                </div>
            </div>

            {{-- Abre el modal para agregar un material al movimiento --}}
            <div class="form-group">
                <button type="button" class="btn btn-primary" id="open-modal-btn">
                    <i class="fas fa-plus"></i> Agregar Material
                </button>
            </div>

            {{-- Tabla de materiales agregados (filas dinámicas desde movements.js) --}}
            {{-- `data-ver-costos` en vez de repetir el @can dentro del
                 JS: la columna del costo la pinta movements.js al
                 agregar cada fila, y tiene que coincidir con esta
                 cabecera o la tabla queda descuadrada. --}}
            <div class="table-responsive">
                <table class="table table-bordered" id="materials-table"
                       data-ver-costos="{{ auth()->check() && \Illuminate\Support\Facades\Gate::allows('materials.costs') ? '1' : '0' }}">
                    <thead>
                    <tr>
                        <th>Material</th>
                        <th>Cantidad</th>
                        <th>Unidad de Medida</th>
                        @can('materials.costs')
                            <th class="text-right">Valor unit. de compra</th>
                        @endcan
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
    <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel" aria-hidden="true"
         data-url-seriales="{{ route('movements.serials_file') }}">
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
                                        {{-- Valor de REFERENCIA del catálogo: se propone
                                             al elegir el material y se puede cambiar o
                                             borrar. Lo que se guarda es lo que quede en
                                             la casilla, no esto. --}}
                                        data-purchase-value="{{ $material->purchase_unit_value }}"
                                        data-unit="{{ $material->unit_of_measurement }}"
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

                    {{-- LA UNIDAD YA NO SE PREGUNTA: viene del material.
                         Se ENSEÑA junto a la cantidad para que quien
                         registra sepa contra qué está contando, pero no es
                         un campo: se declara al crear el material. --}}
                    <div class="row">
                        <div class="form-group col-md-6">
                            <label for="modal-quantity">Cantidad <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" id="modal-quantity" class="form-control quantity-input"
                                       min="1" step="1" placeholder="0">
                                <div class="input-group-append">
                                    <span class="input-group-text" id="modal-unit-label">—</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    @can('materials.costs')
                        {{-- ============================================================
                             Valor unitario de compra — solo en ENTRADAS.

                             En un traslado el costo ya viaja con la existencia
                             desde el almacén de origen; dejar que se reescriba
                             aquí permitiría revaluar inventario moviéndolo de
                             sitio. En una salida no hay nada que costear. El
                             servidor lo ignora igual (`valorDeCompraDeLaEntrada`),
                             esto solo evita enseñar una casilla que no hace nada.
                             ============================================================ --}}
                        <div class="row d-none" id="modal-valor-compra-group">
                            <div class="form-group col-md-6">
                                <label for="modal-purchase-unit-value">
                                    Valor unitario de compra <small class="text-muted">(opcional)</small>
                                </label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" id="modal-purchase-unit-value"
                                           class="form-control" min="0" step="0.01"
                                           placeholder="Déjelo vacío si no se conoce">
                                </div>
                                <small class="form-text text-muted">
                                    Lo que se paga por CADA unidad de este ingreso. Se propone el valor
                                    del catálogo; si lo deja vacío, el material entra sin valorar y no
                                    se suma al total del inventario.
                                </small>
                            </div>
                        </div>
                    @endcan

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

                        {{-- Muchos equipos: los seriales desde un archivo. Sirve
                             para los tres tipos; en salidas y transferencias
                             el servidor comprueba que cada uno esté en el
                             almacén de origen, y en entradas que no esté ya
                             en el inventario. --}}
                        <div class="border rounded p-2 mb-2 bg-light">
                            <label for="serial-file" class="small font-weight-bold mb-1">
                                <i class="fas fa-file-upload mr-1"></i> Cargar los seriales desde un archivo
                            </label>
                            <input type="file" id="serial-file" class="form-control-file form-control-sm"
                                   accept=".txt,.csv,.xlsx,.xls">
                            <small class="form-text text-muted">
                                <code>.txt</code>, <code>.csv</code>, <code>.xlsx</code> o <code>.xls</code>: un serial por fila.
                                Si la primera fila dice «Serial» o «SN» se usa esa columna. La cantidad se ajusta sola
                                a los seriales válidos.
                            </small>
                            <div id="serial-file-result" class="small mt-2 d-none"></div>
                        </div>

                        {{-- Salida / Transferencia: elegir de los que hay.
                             Lo elegido pasa a la lista de abajo, que es la
                             que se envía. --}}
                        <div id="serial-picker" class="d-none mb-2">
                            <select id="serial-number-select" class="form-control" multiple></select>
                            <small class="form-text text-muted">
                                Escriba para filtrar. Lo que elija se agrega a la lista.
                            </small>
                            <div class="alert alert-warning py-2 mt-2 d-none" id="serial-vacio">
                                Este equipo no tiene unidades con serial en el almacén de origen.
                            </div>
                        </div>

                        {{-- Escribir o escanear: cada Enter agrega uno --}}
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="serial-quick" class="form-control" autocomplete="off"
                                   placeholder="Escriba o escanee el serial y pulse Enter">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-primary" id="serial-add-btn">
                                    <i class="fas fa-plus"></i> Agregar
                                </button>
                            </div>
                        </div>

                        {{-- ---------- La lista, que es lo que se envía ----------
                             Paginada y con buscador: una entrada de 500 equipos
                             dejaba una pantalla imposible de recorrer. --}}
                        <div class="input-group input-group-sm mb-2 d-none" id="serial-filtro-grupo">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                            </div>
                            <input type="text" id="serial-filtro" class="form-control"
                                   placeholder="Buscar un serial de la lista">
                        </div>

                        <ul id="serial-number-list" class="list-unstyled mb-0"></ul>

                        <div class="d-flex justify-content-between align-items-center mt-1 d-none" id="serial-paginador">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="serial-anterior">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </button>
                            <small class="text-muted" id="serial-pagina-texto"></small>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="serial-siguiente">
                                Siguiente <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>

                        <small class="form-text text-muted">
                            Enter agrega el serial a la lista; también puede pegar varios de una vez
                            (uno por línea o separados por comas). La cantidad la dan los seriales.
                        </small>
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

@endsection
