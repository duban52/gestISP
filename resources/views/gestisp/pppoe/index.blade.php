@extends('adminlte::page')
@section('title', 'Cuentas PPPoE')

@section('content_header')
    <div class="card p-3">
        <h2>CUENTAS PPPOE</h2>
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

    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <div>
            {{-- Importar cuentas existentes de un router --}}
            <form method="POST" id="formImportar" class="form-inline d-inline-flex" action="">
                @csrf
                <select id="importRouterSelect" class="form-control form-control-sm mr-2">
                    <option value="">Importar desde router...</option>
                    @foreach($routers as $router)
                        <option value="{{ $router->id }}">{{ $router->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-outline-primary" disabled id="btnImportar">
                    <i class="fas fa-download"></i> Importar
                </button>
            </form>
        </div>
        <button class="btn btn-primary" id="btnNuevaCuenta">
            <i class="fas fa-plus"></i> Nueva Cuenta PPPoE
        </button>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <div class="table-responsive">
                <table id="pppoeTable" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Router</th>
                        <th>Perfil</th>
                        <th>IP Remota</th>
                        <th>Cliente</th>
                        <th>Estado</th>
                        <th>Comentario</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($accounts as $account)
                        <tr>
                            <td>{{ $account->username }}</td>
                            <td>{{ $account->router->name ?? 'N/A' }}</td>
                            <td>{{ $account->profile }}</td>
                            <td>{{ $account->remote_address ?? '—' }}</td>
                            <td>
                                @if($account->contract_id)
                                    {{ $account->contract->client->name ?? '—' }}
                                    {{ $account->contract->client->last_name ?? '' }}
                                @else
                                    {{-- Las cuentas importadas del router llegan
                                         sin cliente: se vinculan desde su ficha --}}
                                    <a href="{{ route('pppoe.show', $account) }}"
                                       class="badge badge-warning"
                                       title="Vincular esta cuenta con un contrato">
                                        <i class="fas fa-unlink mr-1"></i> Sin contrato
                                    </a>
                                @endif
                            </td>
                            <td>
                                @if($account->disabled)
                                    <span class="badge badge-danger">Suspendida</span>
                                @else
                                    <span class="badge badge-success">Activa</span>
                                @endif
                            </td>
                            <td>{{ $account->comment ?? '—' }}</td>
                            <td>
                                <a href="{{ route('pppoe.show', $account) }}" class="btn btn-sm btn-info" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button
                                    class="btn btn-sm btn-primary btn-editar-pppoe"
                                    data-id="{{ $account->id }}"
                                    data-username="{{ $account->username }}"
                                    data-profile="{{ $account->profile }}"
                                    data-remote="{{ $account->remote_address }}"
                                    data-comment="{{ $account->comment }}"
                                    data-router="{{ $account->router_id }}"
                                    title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <form method="POST" action="{{ route('pppoe.toggle', $account) }}" class="d-inline">
                                    @csrf
                                    @if($account->disabled)
                                        <button type="submit" class="btn btn-sm btn-success" title="Reactivar">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    @else
                                        <button type="submit" class="btn btn-sm btn-warning" title="Suspender">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    @endif
                                </form>

                                <button
                                    class="btn btn-sm btn-danger btn-eliminar-pppoe"
                                    data-id="{{ $account->id }}"
                                    data-username="{{ $account->username }}"
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

    {{-- Modal Crear Cuenta --}}
    <div class="modal fade" id="modalCrearPppoe" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form method="POST" action="{{ route('pppoe.store') }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Nueva Cuenta PPPoE</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Buscar Contrato <span class="text-danger">*</span></label>
                            <input type="text" id="buscarContratoPppoe" class="form-control"
                                   placeholder="Identificación, nombre o # contrato...">
                            <div id="resultadosContratoPppoe" class="list-group mt-1"
                                 style="display:none; position:absolute; z-index:9999; width:90%;"></div>
                        </div>
                        <div class="form-group">
                            <label>Cliente seleccionado</label>
                            <input type="text" id="clientePppoeView" class="form-control" disabled
                                   placeholder="Ninguno seleccionado">
                        </div>
                        <input type="hidden" name="contract_id" id="pppoeContractId">

                        <div class="form-group">
                            <label>Router <span class="text-danger">*</span></label>
                            <select name="router_id" id="crearRouterSelect" class="form-control" required>
                                <option value="">Seleccione un router</option>
                                @foreach($routers as $router)
                                    <option value="{{ $router->id }}">{{ $router->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Perfil (Plan) <span class="text-danger">*</span></label>
                            <select name="profile" id="crearProfileSelect" class="form-control" required>
                                <option value="">Seleccione primero un router</option>
                            </select>
                        </div>

                        {{-- Credenciales autogeneradas (editables) --}}
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Usuario <span class="text-danger">*</span></label>
                                    <input type="text" name="username" id="pppoeUsername" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Contraseña <span class="text-danger">*</span></label>
                                    <input type="text" name="password" id="pppoePassword" class="form-control" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Comentario</label>
                            <input type="text" name="comment" id="pppoeComment" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>IP Remota (opcional)</label>
                            <input type="text" name="remote_address" class="form-control"
                                   placeholder="Dejar vacío para IP dinámica del pool">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Crear Cuenta
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Editar Cuenta --}}
    <div class="modal fade" id="modalEditarPppoe" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form method="POST" id="formEditarPppoe" action="">
                @csrf
                @method('PUT')
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Editar Cuenta PPPoE</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Usuario <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="editarUsername" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Contraseña</label>
                            <input type="text" name="password" class="form-control"
                                   placeholder="Dejar vacío para conservar la actual">
                        </div>
                        <div class="form-group">
                            <label>Perfil (Plan) <span class="text-danger">*</span></label>
                            <select name="profile" id="editarProfileSelect" class="form-control" required>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>IP Remota</label>
                            <input type="text" name="remote_address" id="editarRemote" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Comentario</label>
                            <input type="text" name="comment" id="editarComment" class="form-control">
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle"></i>
                            Al guardar, la sesión activa del usuario se reiniciará para aplicar los cambios.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Actualizar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Eliminar Cuenta --}}
    <div class="modal fade" id="modalEliminarPppoe" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Cuenta PPPoE</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar la cuenta <strong id="eliminarPppoeUsername"></strong>?</p>
                    <p class="text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Se eliminará del router y de la base de datos. La sesión del cliente se desconectará.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarPppoe" method="POST">
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
            $('#pppoeTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay cuentas PPPoE registradas.'
                },
                pageLength: 25,
                columnDefs: [
                    { orderable: false, targets: [7] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        // ============ Importar desde router ============
        document.getElementById('importRouterSelect').addEventListener('change', function () {
            const btn = document.getElementById('btnImportar');
            btn.disabled = !this.value;
            document.getElementById('formImportar').action = this.value
                ? `/pppoe/import/${this.value}`
                : '';
        });

        // ============ Cargar perfiles al elegir router ============
        document.getElementById('crearRouterSelect').addEventListener('change', function () {
            loadProfiles(this.value, 'crearProfileSelect');
        });

        function loadProfiles(routerId, targetSelectId, selectedProfile = null) {
            const select = document.getElementById(targetSelectId);
            select.innerHTML = '<option value="">Cargando perfiles...</option>';

            if (!routerId) {
                select.innerHTML = '<option value="">Seleccione primero un router</option>';
                return;
            }

            fetch(`/api/routers/${routerId}/profiles`)
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        select.innerHTML = `<option value="">${data.error}</option>`;
                        return;
                    }

                    let html = '<option value="">Seleccione un perfil</option>';
                    data.forEach(p => {
                        const rateInfo = p.rate_limit ? ` (${p.rate_limit})` : '';
                        const selected = (selectedProfile && p.name === selectedProfile) ? 'selected' : '';
                        html += `<option value="${p.name}" ${selected}>${p.name}${rateInfo}</option>`;
                    });
                    select.innerHTML = html;
                })
                .catch(() => {
                    select.innerHTML = '<option value="">Error al cargar perfiles</option>';
                });
        }

        // ============ Generación automática de credenciales ============

        /**
         * Normaliza texto: minúsculas, sin tildes, sin caracteres raros
         */
        function normalizar(texto) {
            return (texto || '')
                .toLowerCase()
                .normalize('NFD')                    // separa tildes de las letras
                .replace(/[\u0300-\u036f]/g, '')     // elimina las tildes
                .replace(/ñ/g, 'n')
                .replace(/[^a-z0-9\s]/g, '')         // solo letras, números y espacios
                .trim();
        }

        function generarCredenciales(contrato) {
            // Primer nombre y primer apellido (por si son compuestos)
            const primerNombre   = normalizar(contrato.client_name).split(/\s+/)[0]     || '';
            const primerApellido = normalizar(contrato.client_lastname).split(/\s+/)[0] || '';

            // Primeros 5 dígitos de la identidad (solo números)
            const identidad    = (contrato.identity_number || '').replace(/\D/g, '');
            const cincoDigitos = identidad.substring(0, 5);

            // usuario: primernombre_primerapellido_numerodecontrato
            document.getElementById('pppoeUsername').value =
                `${primerNombre}_${primerApellido}_${contrato.id}`;

            // contraseña: primerapellido_primeroscincodigitosdeidentidad
            document.getElementById('pppoePassword').value =
                `${primerApellido}_${cincoDigitos}`;

            // comentario: numero de contrato, número de identidad nombre completo
            document.getElementById('pppoeComment').value =
                `Contrato ${contrato.id}, CC ${contrato.identity_number} ${contrato.client_name} ${contrato.client_lastname}`;
        }

        // ============ Abrir modal crear ============
        document.getElementById('btnNuevaCuenta').addEventListener('click', function () {
            document.getElementById('buscarContratoPppoe').value = '';
            document.getElementById('clientePppoeView').value    = '';
            document.getElementById('pppoeContractId').value     = '';
            document.getElementById('pppoeUsername').value       = '';
            document.getElementById('pppoePassword').value       = '';
            document.getElementById('pppoeComment').value        = '';
            $('#modalCrearPppoe').modal('show');
        });

        // ============ Abrir modal editar ============
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-editar-pppoe')) {
                const btn = e.target.closest('.btn-editar-pppoe');

                document.getElementById('editarUsername').value = btn.getAttribute('data-username');
                document.getElementById('editarRemote').value   = btn.getAttribute('data-remote') || '';
                document.getElementById('editarComment').value  = btn.getAttribute('data-comment') || '';

                document.getElementById('formEditarPppoe').action =
                    `/pppoe/${btn.getAttribute('data-id')}`;

                loadProfiles(
                    btn.getAttribute('data-router'),
                    'editarProfileSelect',
                    btn.getAttribute('data-profile')
                );

                $('#modalEditarPppoe').modal('show');
            }
        });

        // ============ Abrir modal eliminar ============
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-pppoe')) {
                const btn = e.target.closest('.btn-eliminar-pppoe');

                document.getElementById('eliminarPppoeUsername').textContent =
                    btn.getAttribute('data-username');
                document.getElementById('formEliminarPppoe').action =
                    `/pppoe/${btn.getAttribute('data-id')}`;

                $('#modalEliminarPppoe').modal('show');
            }
        });

        // ============ Buscador de contratos ============
        let buscarTimeoutPppoe = null;

        document.getElementById('buscarContratoPppoe').addEventListener('input', function () {
            const q          = this.value.trim();
            const resultados = document.getElementById('resultadosContratoPppoe');

            clearTimeout(buscarTimeoutPppoe);

            if (q.length < 2) {
                resultados.style.display = 'none';
                resultados.innerHTML     = '';
                return;
            }

            buscarTimeoutPppoe = setTimeout(() => {
                fetch(`/api/contratos/buscar?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        resultados.innerHTML = '';

                        if (data.length === 0) {
                            resultados.innerHTML =
                                '<div class="list-group-item text-muted">Sin resultados</div>';
                            resultados.style.display = 'block';
                            return;
                        }

                        data.forEach(contrato => {
                            const item       = document.createElement('button');
                            item.type        = 'button';
                            item.className   = 'list-group-item list-group-item-action';
                            item.textContent = contrato.label;

                            item.addEventListener('click', function () {
                                document.getElementById('pppoeContractId').value  = contrato.id;
                                document.getElementById('clientePppoeView').value = contrato.label;
                                document.getElementById('buscarContratoPppoe').value = '';
                                resultados.style.display = 'none';
                                resultados.innerHTML     = '';

                                // Autogenerar usuario, contraseña y comentario
                                generarCredenciales(contrato);
                            });

                            resultados.appendChild(item);
                        });

                        resultados.style.display = 'block';
                    });
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('#buscarContratoPppoe') && !e.target.closest('#resultadosContratoPppoe')) {
                document.getElementById('resultadosContratoPppoe').style.display = 'none';
            }
        });
    </script>
@endsection
