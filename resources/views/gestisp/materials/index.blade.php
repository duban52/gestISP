@extends('adminlte::page')

@section('title', 'Materiales')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR MATERIALES</h2>
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
         Acciones: gestión de categorías y creación de materiales
         ============================================================ --}}
    <div class="card p-3">
        <div class="d-flex justify-content-end">
            <a href="{{ route('categories.index') }}" class="btn btn-warning mr-md-2">
                <i class="fas fa-tags"></i> Categorías
            </a>
            <a href="{{ route('materials.create') }}" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Nuevo material
            </a>
        </div>
    </div>

    {{-- ============================================================
         Tabla de materiales (DataTables)

         La categoría viene precargada con with() y el conteo de
         inventario con withCount() desde el controlador, evitando
         consultas N+1. Búsqueda, orden y paginación corren en el
         navegador.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="materialsTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Categoría</th>
                        <th>Tipo</th>
                        <th>Registros en inventario</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($materials as $material)
                        <tr>
                            <td>{{ $material->name }}</td>
                            <td>{{ $material->category->name ?? '—' }}</td>

                            {{-- Tipo como badge legible en lugar del
                                 checkbox deshabilitado anterior --}}
                            <td>
                                @if($material->is_equipment)
                                    <span class="badge badge-primary">
                                            <i class="fas fa-barcode"></i> Equipo (con serial)
                                        </span>
                                @else
                                    <span class="badge badge-secondary">
                                            Consumible
                                        </span>
                                @endif
                            </td>

                            {{-- Cantidad de registros de stock del material --}}
                            <td>
                                    <span class="badge badge-info">
                                        {{ $material->inventories_count }}
                                    </span>
                            </td>

                            <td>
                                {{-- Editar material --}}
                                <a href="{{ route('materials.edit', $material) }}"
                                   class="btn btn-warning btn-sm"
                                   title="Editar">
                                    <i class="fas fa-pencil-alt"></i> Editar
                                </a>

                                {{-- Eliminar material (abre modal de confirmación) --}}
                                <button
                                    class="btn btn-danger btn-sm btn-eliminar-material"
                                    data-id="{{ $material->id }}"
                                    data-nombre="{{ $material->name }}"
                                    data-inventario="{{ $material->inventories_count }}"
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
         el nombre, el conteo de inventario y arma la URL del
         formulario dinámicamente.
         ============================================================ --}}
    <div class="modal fade" id="modalEliminarMaterial" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Material</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el material
                        <strong id="eliminarMaterialNombre"></strong>?</p>

                    {{-- Advertencia contextual cuando tiene inventario --}}
                    <div id="materialInventarioAlert" class="alert alert-warning" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Este material tiene <strong><span id="eliminarMaterialInventario"></span></strong>
                        registros en inventario. La eliminación será bloqueada.
                    </div>

                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarMaterial" method="POST">
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
            $('#materialsTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay materiales registrados.'
                },

                // Registros visibles por página
                pageLength: 25,

                // Orden inicial: por nombre (columna 0) ascendente
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
         * Carga el nombre y el conteo de inventario del material,
         * mostrando la advertencia solo cuando hay stock registrado.
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-material')) {
                const btn        = e.target.closest('.btn-eliminar-material');
                const inventario = parseInt(btn.getAttribute('data-inventario'), 10);

                document.getElementById('eliminarMaterialNombre').textContent =
                    btn.getAttribute('data-nombre');
                document.getElementById('formEliminarMaterial').action =
                    `/materials/${btn.getAttribute('data-id')}`;

                // Mostrar la advertencia solo si el material tiene inventario
                const alertBox = document.getElementById('materialInventarioAlert');
                if (inventario > 0) {
                    document.getElementById('eliminarMaterialInventario').textContent = inventario;
                    alertBox.style.display = 'block';
                } else {
                    alertBox.style.display = 'none';
                }

                $('#modalEliminarMaterial').modal('show');
            }
        });
    </script>
@endsection
