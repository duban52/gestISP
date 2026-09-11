@extends('adminlte::page')

@section('title', 'Almacenes')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR ALMACENES</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Alertas de sesión (resultado de crear/editar/eliminar)
         ============================================================ --}}
    @if(session('success-create'))
        <div class="alert alert-success">{{ session('success-create') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger">{{ session('success-delete') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Botón de creación
         ============================================================ --}}
    <div class="card">
        <div class="card-header d-flex justify-content-end">
            <a class="btn btn-primary" href="{{ route('warehouses.create') }}">
                Crear Almacén <i class="fas fa-plus-circle"></i>
            </a>
        </div>
    </div>

    {{-- ============================================================
         Tabla de almacenes (DataTables)

         La columna "Materiales en inventario" viene del withCount()
         del controlador (inventories_count), sin consultas extra
         por fila. La paginación, búsqueda y ordenamiento los maneja
         DataTables en el navegador.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="warehousesTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Sucursal</th>
                        <th>Descripción</th>
                        <th>Materiales en inventario</th>
                        {{-- DOS COLUMNAS, no una. Antes solo estaba «Creado
                             por» y era la misma columna que hacía de dueño:
                             por eso un almacén creado por la oficina para un
                             técnico salía a nombre de la oficina, y de ahí
                             descargaban las órdenes que cerraba la oficina. --}}
                        <th>Pertenece a</th>
                        <th>Creado por</th>
                        <th>Fecha de creación</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($warehouses as $warehouse)
                        <tr>
                            {{-- Solo se ve en panel consolidado; DataTables la
                                 oculta en los demas modos (ver el columnDef de
                                 mas abajo). Se pinta siempre para que los
                                 indices de columna no cambien segun el modo. --}}
                            <td>{{ $warehouse->branch?->name ?: '—' }}</td>
                            <td>{{ $warehouse->description }}</td>

                            {{-- Cantidad de materiales distintos con stock registrado --}}
                            <td>
                                    <span class="badge badge-info">
                                        {{ $warehouse->inventories_count }}
                                    </span>
                            </td>

                            {{-- Sin dueño NO es un dato que falte: es una
                                 bodega, el de cabecera, uno general. Por eso
                                 se nombra en vez de pintar un guion. --}}
                            <td>
                                @if($warehouse->esGeneral())
                                    <span class="badge badge-secondary">General</span>
                                @else
                                    {{ $warehouse->duenoVisible() }}
                                @endif
                            </td>

                            <td>
                                {{ $warehouse->creator?->name ?? '—' }}
                                {{ $warehouse->creator?->last_name ?? '' }}
                            </td>

                            <td>{{ $warehouse->created_at->format('Y-m-d') }}</td>

                            <td>
                                {{-- Ver inventario del almacén --}}
                                <a class="btn btn-success btn-sm"
                                   href="{{ route('warehouses.show', $warehouse) }}"
                                   title="Ver Inventario">
                                    <i class="far fa-eye"></i> Inventario
                                </a>


                                {{-- Editar: nombre y dueño. El backend ya lo
                                     tenía, pero no estaba enlazado desde aquí,
                                     así que reasignar un almacén exigía tocar
                                     la base de datos. --}}
                                <a class="btn btn-warning btn-sm"
                                   href="{{ route('warehouses.edit', $warehouse) }}"
                                   title="Editar nombre y dueño">
                                    <i class="fas fa-pencil-alt"></i> Editar
                                </a>

                                {{-- Eliminar almacén (abre modal de confirmación) --}}
                                <button
                                    class="btn btn-danger btn-sm btn-eliminar-warehouse"
                                    data-id="{{ $warehouse->id }}"
                                    data-nombre="{{ $warehouse->description }}"
                                    data-inventario="{{ $warehouse->inventories_count }}"
                                    title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modal de confirmación de eliminación

         Un único modal reutilizable: el botón de cada fila carga
         la descripción, el conteo de inventario y arma la URL del
         formulario dinámicamente.
         ============================================================ --}}
    <div class="modal fade" id="modalEliminarWarehouse" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Almacén</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el almacén
                        <strong id="eliminarWarehouseNombre"></strong>?</p>

                    {{-- Advertencia contextual según tenga o no inventario --}}
                    <div id="warehouseInventarioAlert" class="alert alert-warning" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Este almacén tiene <strong><span id="eliminarWarehouseInventario"></span></strong>
                        materiales en inventario. La eliminación será bloqueada
                        hasta que traslade o dé de baja el material.
                    </div>

                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarWarehouse" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Sí, eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

{{-- ============================================================
     Estilos de DataTables (tema Bootstrap 4, compatible con AdminLTE)
     ============================================================ --}}
@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

{{-- ============================================================
     Scripts de DataTables e inicialización
     ============================================================ --}}
@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#warehousesTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay almacenes registrados.'
                },

                // Registros visibles por página
                pageLength: 25,

                // Orden inicial: por descripción (columna 0) ascendente
                order: [[1, 'asc']],

                columnDefs: [
                    // La sucursal se pinta siempre para que los indices de
                    // columna no cambien con el modo de trabajo; DataTables
                    // la esconde cuando no hay varias sedes que distinguir.
                    { visible: {{ $mostrarSucursal ? 'true' : 'false' }}, targets: 0 },
                    // La columna de acciones (índice 4) no es ordenable
                    { orderable: false, targets: [6] },

                    // Evita el warning de DataTables cuando una celda llega vacía
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        /**
         * Abre el modal de confirmación de eliminación.
         * Carga la descripción y el conteo de inventario del almacén,
         * mostrando la advertencia solo cuando hay material registrado.
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-warehouse')) {
                const btn        = e.target.closest('.btn-eliminar-warehouse');
                const inventario = parseInt(btn.getAttribute('data-inventario'), 10);

                document.getElementById('eliminarWarehouseNombre').textContent =
                    btn.getAttribute('data-nombre');
                document.getElementById('formEliminarWarehouse').action =
                    `/warehouses/${btn.getAttribute('data-id')}`;

                // Mostrar la advertencia solo si el almacén tiene inventario
                const alertBox = document.getElementById('warehouseInventarioAlert');
                if (inventario > 0) {
                    document.getElementById('eliminarWarehouseInventario').textContent = inventario;
                    alertBox.style.display = 'block';
                } else {
                    alertBox.style.display = 'none';
                }

                $('#modalEliminarWarehouse').modal('show');
            }
        });
    </script>
@endsection
