@extends('adminlte::page')
@section('title', 'ONTs Pendientes')

@section('content_header')
    <div class="card p-3">
        <h2>ONT´s Pendientes por activación</h2>
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

    <div class="card">
        <div class="card-body">
            <div class="form-group">
                <select class="form-control" name="olt" id="olt">
                    <option value="">Seleccione una OLT</option>
                    @foreach($olts as $olt)
                        <option value="{{ $olt->id }}">{{ $olt->name }}</option>
                    @endforeach
                </select>
            </div>

            <div id="loader" class="text-center my-3" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Cargando...</span>
                </div>
            </div>

            <div class="table-responsive">
                <table id="autofindTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>SN</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Ubicación (F/S/P)</th>
                        <th>Encontrada el</th>
                        <th>Acción</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modal Activar ONT --}}
    <div class="modal fade" id="activarOntModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form id="formActivarOnt" method="POST" action="{{ route('onts.activate') }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Activar ONT</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="ont_sn" id="modalOntSn">

                        <div class="form-group">
                            <label>Ubicación</label>
                            <input type="text" class="form-control" id="modalOntLocationView" name="ont_location" readonly>
                        </div>
                        <div class="form-group">
                            <label>SN</label>
                            <input type="text" class="form-control" id="modalOntSnView" disabled>
                        </div>
                        <div class="form-group">
                            <label>Marca</label>
                            <input type="text" class="form-control" id="modalVendor" disabled>
                        </div>
                        <div class="form-group">
                            <label>Modelo</label>
                            <input type="text" class="form-control" id="modalModel" disabled>
                        </div>

                        <div class="form-group">
                            <label>Buscar Contrato</label>
                            <input
                                type="text"
                                id="buscarContrato"
                                class="form-control"
                                placeholder="Buscar por identificación, nombre o # contrato...">
                            <div id="resultadosContrato"
                                 class="list-group mt-1"
                                 style="display:none; position:absolute; z-index:9999; width:90%;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Cliente seleccionado</label>
                            <input
                                type="text"
                                id="clienteSeleccionadoView"
                                class="form-control"
                                disabled
                                placeholder="Ninguno seleccionado">
                        </div>

                        <input type="hidden" name="contract_id" id="selectedContractId">
                        <input type="hidden" name="description"  id="selectedDescription">

                        <div class="form-group">
                            <label>VLAN</label>
                            <select name="vlan" id="vlanSelect" class="form-control" required>
                                <option value="">Seleccione una VLAN</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Line Profile</label>
                            <select name="ont_lineprofile" id="lineProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Line Profile</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Srv Profile</label>
                            <select name="ont_srvprofile" id="srvProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Srv Profile</option>
                            </select>
                        </div>

                        <input type="hidden" id="selectedOltId" name="olt_id">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Activar</button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Mover ONT --}}
    <div class="modal fade" id="moverOntModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form id="formMoverOnt" method="POST" action="">
                @csrf
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">Mover ONT a nuevo puerto</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Esta acción eliminará la ONT del puerto actual y la activará en el nuevo puerto.
                        </div>
                        <div class="form-group">
                            <label>Serial</label>
                            <input type="text" id="moverSn" class="form-control" disabled>
                        </div>
                        <div class="form-group">
                            <label>Puerto actual</label>
                            <input type="text" id="moverPortActual" class="form-control" disabled>
                        </div>
                        <div class="form-group">
                            <label>Nuevo puerto</label>
                            <input type="text" name="ont_location" id="moverPortNuevo" class="form-control" readonly>
                        </div>
                        <div class="form-group">
                            <label>VLAN</label>
                            <select name="vlan" id="moverVlanSelect" class="form-control" required>
                                <option value="">Seleccione una VLAN</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Line Profile</label>
                            <select name="ont_lineprofile" id="moverLineProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Line Profile</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Srv Profile</label>
                            <select name="ont_srvprofile" id="moverSrvProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Srv Profile</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-exchange-alt"></i> Mover ONT
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    </div>
                </div>
            </form>
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
        let autofindDT = null;

        $(document).ready(function () {
            autofindDT = $('#autofindTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Seleccione una OLT para ver las ONTs pendientes.'
                },
                pageLength: 25,
                columnDefs: [
                    { orderable: false, targets: [5] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        // Cambio de OLT
        document.getElementById('olt').addEventListener('change', function () {
            const oltId = this.value;
            document.getElementById('selectedOltId').value = oltId;
            const loader = document.getElementById('loader');

            autofindDT.clear().draw();

            ['vlanSelect', 'lineProfileSelect', 'srvProfileSelect'].forEach(id => {
                document.getElementById(id).innerHTML = '<option value="">Seleccione...</option>';
            });

            if (!oltId) return;

            loader.style.display = 'block';

            // ONTs Autofind + verificación de SN
            fetch(`/olts/${oltId}/onts-autofind`)
                .then(r => r.json())
                .then(data => {
                    loader.style.display = 'none';

                    if (data.error) {
                        autofindDT.row.add([
                            `<span class="text-danger">${data.error}</span>`,
                            '—', '—', '—', '—', '—'
                        ]).draw();
                        return;
                    }

                    if (data.length === 0) {
                        autofindDT.row.add([
                            '<span class="text-muted">No hay ONTs en autofind.</span>',
                            '—', '—', '—', '—', '—'
                        ]).draw();
                        return;
                    }

                    // Verificar cada SN contra la DB
                    const checks = data.map(ont =>
                        fetch(`/api/onts/check-sn/${encodeURIComponent(ont.ont_sn)}`)
                            .then(r => r.json())
                            .then(check => ({ ont, check }))
                    );

                    Promise.all(checks).then(results => {
                        results.forEach(({ ont, check }) => {
                            let accionBtn;

                            if (check.exists) {
                                accionBtn = `
                                    <button
                                        class="btn btn-warning btn-sm mover-btn"
                                        data-ont-id="${check.ont_id}"
                                        data-location="${ont.fspon}"
                                        data-sn="${ont.ont_sn}"
                                        data-current="${check.current_location}"
                                        title="Puerto actual: ${check.current_location}">
                                        <i class="fas fa-exchange-alt"></i> Mover a este puerto
                                    </button>`;
                            } else {
                                accionBtn = `
                                    <button
                                        class="btn btn-success btn-sm activar-btn"
                                        data-location="${ont.fspon}"
                                        data-sn="${ont.ont_sn}"
                                        data-vendor="${ont.vendor}"
                                        data-model="${ont.equipment_id}">
                                        <i class="fas fa-check-square"></i> Activar
                                    </button>`;
                            }

                            autofindDT.row.add([
                                ont.ont_sn,
                                ont.vendor,
                                ont.equipment_id,
                                ont.fspon,
                                ont.autofind_time,
                                accionBtn
                            ]);
                        });

                        autofindDT.draw();
                    });
                })
                .catch(() => {
                    loader.style.display = 'none';
                    autofindDT.row.add([
                        '<span class="text-danger">Error al conectar con la OLT</span>',
                        '—', '—', '—', '—', '—'
                    ]).draw();
                });

            // VLANs
            fetch(`/api/vlansolt/${oltId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="">Seleccione una VLAN</option>';
                    data.forEach(v => {
                        html += `<option value="${v.id_vlan}">${v.id_vlan} - ${v.name}</option>`;
                    });
                    document.getElementById('vlanSelect').innerHTML = html;
                });

            // Line Profiles
            fetch(`/api/lineprofiles/${oltId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="">Seleccione un Line Profile</option>';
                    data.forEach(p => {
                        html += `<option value="${p.id_line_profile}">${p.id_line_profile} - ${p.name}</option>`;
                    });
                    document.getElementById('lineProfileSelect').innerHTML = html;
                });

            // Srv Profiles
            fetch(`/api/srvprofiles/${oltId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="">Seleccione un Srv Profile</option>';
                    data.forEach(p => {
                        html += `<option value="${p.id_srv_profile}">${p.id_srv_profile} - ${p.name}</option>`;
                    });
                    document.getElementById('srvProfileSelect').innerHTML = html;
                });
        });

        // Botón activar → modal activación
        document.addEventListener('click', function (e) {
            if (e.target.closest('.activar-btn')) {
                const btn = e.target.closest('.activar-btn');
                document.getElementById('modalOntLocationView').value   = btn.getAttribute('data-location');
                document.getElementById('modalOntSn').value             = btn.getAttribute('data-sn');
                document.getElementById('modalOntSnView').value         = btn.getAttribute('data-sn');
                document.getElementById('modalVendor').value            = btn.getAttribute('data-vendor');
                document.getElementById('modalModel').value             = btn.getAttribute('data-model');
                document.getElementById('buscarContrato').value         = '';
                document.getElementById('clienteSeleccionadoView').value = '';
                document.getElementById('selectedContractId').value     = '';
                document.getElementById('selectedDescription').value    = '';

                $('#activarOntModal').modal('show');
            }
        });

        // Botón mover → modal traslado
        document.addEventListener('click', function (e) {
            if (e.target.closest('.mover-btn')) {
                const btn = e.target.closest('.mover-btn');

                document.getElementById('moverSn').value         = btn.getAttribute('data-sn');
                document.getElementById('moverPortActual').value = btn.getAttribute('data-current');
                document.getElementById('moverPortNuevo').value  = btn.getAttribute('data-location');

                document.getElementById('moverVlanSelect').innerHTML =
                    document.getElementById('vlanSelect').innerHTML;
                document.getElementById('moverLineProfileSelect').innerHTML =
                    document.getElementById('lineProfileSelect').innerHTML;
                document.getElementById('moverSrvProfileSelect').innerHTML =
                    document.getElementById('srvProfileSelect').innerHTML;

                document.getElementById('formMoverOnt').action =
                    `/onts/${btn.getAttribute('data-ont-id')}/relocate`;

                $('#moverOntModal').modal('show');
            }
        });

        // Buscador de contratos
        let buscarTimeout = null;

        document.getElementById('buscarContrato').addEventListener('input', function () {
            const q          = this.value.trim();
            const resultados = document.getElementById('resultadosContrato');

            clearTimeout(buscarTimeout);

            if (q.length < 2) {
                resultados.style.display = 'none';
                resultados.innerHTML     = '';
                return;
            }

            buscarTimeout = setTimeout(() => {
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
                                document.getElementById('selectedContractId').value      = contrato.id;
                                document.getElementById('selectedDescription').value     = contrato.description;
                                document.getElementById('clienteSeleccionadoView').value = contrato.label;
                                document.getElementById('buscarContrato').value          = '';
                                resultados.style.display = 'none';
                                resultados.innerHTML     = '';
                            });

                            resultados.appendChild(item);
                        });

                        resultados.style.display = 'block';
                    });
            }, 300);
        });

        // Cerrar resultados al hacer click fuera
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#buscarContrato') && !e.target.closest('#resultadosContrato')) {
                document.getElementById('resultadosContrato').style.display = 'none';
            }
        });
    </script>
@endsection
