{{-- ============================================================
     Listado de ONTs autorizadas

     Mismo lenguaje que la ficha de la OLT, y por la misma razón: lo
     primero que se mira no es la tabla, son las cifras. Cuántas hay,
     cuántas están arriba y —sobre todo— cuántas tienen la señal en
     mal estado, que es lo que decide si hay que salir a la calle hoy.

     La tabla viene después, para buscar una ONT concreta.

     Los filtros van del lado del SERVIDOR: el buscador de DataTables
     solo ve lo que ya está en la página, y una sucursal puede tener
     miles de ONTs.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'ONTs autorizadas')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-network-wired mr-2"></i>ONTs autorizadas
            @if($oltFiltrada)
                <small class="text-muted">— {{ $oltFiltrada->name }}</small>
            @endif
        </h1>
        <div>
            {{-- Sin @can a proposito: este proyecto no define un
                 Gate::before, asi que @can mira los roles GLOBALES del
                 usuario y no el rol de la sucursal activa, que es con
                 el que trabaja el resto del sistema. Los dos pueden
                 divergir. El permiso lo exige el controlador de
                 destino, que es donde de verdad importa. --}}
            <a href="{{ route('onts.no-authorized') }}" class="btn btn-outline-warning">
                <i class="fas fa-search"></i> Sin autorizar
            </a>
            <a href="{{ route('olts.index') }}" class="btn btn-secondary">
                <i class="fas fa-server"></i> OLTs
            </a>
        </div>
    </div>
@endsection

@section('content')

    @if(session('success'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @elseif(session('success-update'))
        <div class="alert alert-warning alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('success-update') }}
        </div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('success-delete') }}
        </div>
    @endif

    {{-- ---------- Cifras principales ---------- --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $resumen['total'] }}</h3>
                    <p class="mb-0">ONTs {{ $oltFiltrada ? 'en esta OLT' : 'registradas' }}</p>
                </div>
                <div class="icon"><i class="fas fa-network-wired"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ $resumen['en_linea'] }}</h3>
                    <p class="mb-0">En línea</p>
                </div>
                <div class="icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $resumen['caidas'] > 0 ? 'bg-gradient-danger' : 'bg-gradient-secondary' }}">
                <div class="inner">
                    <h3>{{ $resumen['caidas'] }}</h3>
                    <p class="mb-0">Caídas</p>
                </div>
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $resumen['disponibilidad'] }}<sup style="font-size:1.2rem">%</sup></h3>
                    <p class="mb-0">Disponibilidad</p>
                </div>
                <div class="icon"><i class="fas fa-heartbeat"></i></div>
            </div>
        </div>
    </div>

    {{-- La disponibilidad se mide sobre las que DEBERÍAN dar servicio:
         una ONT cortada por facturación no es una falla de red, y
         contarla hundiría el indicador sin que nada esté mal. --}}
    @if($resumen['deshabilitadas'] > 0 || $resumen['sin_contrato'] > 0)
        <div class="alert alert-light border py-2">
            <i class="fas fa-info-circle text-muted"></i>
            @if($resumen['deshabilitadas'] > 0)
                <strong>{{ $resumen['deshabilitadas'] }}</strong> deshabilitada(s) a propósito
                (no cuentan como caídas ni bajan la disponibilidad).
            @endif
            @if($resumen['sin_contrato'] > 0)
                <strong>{{ $resumen['sin_contrato'] }}</strong> sin contrato asociado.
                <a href="{{ request()->fullUrlWithQuery(['contrato' => 'no']) }}">Verlas</a>.
            @endif
        </div>
    @endif

    {{-- ============================================================
         Calidad de la señal

         Cada banda es un botón que filtra: es la diferencia entre
         "hay 14 críticas" y poder ver CUÁLES son las 14.
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0"><i class="fas fa-signal mr-1"></i> Calidad de la señal óptica</h3>
            <small class="text-muted">
                @if($resumen['potencia_media'] !== null)
                    Promedio de las que están en línea:
                    <strong>{{ number_format($resumen['potencia_media'], 2) }} dBm</strong>
                @else
                    Todavía no hay lecturas de potencia
                @endif
            </small>
        </div>
        <div class="card-body py-3">
            <div class="row">
                @foreach($resumen['bandas'] as $clave => $banda)
                    @php
                        $activa = ($filtros['banda'] ?? '') === $clave;
                        $url = $activa
                            ? request()->fullUrlWithoutQuery('banda')
                            : request()->fullUrlWithQuery(['banda' => $clave]);
                    @endphp
                    <div class="col-6 col-md">
                        <a href="{{ $url }}"
                           class="d-block text-center text-decoration-none p-2 rounded border
                                  {{ $activa ? 'border-' . $banda['color'] . ' bg-light' : 'border-light' }}"
                           title="{{ $banda['rango'] }}">
                            <div class="h3 mb-0 text-{{ $banda['color'] }}">{{ $banda['cantidad'] }}</div>
                            <div class="small text-uppercase text-muted">{{ $banda['etiqueta'] }}</div>
                            <div class="text-muted" style="font-size:.7rem;">{{ $banda['rango'] }}</div>
                        </a>
                    </div>
                @endforeach
            </div>

            @if($resumen['con_problema'] > 0)
                <div class="alert alert-warning py-2 mt-3 mb-0">
                    <i class="fas fa-tools"></i>
                    <strong>{{ $resumen['con_problema'] }}</strong> ONT(s) con la señal fuera de rango
                    (débil, crítica o saturada). Son las que conviene revisar antes de que el cliente llame.
                </div>
            @endif
        </div>
    </div>

    {{-- ---------- Filtros ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0"><i class="fas fa-filter mr-1"></i> Filtros</h3>
        </div>
        <form method="GET" class="card-body py-3">
            <div class="row align-items-end">
                <div class="col-md-3 form-group mb-2">
                    <label class="mb-1 small">OLT</label>
                    <select name="olt" class="form-control form-control-sm">
                        <option value="">Todas</option>
                        @foreach($olts as $olt)
                            <option value="{{ $olt->id }}" @selected((string) ($filtros['olt'] ?? '') === (string) $olt->id)>
                                {{ $olt->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 form-group mb-2">
                    <label class="mb-1 small">Estado</label>
                    <select name="estado" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        <option value="en_linea" @selected(($filtros['estado'] ?? '') === 'en_linea')>En línea</option>
                        <option value="caida" @selected(($filtros['estado'] ?? '') === 'caida')>Caídas</option>
                        <option value="deshabilitada" @selected(($filtros['estado'] ?? '') === 'deshabilitada')>Deshabilitadas</option>
                    </select>
                </div>
                <div class="col-md-3 form-group mb-2">
                    <label class="mb-1 small">Señal</label>
                    <select name="banda" class="form-control form-control-sm">
                        <option value="">Todas</option>
                        @foreach($resumen['bandas'] as $clave => $banda)
                            <option value="{{ $clave }}" @selected(($filtros['banda'] ?? '') === $clave)>
                                {{ $banda['etiqueta'] }} ({{ $banda['rango'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 form-group mb-2">
                    <label class="mb-1 small">Contrato</label>
                    <select name="contrato" class="form-control form-control-sm">
                        <option value="">Indiferente</option>
                        <option value="si" @selected(($filtros['contrato'] ?? '') === 'si')>Con contrato</option>
                        <option value="no" @selected(($filtros['contrato'] ?? '') === 'no')>Sin contrato</option>
                    </select>
                </div>
                <div class="col-md-1 form-group mb-2">
                    <button class="btn btn-primary btn-sm btn-block" title="Aplicar">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>

            @if(array_filter($filtros))
                <a href="{{ route('onts.authorized') }}" class="btn btn-link btn-sm pl-0">
                    <i class="fas fa-times"></i> Quitar todos los filtros
                </a>
            @endif
        </form>
    </div>

    {{-- ---------- Tabla ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0">
                <i class="fas fa-list mr-1"></i> Detalle
                <span class="badge badge-secondary ml-1">{{ $onts->count() }}</span>
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="ontsTable" class="table table-hover table-sm">
                    <thead class="thead-light">
                    <tr>
                        <th>ONT</th>
                        <th>Ubicación</th>
                        <th>Cliente</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Señal</th>
                        <th class="text-center">VLAN</th>
                        <th class="text-center" style="width: 90px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($onts as $ont)
                        @php
                            $banda = ($ont->rx_power !== null && $ont->rx_power !== '')
                                ? \App\Services\OltStatistics::bandaDe((float) $ont->rx_power)
                                : null;
                            $colorBanda = $banda ? ($resumen['bandas'][$banda]['color'] ?? 'secondary') : 'secondary';
                            $deshabilitada = $ont->admin_enabled === false;
                        @endphp
                        <tr>
                            {{-- El serial es lo que identifica la ONT en campo;
                                 el resto de datos técnicos van debajo, pequeños. --}}
                            <td>
                                <a href="{{ route('onts.show', $ont) }}" class="font-weight-bold">
                                    <code>{{ $ont->sn }}</code>
                                </a>
                                @if($ont->model)
                                    <small class="d-block text-muted">{{ $ont->model }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="text-muted small">{{ $ont->olt->name ?? '—' }}</span>
                                <span class="d-block">
                                    <code>{{ $ont->slot }}/{{ $ont->port }}</code>
                                    <span class="badge badge-light border">ONU {{ $ont->onu_id }}</span>
                                </span>
                            </td>
                            <td>
                                @if($ont->contract)
                                    <a href="{{ route('contracts.show', $ont->contract) }}">
                                        {{ $ont->contract->numero_visible }}
                                    </a>
                                    <small class="d-block text-muted">{{ $ont->description }}</small>
                                @else
                                    <span class="badge badge-warning">Sin contrato</span>
                                    <small class="d-block text-muted">{{ $ont->description }}</small>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($deshabilitada)
                                    <span class="badge badge-secondary" title="Cortada a propósito">Deshabilitada</span>
                                @elseif($ont->status)
                                    <span class="badge badge-success">En línea</span>
                                @else
                                    <span class="badge badge-danger">Caída</span>
                                @endif
                            </td>
                            {{-- data-order deja que DataTables ordene por el
                                 NÚMERO. Sin él ordenaría el texto, y "-15.0"
                                 quedaría antes que "-28.5", justo al revés. --}}
                            <td class="text-center" data-order="{{ $ont->rx_power !== null && $ont->rx_power !== '' ? (float) $ont->rx_power : 99 }}">
                                <span id="rx-power-{{ $ont->id }}"
                                      class="badge badge-{{ $colorBanda }}"
                                      title="{{ $banda ? $resumen['bandas'][$banda]['etiqueta'] : 'Sin lectura' }}">
                                    {{ ($ont->rx_power !== null && $ont->rx_power !== '') ? $ont->rx_power . ' dBm' : '—' }}
                                </span>
                                <button class="btn btn-sm btn-link p-0 ml-1 btn-sync-power"
                                        data-id="{{ $ont->id }}" title="Refrescar potencia">
                                    <i class="fas fa-sync"></i>
                                </button>
                            </td>
                            <td class="text-center">{{ $ont->vlan ?: '—' }}</td>
                            <td class="text-center">
                                <a href="{{ route('onts.show', $ont) }}" class="btn btn-outline-info btn-sm" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button class="btn btn-outline-danger btn-sm btn-eliminar"
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
        </div>
    </div>

    {{-- Modal de confirmación de borrado --}}
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
                    <p class="text-danger mb-0">
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
    <style>
        /* Las tarjetas de banda son enlaces: se comportan como botones
           sin parecer botones, para no competir con las cifras. */
        .card-body a.rounded:hover {
            background: #f4f6f9 !important;
        }
    </style>
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(function () {
            $('#ontsTable').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
                pageLength: 25,
                order: [[0, 'asc']],
                // Estado y acciones no se ordenan; la señal sí, por el
                // data-order numérico de cada celda.
                columnDefs: [{ orderable: false, targets: [3, 6] }],
            });
        });

        // Eliminar ONT
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar')) {
                const btn  = e.target.closest('.btn-eliminar');
                const id   = btn.getAttribute('data-id');
                const sn   = btn.getAttribute('data-sn');
                const desc = btn.getAttribute('data-desc');

                document.getElementById('eliminarSn').textContent   = sn;
                document.getElementById('eliminarDesc').textContent  = desc;
                document.getElementById('formEliminarOnt').action    = `/onts/${id}`;

                // Restaurar el formulario por si el intento anterior falló
                document.getElementById('eliminarCampos').style.display   = 'block';
                document.getElementById('eliminarBotones').style.display  = 'flex';
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
            document.getElementById('eliminarCampos').style.display   = 'none';
            document.getElementById('eliminarBotones').style.display  = 'none';
            document.getElementById('eliminarProgreso').style.display = 'block';

            $('#modalEliminarOnt').data('bs.modal')._config.backdrop = 'static';
            $('#modalEliminarOnt').data('bs.modal')._config.keyboard = false;
        });

        // Refrescar potencia sin recargar la página
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
                    if (data.ok && data.status) {
                        display.textContent = data.rx_power + ' dBm';
                        display.className   = 'badge badge-' + colorDe(data.rx_power);
                    } else if (data.ok) {
                        display.textContent = 'Offline';
                        display.className   = 'badge badge-secondary';
                    } else {
                        display.textContent = 'Error';
                        display.className   = 'badge badge-warning';
                    }
                })
                .catch(() => {
                    display.textContent = 'Error';
                    display.className   = 'badge badge-warning';
                })
                .finally(() => {
                    icon.classList.remove('fa-spin');
                    btn.disabled = false;
                });
        });

        /* Los mismos umbrales que usa el servidor. Se repiten aquí
           porque la potencia se refresca sin recargar la página y hay
           que recolorear la insignia en el navegador; si algún día se
           afinan, hay que tocar los dos sitios. */
        function colorDe(dbm) {
            if (dbm > -8)  return 'danger';   // saturación
            if (dbm > -22) return 'success';  // óptima
            if (dbm > -25) return 'info';     // aceptable
            if (dbm > -27) return 'warning';  // débil
            return 'danger';                  // crítica
        }
    </script>
@endsection
