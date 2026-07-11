@extends('adminlte::page')

@section('title', 'Sucursales')

@section('content')
    {{-- ============================================================
         Alertas de sesión (resultado de crear/editar/eliminar)
         ============================================================ --}}
    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    {{-- ============================================================
         Encabezado con título y botón de creación
         ============================================================ --}}
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between">
            <div class="col-md-6">
                <h2>ADMINISTRAR SUCURSALES</h2>
            </div>
            <div class="col-md-6 d-flex justify-content-end">
                <a class="btn btn-primary" href="{{ route('branches.create') }}">
                    Crear sucursal <i class="fas fa-plus-circle"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Tabla de sucursales (DataTables)

         La paginación, búsqueda y ordenamiento los maneja
         DataTables en el navegador, por eso el controlador
         envía la colección completa sin paginar.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="branchesTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>NIT</th>
                        <th>Nombre</th>
                        <th>Municipio</th>
                        <th>Departamento</th>
                        <th>Dirección</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($branches as $branch)
                        <tr>
                            <td>{{ $branch->nit }}</td>
                            <td>{{ $branch->name }}</td>
                            <td>{{ $branch->municipality }}</td>
                            <td>{{ $branch->department }}</td>
                            <td>{{ $branch->address }}</td>
                            <td>
                                {{-- Ver detalle de la sucursal --}}
                                <a class="btn btn-success btn-sm"
                                   href="{{ route('branches.show', $branch) }}"
                                   title="Ver más">
                                    <i class="far fa-eye"></i> Ver más
                                </a>

                                {{-- Editar sucursal --}}
                                <a class="btn btn-warning btn-sm"
                                   href="{{ route('branches.edit', $branch) }}"
                                   title="Editar">
                                    <i class="fas fa-pencil-alt"></i> Modificar
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
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
     Scripts de DataTables e inicialización de la tabla
     ============================================================ --}}
@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#branchesTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay sucursales registradas.'
                },

                // Registros visibles por página
                pageLength: 25,

                // Orden inicial: por nombre (columna 1) ascendente
                order: [[1, 'asc']],

                columnDefs: [
                    // La columna de acciones (índice 5) no es ordenable
                    { orderable: false, targets: [5] },

                    // Evita el warning de DataTables cuando una celda llega vacía
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });
    </script>
@endsection
