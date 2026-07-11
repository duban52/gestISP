@extends('adminlte::page')
@section('title', 'Detalle ONT')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <h2>Detalle de ONT — {{ $ont->sn }}</h2>
        <a href="{{ route('onts.authorized') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Alerta de error en tiempo real (oculta por defecto) --}}
    <div id="realtimeError" class="alert alert-warning" style="display:none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="realtimeErrorMsg"></span>
    </div>

    <div class="row">
        {{-- Columna izquierda: datos de la DB (carga instantánea) --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-info-circle"></i> Información General
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Serial</th>
                            <td>{{ $ont->sn }}</td>
                        </tr>
                        <tr>
                            <th>OLT</th>
                            <td>{{ $ont->olt->name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Ubicación (F/S/P)</th>
                            <td>0/{{ $ont->slot }}/{{ $ont->port }}</td>
                        </tr>
                        <tr>
                            <th>ONT ID</th>
                            <td>{{ $ont->onu_id }}</td>
                        </tr>
                        <tr>
                            <th>Service Port</th>
                            <td>{{ $ont->service_port }}</td>
                        </tr>
                        <tr>
                            <th>VLAN</th>
                            <td>{{ $ont->vlan }}</td>
                        </tr>
                        <tr>
                            <th>Descripción</th>
                            <td>{{ $ont->description }}</td>
                        </tr>
                        <tr>
                            <th>Estado en sistema</th>
                            <td>
                                @if($ont->status)
                                    <span class="badge badge-success">Activa</span>
                                @else
                                    <span class="badge badge-danger">Offline</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-user"></i> Cliente y Contrato
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Cliente</th>
                            <td>
                                {{ $ont->contract->client->name ?? 'N/A' }}
                                {{ $ont->contract->client->last_name ?? '' }}
                            </td>
                        </tr>
                        <tr>
                            <th>Identificación</th>
                            <td>{{ $ont->contract->client->identity_number ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Teléfono</th>
                            <td>{{ $ont->contract->client->number_phone ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Contrato #</th>
                            <td>{{ $ont->contract_id ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Dirección</th>
                            <td>{{ $ont->contract->address ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Barrio</th>
                            <td>{{ $ont->contract->neighborhood ?? 'N/A' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Columna derecha: datos en tiempo real (AJAX) --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-broadcast-tower"></i> Estado en Tiempo Real</span>
                    <button id="btnRefreshRealtime" class="btn btn-sm btn-light" title="Refrescar">
                        <i class="fas fa-sync"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    {{-- Loader --}}
                    <div id="realtimeLoader" class="text-center p-4">
                        <div class="spinner-border text-success" role="status"></div>
                        <p class="mt-2 text-muted">Consultando la OLT...</p>
                    </div>

                    {{-- Tabla (oculta hasta que lleguen los datos) --}}
                    <table id="realtimeTable" class="table table-striped mb-0" style="display:none;">
                        <tr>
                            <th style="width:40%">Estado operativo</th>
                            <td id="rt-run-state">—</td>
                        </tr>
                        <tr>
                            <th>Potencia Rx (ONT)</th>
                            <td id="rt-rx-power">—</td>
                        </tr>
                        <tr>
                            <th>Potencia Tx (ONT)</th>
                            <td id="rt-tx-power">—</td>
                        </tr>
                        <tr>
                            <th>Potencia Rx en OLT</th>
                            <td id="rt-olt-rx-power">—</td>
                        </tr>
                        <tr>
                            <th>Temperatura</th>
                            <td id="rt-temperature">—</td>
                        </tr>
                        <tr>
                            <th>Voltaje</th>
                            <td id="rt-voltage">—</td>
                        </tr>
                        <tr>
                            <th>Corriente</th>
                            <td id="rt-current">—</td>
                        </tr>
                        <tr>
                            <th>Distancia</th>
                            <td id="rt-distance">—</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- CATV (oculta hasta confirmar que la ONT tiene módulo) --}}
            <div class="card" id="catvCard" style="display:none;">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-tv"></i> CATV (Televisión)
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Estado del puerto</th>
                            <td id="rt-catv-state">—</td>
                        </tr>
                        <tr>
                            <th>Potencia Rx CATV</th>
                            <td id="rt-catv-power">—</td>
                        </tr>
                        <tr>
                            <th>Acciones</th>
                            <td id="rt-catv-actions">—</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Historial (oculta hasta que lleguen los datos) --}}
            <div class="card" id="historyCard" style="display:none;">
                <div class="card-header bg-secondary text-white">
                    <i class="fas fa-history"></i> Historial de Conexión
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Última conexión</th>
                            <td id="rt-last-up">—</td>
                        </tr>
                        <tr>
                            <th>Última desconexión</th>
                            <td id="rt-last-down">—</td>
                        </tr>
                        <tr>
                            <th>Causa última caída</th>
                            <td id="rt-down-cause">—</td>
                        </tr>
                        <tr>
                            <th>Tiempo en línea</th>
                            <td id="rt-online-duration">—</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js')
    <script>
        const ontId          = {{ $ont->id }};
        const realtimeUrl    = `/onts/${ontId}/realtime`;
        const catvEnableUrl  = `/onts/${ontId}/catv/enable`;
        const catvDisableUrl = `/onts/${ontId}/catv/disable`;
        const csrfToken      = document.querySelector('meta[name="csrf-token"]').content;

        function setText(id, value, suffix = '') {
            document.getElementById(id).innerHTML =
                (value !== null && value !== undefined && value !== '') ? value + suffix : '—';
        }

        function loadRealtime() {
            const loader = document.getElementById('realtimeLoader');
            const table  = document.getElementById('realtimeTable');
            const errBox = document.getElementById('realtimeError');

            loader.style.display = 'block';
            table.style.display  = 'none';
            errBox.style.display = 'none';
            document.getElementById('catvCard').style.display    = 'none';
            document.getElementById('historyCard').style.display = 'none';

            fetch(realtimeUrl)
                .then(r => r.json())
                .then(res => {
                    loader.style.display = 'none';

                    if (!res.ok) {
                        document.getElementById('realtimeErrorMsg').textContent = res.message;
                        errBox.style.display = 'block';
                        return;
                    }

                    const d = res.data;
                    table.style.display = 'table';

                    // Estado operativo
                    if ((d.run_state || '').toLowerCase() === 'online') {
                        setText('rt-run-state', '<span class="badge badge-success">Online</span>');
                    } else {
                        setText('rt-run-state', `<span class="badge badge-danger">${d.run_state ?? 'Desconocido'}</span>`);
                    }

                    // Potencia Rx con color según umbral
                    if (d.rx_power !== null) {
                        const cls  = d.rx_power < -25 ? 'text-danger font-weight-bold' : 'text-success';
                        const warn = d.rx_power < -25 ? ' <i class="fas fa-exclamation-triangle text-danger"></i>' : '';
                        setText('rt-rx-power', `<span class="${cls}">${d.rx_power} dBm</span>${warn}`);
                    } else {
                        setText('rt-rx-power', null);
                    }

                    setText('rt-tx-power',     d.tx_power,     ' dBm');
                    setText('rt-olt-rx-power', d.olt_rx_power, ' dBm');

                    // Temperatura con alerta
                    if (d.temperature !== null) {
                        const cls = d.temperature > 50 ? 'text-danger' : '';
                        setText('rt-temperature', `<span class="${cls}">${d.temperature} °C</span>`);
                    } else {
                        setText('rt-temperature', null);
                    }

                    setText('rt-voltage',  d.voltage,  ' V');
                    setText('rt-current',  d.current,  ' mA');
                    setText('rt-distance', d.distance, ' m');

                    // Historial
                    document.getElementById('historyCard').style.display = 'block';
                    setText('rt-last-up',   d.last_up_time);
                    setText('rt-last-down', d.last_down_time);
                    setText('rt-online-duration', d.online_duration);

                    if (d.last_down_cause === 'dying-gasp') {
                        setText('rt-down-cause', '<span class="badge badge-warning">Corte de energía (dying-gasp)</span>');
                    } else if (d.last_down_cause === 'LOSi/LOBi') {
                        setText('rt-down-cause', '<span class="badge badge-danger">Pérdida de señal óptica (LOSi)</span>');
                    } else {
                        setText('rt-down-cause', d.last_down_cause);
                    }

                    // CATV
                    if (d.has_catv) {
                        document.getElementById('catvCard').style.display = 'block';

                        if (d.catv_state === 'on') {
                            setText('rt-catv-state', '<span class="badge badge-success">Habilitado</span>');
                        } else if (d.catv_state === 'off') {
                            setText('rt-catv-state', '<span class="badge badge-danger">Deshabilitado</span>');
                        } else {
                            setText('rt-catv-state', '<span class="badge badge-secondary">Desconocido</span>');
                        }

                        if (d.catv_rx_power !== null && d.catv_rx_power > -35) {
                            const cls = d.catv_rx_power < -8 ? 'text-danger' : 'text-success';
                            setText('rt-catv-power', `<span class="${cls}">${d.catv_rx_power} dBm</span>`);
                        } else {
                            setText('rt-catv-power', `<span class="text-muted">Sin señal (${d.catv_rx_power} dBm)</span>`);
                        }

                        // Botones según estado
                        let actions = '';
                        if (d.catv_state === 'on') {
                            actions = catvButton(catvDisableUrl, 'btn-danger', 'fa-toggle-off', 'Deshabilitar CATV');
                        } else if (d.catv_state === 'off') {
                            actions = catvButton(catvEnableUrl, 'btn-success', 'fa-toggle-on', 'Habilitar CATV');
                        } else {
                            actions = catvButton(catvEnableUrl, 'btn-success', 'fa-toggle-on', 'Habilitar') + ' ' +
                                catvButton(catvDisableUrl, 'btn-danger', 'fa-toggle-off', 'Deshabilitar');
                        }
                        document.getElementById('rt-catv-actions').innerHTML = actions;
                    }
                })
                .catch(() => {
                    loader.style.display = 'none';
                    document.getElementById('realtimeErrorMsg').textContent =
                        'Error de conexión al consultar la OLT.';
                    errBox.style.display = 'block';
                });
        }

        function catvButton(url, btnClass, icon, label) {
            return `
                <form method="POST" action="${url}" class="d-inline">
                    <input type="hidden" name="_token" value="${csrfToken}">
                    <button type="submit" class="btn ${btnClass} btn-sm">
                        <i class="fas ${icon}"></i> ${label}
                    </button>
                </form>`;
        }

        // Cargar al abrir la página
        document.addEventListener('DOMContentLoaded', loadRealtime);

        // Botón de refresco manual
        document.getElementById('btnRefreshRealtime').addEventListener('click', loadRealtime);
    </script>
@endsection
