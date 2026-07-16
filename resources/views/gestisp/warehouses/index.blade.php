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
                        <th>Descripción</th>
                        <th>Materiales en inventario</th>
                        <th>Creado por</th>
                        <th>Fecha de creación</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($warehouses as $warehouse)
                        <tr>
                            <td>{{ $warehouse->description }}</td>

                            {{-- Cantidad de materiales distintos con stock registrado --}}
                            <td>
                                    <span class="badge badge-info">
                                        {{ $warehouse->inventories_count }}
                                    </span>
                            </td>

                            <td>
                                {{ $warehouse->user->name ?? '—' }}
                                {{ $warehouse->user->last_name ?? '' }}
                            </td>

                            <td>{{ $warehouse->created_at->format('Y-m-d') }}</td>

                            <td>
                                {{-- Ver inventario del almacén --}}
                                <a class="btn btn-success btn-sm"
                                   href="{{ route('warehouses.show', $warehouse) }}"
                                   title="Ver Inventario">
                                    <i class="far fa-eye"></i> Inventario
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
                order: [[0, 'asc']],

                columnDefs: [
                    // La columna de acciones (índice 4) no es ordenable
                    { orderable: false, targets: [4] },

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
