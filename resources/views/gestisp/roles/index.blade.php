@extends('adminlte::page')

@section('title', 'Roles')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR ROLES</h2>
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
         Acciones: creación de roles
         ============================================================ --}}
    <div class="card p-3">
        <div class="d-flex justify-content-end">
            <a class="btn btn-primary" href="{{ route('roles.create') }}">
                <i class="fas fa-plus-circle"></i> Crear rol
            </a>
        </div>
    </div>

    {{-- ============================================================
         Tabla de roles (DataTables)

         El conteo de permisos viene con withCount() y el de
         usuarios asignados llega en $usersPerRole (consulta
         agrupada sobre la pivote user_branch), evitando consultas
         N+1. Búsqueda, orden y paginación corren en el navegador.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="rolesTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Rol</th>
                        <th>Permisos</th>
                        <th>Usuarios asignados</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($roles as $rol)
                        <tr>
                            <td>{{ $rol->name }}</td>
                            <td>
                                <span class="badge badge-info">{{ $rol->permissions_count }}</span>
                            </td>
                            <td>
                                <span class="badge badge-secondary">{{ $usersPerRole[$rol->id] ?? 0 }}</span>
                            </td>
                            <td>
                                {{-- Editar rol --}}
                                <a href="{{ route('roles.edit', $rol) }}"
                                   class="btn btn-warning btn-sm"
                                   title="Editar">
                                    <i class="fas fa-pencil-alt"></i> Editar
                                </a>

                                {{-- Eliminar rol (abre modal de confirmación).
                                     El superadministrador no puede eliminarse. --}}
                                @if($rol->name !== 'superadministrador')
                                    <button
                                        class="btn btn-danger btn-sm btn-eliminar-rol"
                                        data-url="{{ route('roles.destroy', $rol) }}"
                                        data-nombre="{{ $rol->name }}"
                                        data-usuarios="{{ $usersPerRole[$rol->id] ?? 0 }}"
                                        title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                @endif
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
         el nombre, el conteo de usuarios asignados y arma la URL
         del formulario dinámicamente.
         ============================================================ --}}
    <div class="modal fade" id="modalEliminarRol" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Rol</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el rol
                        <strong id="eliminarRolNombre"></strong>?</p>

                    {{-- Advertencia contextual cuando el rol tiene usuarios --}}
                    <div id="rolUsuariosAlert" class="alert alert-warning" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Este rol tiene <strong><span id="eliminarRolUsuarios"></span></strong>
                        usuario(s) asignado(s). La eliminación será bloqueada.
                    </div>

                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarRol" method="POST">
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
            $('#rolesTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay roles registrados.'
                },

                // Registros visibles por página
                pageLength: 25,

                // Orden inicial: por nombre del rol (columna 0) ascendente
                order: [[0, 'asc']],

                columnDefs: [
                    // La columna de acciones (índice 3) no es ordenable
                    { orderable: false, targets: [3] },

                    // Evita el warning de DataTables cuando una celda llega vacía
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        /**
         * Abre el modal de confirmación de eliminación.
         * Carga el nombre y el conteo de usuarios asignados,
         * mostrando la advertencia solo cuando el rol está en uso.
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-rol')) {
                const btn      = e.target.closest('.btn-eliminar-rol');
                const usuarios = parseInt(btn.getAttribute('data-usuarios'), 10);

                document.getElementById('eliminarRolNombre').textContent =
                    btn.getAttribute('data-nombre');

                // URL generada con route() en el botón (las rutas
                // llevan el prefijo gestisp/)
                document.getElementById('formEliminarRol').action =
                    btn.getAttribute('data-url');

                // Mostrar la advertencia solo si el rol tiene usuarios
                const alertBox = document.getElementById('rolUsuariosAlert');
                if (usuarios > 0) {
                    document.getElementById('eliminarRolUsuarios').textContent = usuarios;
                    alertBox.style.display = 'block';
                } else {
                    alertBox.style.display = 'none';
                }

                $('#modalEliminarRol').modal('show');
            }
        });
    </script>
@endsection
