@extends('adminlte::page')

@section('title', 'Servicios')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR SERVICIOS</h2>
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
            <a class="btn btn-primary" href="{{ route('services.create') }}">
                Crear Servicio <i class="fas fa-plus-circle"></i>
            </a>
        </div>
    </div>

    {{-- ============================================================
         Tabla de servicios (DataTables)

         La paginación, búsqueda y ordenamiento los maneja
         DataTables en el navegador, por eso el controlador
         envía la colección completa sin paginar.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="servicesTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Precio Base</th>
                        <th>Impuesto (IVA)</th>
                        <th>Precio Final</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($services as $service)
                        <tr>
                            <td>{{ $service->name }}</td>

                            {{-- Precio base con formato de moneda --}}
                            <td>${{ number_format($service->base_price, 0, ',', '.') }}</td>

                            {{-- Porcentaje de impuesto --}}
                            <td>{{ $service->tax_percentage }}%</td>

                            {{-- Precio final calculado (base + impuesto) --}}
                            <td>
                                ${{ number_format(
                                        $service->base_price * (1 + $service->tax_percentage / 100),
                                        0, ',', '.'
                                    ) }}
                            </td>

                            <td>
                                {{-- Editar servicio --}}
                                <a class="btn btn-warning btn-sm"
                                   href="{{ route('services.edit', $service) }}"
                                   title="Editar">
                                    <i class="fas fa-pencil-alt"></i> Modificar
                                </a>

                                {{-- Eliminar servicio (abre modal de confirmación) --}}
                                <button
                                    class="btn btn-danger btn-sm btn-eliminar-service"
                                    data-id="{{ $service->id }}"
                                    data-nombre="{{ $service->name }}"
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
         el nombre y arma la URL del formulario dinámicamente.
         ============================================================ --}}
    <div class="modal fade" id="modalEliminarService" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Servicio</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el servicio
                        <strong id="eliminarServiceNombre"></strong>?</p>
                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer. Si el servicio tiene
                        planes asociados, la eliminación será bloqueada.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarService" method="POST">
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
            $('#servicesTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay servicios registrados.'
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
         * Toma el id y nombre del botón pulsado, arma la URL
         * del formulario DELETE y muestra el modal.
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-service')) {
                const btn = e.target.closest('.btn-eliminar-service');

                document.getElementById('eliminarServiceNombre').textContent =
                    btn.getAttribute('data-nombre');
                document.getElementById('formEliminarService').action =
                    `/services/${btn.getAttribute('data-id')}`;

                $('#modalEliminarService').modal('show');
            }
        });
    </script>
@endsection
