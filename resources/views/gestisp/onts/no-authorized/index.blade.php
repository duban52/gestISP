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

    <!-- Modal de Activación de ONT -->
    <div class="modal fade" id="activarOntModal" tabindex="-1" role="dialog" aria-labelledby="activarOntModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <form id="formActivarOnt" method="POST" action="">
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
                        <div class="form-group">
                            <label>Nombre del Cliente</label>
                            <input type="text" name="client_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Plan</label>
                            <input type="text" name="service_plan" class="form-control" required>
                        </div>
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
            const tbody = document.querySelector('table tbody');
            const loader = document.getElementById('loader');

            tbody.innerHTML = '';
            loader.style.display = 'block'; // Mostrar el loader

            if (!oltId) {
                loader.style.display = 'none'; // Ocultar si no hay selección
                return;
            }

            fetch(`/public/olts/${oltId}/onts-autofind`)
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none'; // Ocultar loader al terminar

                    if (data.error) {
                        tbody.innerHTML = `<tr><td colspan="6" class="text-danger">${data.error}</td></tr>`;
                        return;
                    }

                    if (data.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="6">No hay ONTs en autofind.</td></tr>`;
                        return;
                    }

                    data.forEach(ont => {
                        const row = `<tr>
                    <td>${ont.ont_sn}</td>
                    <td>${ont.vendor}</td>
                    <td>${ont.equipment_id}</td>
                    <td>${ont.fspon}</td>
                    <td>${ont.autofind_time}</td>
                    <td>
                        <button
                            class="btn btn-success activar-btn"
                            data-sn="${ont.ont_sn}"
                            data-vendor="${ont.vendor}"
                            data-model="${ont.equipment_id}"
                            title="Activar ONT">
                            <i class="fas fa-check-square"></i>
                        </button>
                    </td>
                </tr>`;
                        tbody.innerHTML += row;
                    });
                })
                .catch(error => {
                    loader.style.display = 'none'; // Ocultar en error
                    tbody.innerHTML = `<tr><td colspan="6" class="text-danger">Error al cargar ONTs: ${error}</td></tr>`;
                });
        });
    </script>


@endsection
