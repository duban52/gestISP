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
                    <span>
                        <i class="fas fa-broadcast-tower"></i> Estado en Tiempo Real
                        {{-- Indicador de la latencia real de la consulta SNMP --}}
                        <small id="rt-latency" class="badge badge-light ml-2" style="display:none;"></small>
                    </span>
                    <button id="btnRefreshRealtime" class="btn btn-sm btn-light" title="Refrescar">
                        <i class="fas fa-sync"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    {{-- Loader --}}
                    <div id="realtimeLoader" class="text-center p-4">
                        <div class="spinner-border text-success" role="status"></div>
                        <p class="mt-2 text-muted">Consultando la OLT por SNMP...</p>
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

            {{-- ============================================================
                 Gráficas históricas

                 Se alimentan de las muestras que guarda el comando
                 onts:poll. Si aún no hay muestras, se explica cómo
                 activarlas en lugar de mostrar una gráfica vacía.
                 ============================================================ --}}
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-chart-area"></i> Historial</span>
                    <select id="chartRange" class="form-control form-control-sm" style="width:auto;">
                        <option value="6">Últimas 6 horas</option>
                        <option value="24" selected>Últimas 24 horas</option>
                        <option value="72">Últimos 3 días</option>
                        <option value="168">Última semana</option>
                    </select>
                </div>
                <div class="card-body">
                    <div id="chartsEmpty" class="alert alert-info mb-0" style="display:none;">
                        <i class="fas fa-info-circle"></i>
                        Todavía no hay muestras registradas para esta ONT. El historial lo
                        genera la tarea programada <code>php artisan onts:poll</code>;
                        una vez que corra periódicamente, aquí verá la evolución.
                    </div>

                    <div id="chartsWrapper" style="display:none;">
                        {{-- Potencia óptica --}}
                        <h6 class="text-muted mb-2">Potencia óptica (dBm)</h6>
                        <canvas id="opticalChart" height="120"></canvas>

                        {{-- Ancho de banda --}}
                        <h6 class="text-muted mb-2 mt-4">Ancho de banda</h6>
                        <canvas id="trafficChart" height="120"></canvas>
                        <div id="trafficUnavailable" class="alert alert-secondary mt-2 mb-0" style="display:none;">
                            <i class="fas fa-info-circle"></i>
                            No hay datos de tráfico para esta ONT. Requiere que la OLT exponga
                            contadores por ONT: ejecute
                            <code>php artisan onts:poll --resolve-traffic</code> y verifique
                            con <code>php artisan olt:snmp-probe {{ $ont->olt_id }} --interfaces --filter=ONT</code>.
                        </div>
                    </div>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        const ontId          = {{ $ont->id }};
        const realtimeUrl    = `/onts/${ontId}/realtime`;
        const historyUrl     = `{{ route('onts.metrics_history', $ont) }}`;
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

                    // Latencia real de la consulta SNMP (con SSH esto
                    // eran segundos; ahora son milisegundos)
                    const lat = document.getElementById('rt-latency');
                    lat.textContent = res.cached
                        ? 'en caché'
                        : `SNMP · ${res.query_ms} ms`;
                    lat.style.display = 'inline-block';

                    // Estado operativo
                    if ((d.run_status || '').toLowerCase() === 'online') {
                        setText('rt-run-state', '<span class="badge badge-success">Online</span>');
                    } else {
                        setText('rt-run-state', `<span class="badge badge-danger">${d.run_status ?? 'Desconocido'}</span>`);
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

                    setText('rt-voltage',  d.voltage,      ' V');
                    setText('rt-current',  d.bias_current, ' mA');
                    setText('rt-distance', d.distance,     ' m');

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

        /* ============================================================
           GRÁFICAS HISTÓRICAS

           Los datos vienen de las muestras que guarda onts:poll.
           Se dibujan dos gráficas: potencia óptica (siempre que haya
           muestras) y ancho de banda (solo si la OLT expone
           contadores por ONT).
           ============================================================ */
        let opticalChart = null;
        let trafficChart = null;

        /** Formatea bits por segundo a la unidad más legible */
        function formatBps(bps) {
            if (bps === null || bps === undefined) return '—';
            if (bps >= 1e9) return (bps / 1e9).toFixed(2) + ' Gbps';
            if (bps >= 1e6) return (bps / 1e6).toFixed(2) + ' Mbps';
            if (bps >= 1e3) return (bps / 1e3).toFixed(1) + ' kbps';
            return bps + ' bps';
        }

        function loadCharts() {
            const hours = document.getElementById('chartRange').value;

            fetch(`${historyUrl}?hours=${hours}`)
                .then(r => r.json())
                .then(res => {
                    const empty   = document.getElementById('chartsEmpty');
                    const wrapper = document.getElementById('chartsWrapper');

                    if (!res.ok || res.count === 0) {
                        empty.style.display   = 'block';
                        wrapper.style.display = 'none';
                        return;
                    }

                    empty.style.display   = 'none';
                    wrapper.style.display = 'block';

                    const labels = res.samples.map(s => s.t.substring(5)); // sin el año

                    // ---- Potencia óptica ----
                    const opticalData = {
                        labels,
                        datasets: [
                            {
                                label: 'Rx ONT (dBm)',
                                data: res.samples.map(s => s.rx),
                                borderColor: '#28a745',
                                backgroundColor: 'rgba(40,167,69,.12)',
                                tension: .3,
                                spanGaps: true,
                            },
                            {
                                label: 'Rx en OLT (dBm)',
                                data: res.samples.map(s => s.olt_rx),
                                borderColor: '#17a2b8',
                                backgroundColor: 'rgba(23,162,184,.10)',
                                tension: .3,
                                spanGaps: true,
                            },
                        ],
                    };

                    if (opticalChart) opticalChart.destroy();
                    opticalChart = new Chart(document.getElementById('opticalChart'), {
                        type: 'line',
                        data: opticalData,
                        options: {
                            responsive: true,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: {
                                    callbacks: {
                                        label: c => `${c.dataset.label}: ${c.parsed.y} dBm`,
                                    },
                                },
                            },
                            scales: {
                                y: {
                                    title: { display: true, text: 'dBm' },
                                    // Umbral de alarma habitual en GPON
                                    suggestedMin: -30,
                                    suggestedMax: -10,
                                },
                            },
                        },
                    });

                    // ---- Ancho de banda ----
                    const trafficBox = document.getElementById('trafficUnavailable');
                    const trafficCanvas = document.getElementById('trafficChart');

                    if (!res.has_traffic) {
                        trafficBox.style.display = 'block';
                        trafficCanvas.style.display = 'none';
                        if (trafficChart) { trafficChart.destroy(); trafficChart = null; }
                        return;
                    }

                    trafficBox.style.display = 'none';
                    trafficCanvas.style.display = 'block';

                    if (trafficChart) trafficChart.destroy();
                    trafficChart = new Chart(trafficCanvas, {
                        type: 'line',
                        data: {
                            labels,
                            datasets: [
                                {
                                    label: 'Bajada (descarga)',
                                    data: res.samples.map(s => s.out_bps),
                                    borderColor: '#007bff',
                                    backgroundColor: 'rgba(0,123,255,.15)',
                                    fill: true,
                                    tension: .3,
                                    spanGaps: true,
                                },
                                {
                                    label: 'Subida (carga)',
                                    data: res.samples.map(s => s.in_bps),
                                    borderColor: '#fd7e14',
                                    backgroundColor: 'rgba(253,126,20,.12)',
                                    fill: true,
                                    tension: .3,
                                    spanGaps: true,
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { position: 'bottom' },
                                tooltip: {
                                    callbacks: {
                                        label: c => `${c.dataset.label}: ${formatBps(c.parsed.y)}`,
                                    },
                                },
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { callback: v => formatBps(v) },
                                },
                            },
                        },
                    });
                })
                .catch(() => {
                    document.getElementById('chartsEmpty').style.display = 'block';
                    document.getElementById('chartsWrapper').style.display = 'none';
                });
        }

        // Cargar al abrir la página
        document.addEventListener('DOMContentLoaded', () => {
            loadRealtime();
            loadCharts();
        });

        // Botón de refresco manual (fuerza lectura sin caché)
        document.getElementById('btnRefreshRealtime').addEventListener('click', loadRealtime);

        // Cambio de rango de las gráficas
        document.getElementById('chartRange').addEventListener('change', loadCharts);
    </script>
@endsection
