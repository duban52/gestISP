@extends('adminlte::page')

@section('title', 'Usuarios')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR USUARIOS</h2>
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
         Acciones: creación de usuarios
         ============================================================ --}}
    <div class="card p-3">
        <div class="d-flex justify-content-end">
            <a class="btn btn-primary" href="{{ route('users.create') }}">
                <i class="fas fa-plus-circle"></i> Crear usuario
            </a>
        </div>
    </div>

    {{-- ============================================================
         Tabla de usuarios (DataTables)

         Las sucursales vienen precargadas con with() desde el
         controlador (la pivote incluye role_id) y los nombres de
         los roles llegan indexados por id en $roleNames, evitando
         consultas N+1. Búsqueda, orden y paginación corren en el
         navegador.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="usersTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Identificación</th>
                        <th>Nombre</th>
                        <th>Apellido</th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th>Sucursales / Roles</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($users as $user)
                        <tr>
                            <td>{{ $user->identity_number }}</td>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->last_name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->number_phone }}</td>

                            {{-- Asignaciones sucursal → rol (badges) --}}
                            <td>
                                @forelse($user->branches as $branch)
                                    <span class="badge badge-info">
                                        {{ $branch->name }}: {{ $roleNames[$branch->pivot->role_id] ?? 'sin rol' }}
                                    </span>
                                @empty
                                    <span class="badge badge-secondary">Sin sucursal</span>
                                @endforelse
                            </td>

                            {{-- Estado de acceso --}}
                            <td data-order="{{ $user->is_active ? 1 : 0 }}">
                                @if($user->is_active)
                                    <span class="badge badge-success">Activo</span>
                                @else
                                    <span class="badge badge-danger">Inhabilitado</span>
                                @endif
                            </td>

                            <td>
                                {{-- Trazabilidad: sesiones e historial del usuario --}}
                                @can('users.trace')
                                    <a href="{{ route('users.show', $user) }}"
                                       class="btn btn-info btn-sm"
                                       title="Trazabilidad y sesiones">
                                        <i class="fas fa-fingerprint"></i>
                                    </a>
                                @endcan

                                {{-- Editar usuario --}}
                                <a href="{{ route('users.edit', $user) }}"
                                   class="btn btn-warning btn-sm"
                                   title="Editar">
                                    <i class="fas fa-pencil-alt"></i> Editar
                                </a>

                                {{-- Habilitar / inhabilitar el acceso.
                                     No se muestra para el propio usuario: no
                                     debe poder dejarse a sí mismo sin acceso. --}}
                                @can('users.disable')
                                    @if($user->id !== auth()->id())
                                        <form method="POST" action="{{ route('users.toggle-active', $user) }}" class="d-inline"
                                              onsubmit="return confirm('{{ $user->is_active
                                                    ? '¿Inhabilitar a ' . $user->name . '? No podrá iniciar sesión y se cerrarán sus sesiones activas.'
                                                    : '¿Habilitar a ' . $user->name . '? Podrá volver a iniciar sesión.' }}');">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                                    title="{{ $user->is_active ? 'Inhabilitar acceso' : 'Habilitar acceso' }}">
                                                <i class="fas {{ $user->is_active ? 'fa-user-slash' : 'fa-user-check' }}"></i>
                                            </button>
                                        </form>
                                    @endif
                                @endcan

                                {{-- Eliminar usuario (abre modal de confirmación).
                                     No se muestra para el propio usuario logueado. --}}
                                @if($user->id !== auth()->id())
                                    <button
                                        class="btn btn-danger btn-sm btn-eliminar-usuario"
                                        data-url="{{ route('users.destroy', $user) }}"
                                        data-nombre="{{ $user->name }} {{ $user->last_name }}"
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
         el nombre del usuario y arma la URL del formulario
         dinámicamente.
         ============================================================ --}}
    <div class="modal fade" id="modalEliminarUsuario" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Usuario</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar al usuario
                        <strong id="eliminarUsuarioNombre"></strong>?</p>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Si el usuario tiene registros asociados (pagos, órdenes,
                        movimientos, etc.) la eliminación será bloqueada.
                    </div>

                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarUsuario" method="POST">
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
            $('#usersTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay usuarios registrados.'
                },

                // Registros visibles por página
                pageLength: 25,

                // Orden inicial: por nombre (columna 1) ascendente
                order: [[1, 'asc']],

                columnDefs: [
                    // Las columnas de sucursales (5) y acciones (7) no son
                    // ordenables; la de estado (6) sí, por su data-order
                    { orderable: false, targets: [5, 7] },

                    // Evita el warning de DataTables cuando una celda llega vacía
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        /**
         * Abre el modal de confirmación de eliminación cargando el
         * nombre del usuario y la URL del formulario.
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-usuario')) {
                const btn = e.target.closest('.btn-eliminar-usuario');

                document.getElementById('eliminarUsuarioNombre').textContent =
                    btn.getAttribute('data-nombre');

                // URL generada con route() en el botón (las rutas
                // llevan el prefijo gestisp/)
                document.getElementById('formEliminarUsuario').action =
                    btn.getAttribute('data-url');

                $('#modalEliminarUsuario').modal('show');
            }
        });
    </script>
@endsection
