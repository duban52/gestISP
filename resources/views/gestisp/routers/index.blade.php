@extends('adminlte::page')
@section('title', 'Routers')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR ROUTERS</h2>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger">{{ session('success-delete') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card p-3 text-right">
        <a href="{{ route('routers.create') }}">
            <button class="btn btn-primary">Agregar Router</button>
        </a>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <div class="table-responsive">
                <table id="routersTable" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Dirección IP</th>
                        <th>Estado</th>
                        <th>Modelo</th>
                        <th>Versión</th>
                        <th>CPU</th>
                        <th>Uptime</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modal eliminar router --}}
    <div class="modal fade" id="modalEliminarRouter" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Router</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el router <strong id="eliminarRouterNombre"></strong>?</p>
                    <p class="text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarRouter" method="POST">
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

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            const dt = $('#routersTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Cargando routers...'
                },
                pageLength: 25,
                columnDefs: [
                    { orderable: false, targets: [7] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });

            fetch("{{ route('api.routers') }}")
                .then(r => r.json())
                .then(data => {
                    dt.clear();

                    data.forEach(router => {
                        const statusBadge = router.status
                            ? '<span class="badge badge-success">Conectado</span>'
                            : '<span class="badge badge-danger">Desconectado</span>';

                        const cpuLoad = router.cpu_load !== null
                            ? `<i class="fas fa-microchip"></i> ${router.cpu_load}%`
                            : '—';

                        dt.row.add([
                            router.name,
                            router.ip_address,
                            statusBadge,
                            router.board_name ?? '—',
                            router.version ?? '—',
                            cpuLoad,
                            router.uptime ?? '—',
                            `<a href="routers/${router.id}/edit" class="btn btn-sm btn-primary" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button class="btn btn-sm btn-danger btn-eliminar-router"
                                data-id="${router.id}"
                                data-nombre="${router.name}"
                                title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>`
                        ]);
                    });

                    dt.draw();

                    // Cambiar mensaje si no hay routers
                    if (data.length === 0) {
                        $('#routersTable').DataTable().settings()[0].oLanguage.sEmptyTable =
                            'No hay routers registrados.';
                        dt.draw();
                    }
                })
                .catch(() => {
                    dt.clear();
                    dt.row.add([
                        '<span class="text-danger">Error al cargar los routers</span>',
                        '—', '—', '—', '—', '—', '—', '—'
                    ]).draw();
                });
        });

        // Modal eliminar
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-router')) {
                const btn = e.target.closest('.btn-eliminar-router');

                document.getElementById('eliminarRouterNombre').textContent =
                    btn.getAttribute('data-nombre');
                document.getElementById('formEliminarRouter').action =
                    `/routers/${btn.getAttribute('data-id')}`;

                $('#modalEliminarRouter').modal('show');
            }
        });
    </script>
@endsection
