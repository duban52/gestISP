@extends('adminlte::page')
@section('title', 'Detalle Cuenta PPPoE')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <h2>Cuenta PPPoE — {{ $pppoe->username }}</h2>
        <a href="{{ route('pppoe.index') }}" class="btn btn-secondary">
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

    {{-- Error de conexión al router (oculto por defecto) --}}
    <div id="realtimeError" class="alert alert-warning" style="display:none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="realtimeErrorMsg"></span>
    </div>

    <div class="row">
        {{-- Columna izquierda: datos de la DB --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-id-card"></i> Datos de la Cuenta
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Usuario</th>
                            <td>{{ $pppoe->username }}</td>
                        </tr>
                        <tr>
                            <th>Contraseña</th>
                            <td>
                                <span id="passwordHidden">••••••••</span>
                                <span id="passwordShown" style="display:none;">{{ $pppoe->password }}</span>
                                <button class="btn btn-sm btn-link p-0 ml-1" id="btnTogglePassword" title="Mostrar/ocultar">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th>Router</th>
                            <td>{{ $pppoe->router->name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Perfil (Plan)</th>
                            <td>{{ $pppoe->profile }}</td>
                        </tr>
                        <tr>
                            <th>IP Remota fija</th>
                            <td>{{ $pppoe->remote_address ?? 'Dinámica (pool)' }}</td>
                        </tr>
                        <tr>
                            <th>Estado en sistema</th>
                            <td>
                                @if($pppoe->disabled)
                                    <span class="badge badge-danger">Suspendida</span>
                                @else
                                    <span class="badge badge-success">Activa</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Comentario</th>
                            <td>{{ $pppoe->comment ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Creada</th>
                            <td>{{ $pppoe->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Cliente / Contrato --}}
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-user"></i> Cliente y Contrato
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Cliente</th>
                            <td>
                                {{ $pppoe->contract->client->name ?? '—' }}
                                {{ $pppoe->contract->client->last_name ?? '' }}
                            </td>
                        </tr>
                        <tr>
                            <th>Identificación</th>
                            <td>{{ $pppoe->contract->client->identity_number ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Teléfono</th>
                            <td>{{ $pppoe->contract->client->number_phone ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Contrato #</th>
                            <td>{{ $pppoe->contract_id ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Dirección</th>
                            <td>{{ $pppoe->contract->address ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Barrio</th>
                            <td>{{ $pppoe->contract->neighborhood ?? '—' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Columna derecha: estado de conexión en tiempo real --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-wifi"></i> Estado de Conexión</span>
                    <button id="btnRefreshSession" class="btn btn-sm btn-light" title="Refrescar">
                        <i class="fas fa-sync"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    {{-- Loader --}}
                    <div id="sessionLoader" class="text-center p-4">
                        <div class="spinner-border text-success" role="status"></div>
                        <p class="mt-2 text-muted">Consultando el router...</p>
                    </div>

                    {{-- Tabla de sesión (oculta hasta que lleguen datos) --}}
                    <table id="sessionTable" class="table table-striped mb-0" style="display:none;">
                        <tr>
                            <th style="width:40%">Estado</th>
                            <td id="st-connected">—</td>
                        </tr>
                        <tr>
                            <th>Dirección IP</th>
                            <td id="st-address">—</td>
                        </tr>
                        <tr>
                            <th>Caller ID (MAC)</th>
                            <td id="st-caller">—</td>
                        </tr>
                        <tr>
                            <th>Tiempo conectado</th>
                            <td id="st-uptime">—</td>
                        </tr>
                        <tr>
                            <th>Servicio</th>
                            <td id="st-service">—</td>
                        </tr>
                        <tr>
                            <th>Session ID</th>
                            <td id="st-session-id">—</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Acciones --}}
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <i class="fas fa-tools"></i> Acciones
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('pppoe.restart', $pppoe) }}" class="d-inline"
                          onsubmit="return confirm('¿Reiniciar la sesión de {{ $pppoe->username }}? El cliente perderá conexión unos segundos.');">
                        @csrf
                        <button type="submit" class="btn btn-warning" id="btnRestartSession" disabled>
                            <i class="fas fa-redo"></i> Reiniciar Sesión
                        </button>
                    </form>

                    <form method="POST" action="{{ route('pppoe.toggle', $pppoe) }}" class="d-inline">
                        @csrf
                        @if($pppoe->disabled)
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-play"></i> Reactivar Cuenta
                            </button>
                        @else
                            <button type="submit" class="btn btn-danger"
                                    onclick="return confirm('¿Suspender la cuenta {{ $pppoe->username }}? El cliente quedará sin servicio.');">
                                <i class="fas fa-pause"></i> Suspender Cuenta
                            </button>
                        @endif
                    </form>

                    <small class="d-block text-muted mt-2">
                        <i class="fas fa-info-circle"></i>
                        "Reiniciar Sesión" desconecta al cliente momentáneamente para que renegocie
                        (útil tras cambios de plan). Solo está disponible si hay sesión activa.
                    </small>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js')
    <script>
        const sessionUrl = "{{ route('pppoe.realtime', $pppoe) }}";

        function setText(id, value) {
            document.getElementById(id).innerHTML =
                (value !== null && value !== undefined && value !== '') ? value : '—';
        }

        function loadSession() {
            const loader = document.getElementById('sessionLoader');
            const table  = document.getElementById('sessionTable');
            const errBox = document.getElementById('realtimeError');
            const btnRestart = document.getElementById('btnRestartSession');

            loader.style.display = 'block';
            table.style.display  = 'none';
            errBox.style.display = 'none';
            btnRestart.disabled  = true;

            fetch(sessionUrl)
                .then(r => r.json())
                .then(res => {
                    loader.style.display = 'none';

                    if (!res.ok) {
                        document.getElementById('realtimeErrorMsg').textContent = res.message;
                        errBox.style.display = 'block';
                        return;
                    }

                    table.style.display = 'table';

                    if (res.connected) {
                        const s = res.session;

                        setText('st-connected',
                            '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Conectado</span>');
                        setText('st-address',    s.address);
                        setText('st-caller',     s.caller_id);
                        setText('st-uptime',     s.uptime);
                        setText('st-service',    s.service);
                        setText('st-session-id', s.session_id);

                        // Habilitar reinicio de sesión solo si está conectado
                        btnRestart.disabled = false;
                    } else {
                        setText('st-connected',
                            '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Desconectado</span>');
                        setText('st-address',    null);
                        setText('st-caller',     null);
                        setText('st-uptime',     null);
                        setText('st-service',    null);
                        setText('st-session-id', null);
                    }
                })
                .catch(() => {
                    loader.style.display = 'none';
                    document.getElementById('realtimeErrorMsg').textContent =
                        'Error de conexión al consultar el router.';
                    errBox.style.display = 'block';
                });
        }

        // Mostrar/ocultar contraseña
        document.getElementById('btnTogglePassword').addEventListener('click', function () {
            const hidden = document.getElementById('passwordHidden');
            const shown  = document.getElementById('passwordShown');
            const isHidden = shown.style.display === 'none';

            shown.style.display  = isHidden ? 'inline' : 'none';
            hidden.style.display = isHidden ? 'none' : 'inline';
            this.querySelector('i').className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        });

        document.addEventListener('DOMContentLoaded', loadSession);
        document.getElementById('btnRefreshSession').addEventListener('click', loadSession);
    </script>
@endsection
