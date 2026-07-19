@extends('adminlte::page')
@section('title', 'ONTs Autorizadas')

@section('content_header')
    <div class="card p-3">
        <h2>ONT´S AUTORIZADAS</h2>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger">{{ session('success-delete') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="ontsTable" class="table table-hover table-bordered">
                    <thead>
                    <tr>
                        <th>OLT</th>
                        <th>Slot</th>
                        <th>Puerto</th>
                        <th>Onu Id</th>
                        <th>Service Port</th>
                        <th>Serial</th>
                        <th>Descripción</th>
                        <th>Estado</th>
                        <th>Potencia</th>
                        <th>Vlan</th>
                        <th></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($onts as $ont)
                        <tr>
                            <td>{{ $ont->olt->name ?? 'N/A' }}</td>
                            <td>{{ $ont->slot }}</td>
                            <td>{{ $ont->port }}</td>
                            <td>{{ $ont->onu_id }}</td>
                            <td>{{ $ont->service_port }}</td>
                            <td>{{ $ont->sn }}</td>
                            <td>{{ $ont->description }}</td>
                            <td>
                                @if($ont->status)
                                    <span class="badge badge-success">Activa</span>
                                @else
                                    <span class="badge badge-danger">Offline</span>
                                @endif
                            </td>
                            <td>
                                <span
                                    id="rx-power-{{ $ont->id }}"
                                    class="{{ $ont->rx_power && $ont->rx_power < -25 ? 'text-danger' : 'text-success' }}">
                                    {{ $ont->rx_power ? $ont->rx_power . ' dBm' : '—' }}
                                </span>
                                <button
                                    class="btn btn-sm btn-link p-0 ml-1 btn-sync-power"
                                    data-id="{{ $ont->id }}"
                                    title="Refrescar potencia">
                                    <i class="fas fa-sync"></i>
                                </button>
                            </td>
                            <td>{{ $ont->vlan }}</td>
                            <td>
                                <a href="{{ route('onts.show', $ont) }}" class="btn btn-info btn-sm" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                            <td>
                                <button
                                    class="btn btn-danger btn-sm btn-eliminar"
                                    data-id="{{ $ont->id }}"
                                    data-sn="{{ $ont->sn }}"
                                    data-desc="{{ $ont->description }}"
                                    title="Eliminar ONT">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Modal de confirmación --}}
            <div class="modal fade" id="modalEliminarOnt" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">Eliminar ONT</h5>
                            <button type="button" class="close text-white" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body" id="eliminarCampos">
                            <p>¿Está seguro que desea eliminar la siguiente ONT?</p>
                            <ul>
                                <li><strong>Serial:</strong> <span id="eliminarSn"></span></li>
                                <li><strong>Cliente:</strong> <span id="eliminarDesc"></span></li>
                            </ul>
                            <p class="text-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                                Esta acción eliminará la ONT de la OLT y de la base de datos.
                            </p>
                        </div>

                        {{-- ============================================================
                             Progreso de la eliminación

                             Borrar la ONT implica desconfigurarla en la OLT por
                             consola, lo que toma varios segundos. Sin este aviso
                             el modal quedaba estático y parecía bloqueado.
                             ============================================================ --}}
                        <div class="modal-body text-center py-5" id="eliminarProgreso" style="display:none;">
                            <div class="spinner-border text-danger" role="status" style="width:3.5rem;height:3.5rem;"></div>
                            <h5 class="mt-4 mb-2">Eliminando la ONT de la OLT...</h5>
                            <p class="text-muted mb-0">
                                Se está desconfigurando el equipo. Este proceso puede tardar
                                hasta un minuto.<br>
                                <strong>No cierre esta ventana ni recargue la página.</strong>
                            </p>
                        </div>

                        <div class="modal-footer" id="eliminarBotones">
                            <form id="formEliminarOnt" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                    Cancelar
                                </button>
                                <button type="submit" class="btn btn-danger">
                                    Sí, eliminar
                                </button>
                            </form>
                        </div>
                    </div>
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
            $('#ontsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
                },
                pageLength: 25,
                order: [[0, 'asc']],
                // Deshabilitar ordenamiento en columnas de acciones
                columnDefs: [
                    { orderable: false, targets: [8, 10, 11] }
                ]
            });
        });

        // Eliminar ONT
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar')) {
                const btn  = e.target.closest('.btn-eliminar');
                const id   = btn.getAttribute('data-id');
                const sn   = btn.getAttribute('data-sn');
                const desc = btn.getAttribute('data-desc');

                document.getElementById('eliminarSn').textContent  = sn;
                document.getElementById('eliminarDesc').textContent = desc;
                document.getElementById('formEliminarOnt').action  = `/onts/${id}`;

                // Restaurar el formulario por si el intento anterior falló
                document.getElementById('eliminarCampos').style.display = 'block';
                document.getElementById('eliminarBotones').style.display = 'flex';
                document.getElementById('eliminarProgreso').style.display = 'none';

                $('#modalEliminarOnt').modal('show');
            }
        });

        /* ============================================================
           PROGRESO AL ELIMINAR UNA ONT

           La eliminación desconfigura el equipo en la OLT por consola
           y tarda varios segundos: se sustituye la confirmación por un
           aviso de progreso y se impide cerrar el modal mientras tanto.
           ============================================================ */
        document.getElementById('formEliminarOnt').addEventListener('submit', function () {
            document.getElementById('eliminarCampos').style.display = 'none';
            document.getElementById('eliminarBotones').style.display = 'none';
            document.getElementById('eliminarProgreso').style.display = 'block';

            $('#modalEliminarOnt').data('bs.modal')._config.backdrop = 'static';
            $('#modalEliminarOnt').data('bs.modal')._config.keyboard = false;
        });

        // Refrescar potencia sin recargar página
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-sync-power');
            if (!btn) return;

            const id      = btn.getAttribute('data-id');
            const icon    = btn.querySelector('i');
            const display = document.getElementById('rx-power-' + id);

            icon.classList.add('fa-spin');
            btn.disabled = true;

            fetch(`/onts/${id}/sync-power`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        if (data.status) {
                            display.textContent = data.rx_power + ' dBm';
                            display.className   = data.rx_power < -25 ? 'text-danger' : 'text-success';
                        } else {
                            display.textContent = 'Offline';
                            display.className   = 'text-muted';
                        }
                    } else {
                        display.textContent = 'Error';
                        display.className   = 'text-warning';
                    }
                })
                .catch(() => {
                    display.textContent = 'Error';
                    display.className   = 'text-warning';
                })
                .finally(() => {
                    icon.classList.remove('fa-spin');
                    btn.disabled = false;
                });
        });
    </script>
@endsection
