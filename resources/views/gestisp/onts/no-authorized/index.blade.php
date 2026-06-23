@extends('adminlte::page')
@section('title', 'OLTs')
@section('content_header')
    <div class="card p-3">
        <h2>ONT´s Pendientes por activación</h2>
    </div>
@endsection
@section('content')
    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">
            {{ session('success-update') }}
        </div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger">
            {{ session('success-delete') }}
        </div>
    @endif
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">

                <select class="form-control" name="olt" id="olt">
                    <option value="">Seleccione una OLT</option>
                    @foreach($olts as $olt)
                        <option value="{{$olt->id}}">{{ $olt->name }}</option>
                    @endforeach
                </select>
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th>SN</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Ubicación (F/S/P)</th>
                        <th>Econtrada el</th>
                        <th>Acción</th>
                    </tr>
                    </thead>
                    <tbody>

                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer">
        </div>
        <div id="loader" class="text-center my-3" style="display: none;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Cargando...</span>
            </div>
        </div>
    </div>
    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif
    <!-- Modal de Activación de ONT -->
    <div class="modal fade" id="activarOntModal" tabindex="-1" role="dialog" aria-labelledby="activarOntModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form id="formActivarOnt" method="POST" action="{{ route('onts.activate') }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Activar ONT</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <!-- Datos de la ONT -->
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
                        <!-- Datos adicionales -->
                        <!-- Datos adicionales -->
                        <div class="form-group">
                            <label>Buscar Contrato</label>
                            <input
                                type="text"
                                id="buscarContrato"
                                class="form-control"
                                placeholder="Buscar por identificación, nombre o # contrato...">
                            <div id="resultadosContrato" class="list-group mt-1" style="display:none; position:absolute; z-index:9999; width:90%;">
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

                        <input type="hidden" name="contract_id"  id="selectedContractId">
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
                        <!-- Agrega los campos que necesites -->
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Activar</button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>

        document.getElementById('olt').addEventListener('change', function () {

            const oltId = this.value;

            document.getElementById('selectedOltId').value = oltId;

            const tbody = document.querySelector('table tbody');
            const loader = document.getElementById('loader');

            tbody.innerHTML = '';
            loader.style.display = 'block';

            if (!oltId) {

                loader.style.display = 'none';

                document.getElementById('vlanSelect').innerHTML =
                    '<option value="">Seleccione una VLAN</option>';

                document.getElementById('lineProfileSelect').innerHTML =
                    '<option value="">Seleccione un Line Profile</option>';

                document.getElementById('srvProfileSelect').innerHTML =
                    '<option value="">Seleccione un Srv Profile</option>';

                return;
            }

            // ==========================
            // ONTs Autofind
            // ==========================

            fetch(`/olts/${oltId}/onts-autofind`)
                .then(response => response.json())
                .then(data => {

                    loader.style.display = 'none';

                    if (data.error) {
                        tbody.innerHTML =
                            `<tr><td colspan="6" class="text-danger">${data.error}</td></tr>`;
                        return;
                    }

                    if (data.length === 0) {
                        tbody.innerHTML =
                            `<tr><td colspan="6">No hay ONTs en autofind.</td></tr>`;
                        return;
                    }

                    data.forEach(ont => {

                        tbody.innerHTML += `
                    <tr>
                        <td>${ont.ont_sn}</td>
                        <td>${ont.vendor}</td>
                        <td>${ont.equipment_id}</td>
                        <td>${ont.fspon}</td>
                        <td>${ont.autofind_time}</td>
                        <td>
                            <button
                                class="btn btn-success activar-btn"
                                data-location="${ont.fspon}"
                                data-sn="${ont.ont_sn}"
                                data-vendor="${ont.vendor}"
                                data-model="${ont.equipment_id}">
                                <i class="fas fa-check-square"></i>
                            </button>
                        </td>
                    </tr>
                `;
                    });

                })
                .catch(error => {

                    loader.style.display = 'none';

                    tbody.innerHTML =
                        `<tr><td colspan="6" class="text-danger">${error}</td></tr>`;
                });

            // ==========================
            // VLANS
            // ==========================

            fetch(`/api/vlansolt/${oltId}`)
                .then(response => response.json())
                .then(data => {

                    let html =
                        '<option value="">Seleccione una VLAN</option>';

                    data.forEach(vlan => {

                        html += `
                    <option value="${vlan.id_vlan}">
                        ${vlan.id_vlan} - ${vlan.name}
                    </option>
                `;
                    });

                    document.getElementById('vlanSelect').innerHTML = html;
                });

            // ==========================
            // LINE PROFILES
            // ==========================

            fetch(`/api/lineprofiles/${oltId}`)
                .then(response => response.json())
                .then(data => {

                    let html =
                        '<option value="">Seleccione un Line Profile</option>';

                    data.forEach(profile => {

                        html += `
                    <option value="${profile.id_line_profile}">
                       ${profile.id_line_profile} - ${profile.name}
                    </option>
                `;
                    });

                    document.getElementById('lineProfileSelect').innerHTML = html;
                });

            // ==========================
            // SRV PROFILES
            // ==========================

            fetch(`/api/srvprofiles/${oltId}`)
                .then(response => response.json())
                .then(data => {

                    let html =
                        '<option value="">Seleccione un Srv Profile</option>';

                    data.forEach(profile => {

                        html += `
                    <option value="${profile.id_srv_profile}">
                        ${profile.id_srv_profile} - ${profile.name}
                    </option>
                `;
                    });

                    document.getElementById('srvProfileSelect').innerHTML = html;
                });

        });
    </script>

    <script>
        // Cuando se hace clic en el botón "Activar"
        document.addEventListener('click', function (e) {
            if (e.target.closest('.activar-btn')) {
                const btn = e.target.closest('.activar-btn');
                const location = btn.getAttribute('data-location');
                const sn = btn.getAttribute('data-sn');
                const vendor = btn.getAttribute('data-vendor');
                const model = btn.getAttribute('data-model');

                // Rellenar campos del modal
                document.getElementById('modalOntLocationView').value = location;
                document.getElementById('modalOntSn').value = sn;
                document.getElementById('modalOntSnView').value = sn;
                document.getElementById('modalVendor').value = vendor;
                document.getElementById('modalModel').value = model;

                // Abrir modal con Bootstrap 4
                $('#activarOntModal').modal('show');
            }
        });
    </script>
    <script>
        let buscarTimeout = null;

        document.getElementById('buscarContrato').addEventListener('input', function () {
            const q           = this.value.trim();
            const resultados  = document.getElementById('resultadosContrato');

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
                            const item = document.createElement('button');
                            item.type      = 'button';
                            item.className = 'list-group-item list-group-item-action';
                            item.textContent = contrato.label;

                            item.addEventListener('click', function () {
                                document.getElementById('selectedContractId').value    = contrato.id;
                                document.getElementById('selectedDescription').value   = contrato.description;
                                document.getElementById('clienteSeleccionadoView').value = contrato.label;
                                document.getElementById('buscarContrato').value        = '';
                                resultados.style.display = 'none';
                                resultados.innerHTML     = '';
                            });

                            resultados.appendChild(item);
                        });

                        resultados.style.display = 'block';
                    });
            }, 300); // espera 300ms después de que el usuario deja de escribir
        });

        // Cerrar resultados al hacer click fuera
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#buscarContrato') && !e.target.closest('#resultadosContrato')) {
                document.getElementById('resultadosContrato').style.display = 'none';
            }
        });
    </script>




@endsection
