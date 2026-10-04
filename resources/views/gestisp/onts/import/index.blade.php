@extends('adminlte::page')

@section('title', 'Importar ONTs')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-file-import mr-2"></i>Importar ONT desde una OLT
        </h1>
        {{-- En el teléfono el botón baja y ocupa el ancho completo
             (.acciones-movil en public/css/gestisp-movil.css). --}}
        <div class="acciones-movil">
            <a href="{{ route('onts.authorized') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Explicación del proceso

         El usuario debe entender qué hace la herramienta ANTES de
         ejecutarla sobre una OLT en producción.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <h5><i class="fas fa-info-circle text-primary"></i> ¿Para qué sirve?</h5>
            <p class="mb-2">
                Incorpora a GestISP las ONTs que <strong>ya están funcionando</strong> en una OLT:
                equipos que se autorizaron a mano o que venían administrados por otro
                sistema (Smart OLT, AdminOLT, etc.).
            </p>
            <ul class="mb-0">
                <li>La OLT se consulta <strong>solo de lectura</strong>: no se modifica ninguna configuración del equipo.</li>
                <li>Las ONTs que ya estén registradas en GestISP <strong>no se tocan</strong>; se cuentan como omitidas.</li>
                <li>Si la descripción de la ONT en la OLT contiene el documento del cliente,
                    el sistema <strong>la asocia automáticamente a su contrato</strong>; si no puede
                    identificarlo con certeza, la deja sin asignar para que usted la vincule después.</li>
            </ul>
        </div>
    </div>

    {{-- ============================================================
         Paso 1 y 2: elegir la OLT, analizar y confirmar
         ============================================================ --}}
    <div class="card">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-search"></i> Paso 1 — Analizar una OLT
        </div>
        <div class="card-body">
            <div class="form-row align-items-end">
                <div class="col-md-6">
                    <label for="oltSelect">OLT a consultar</label>
                    <select id="oltSelect" class="form-control">
                        <option value="">Seleccione una OLT...</option>
                        @foreach($olts as $olt)
                            <option value="{{ $olt->id }}">
                                {{ $olt->name }} ({{ $olt->ip_address }}) — {{ $olt->onts_count }} ONT(s) en GestISP
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 mt-2 mt-md-0">
                    <button id="btnAnalizar" class="btn btn-primary btn-block" disabled>
                        <i class="fas fa-search"></i> Analizar
                    </button>
                </div>
            </div>

            @if($olts->isEmpty())
                <div class="alert alert-warning mt-3 mb-0">
                    No hay OLTs activas en esta sucursal.
                </div>
            @endif

            {{-- Progreso del análisis --}}
            <div id="analisisCargando" class="text-center py-4" style="display:none;">
                <div class="spinner-border text-primary" style="width:3rem;height:3rem;"></div>
                <h5 class="mt-3 mb-1">Consultando la OLT...</h5>
                <p class="text-muted mb-0">
                    Se está leyendo el inventario completo del equipo.
                    En una OLT con miles de ONTs esto puede tardar hasta un minuto.
                </p>
            </div>

            {{-- Error del análisis --}}
            <div id="analisisError" class="alert alert-danger mt-3" style="display:none;">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="analisisErrorMsg"></span>
            </div>

            {{-- Resultado del análisis --}}
            <div id="analisisResultado" class="mt-4" style="display:none;">
                <h5 class="mb-3"><i class="fas fa-clipboard-check text-success"></i> Resultado del análisis</h5>

                <div class="row text-center mb-3">
                    <div class="col-6 col-md-3">
                        <div class="border rounded py-3">
                            <h3 id="resTotal" class="mb-0">0</h3>
                            <small class="text-muted">ONTs en la OLT</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded py-3 bg-light">
                            <h3 id="resNuevas" class="mb-0 text-success">0</h3>
                            <small class="text-muted">Se importarían</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded py-3">
                            <h3 id="resExistentes" class="mb-0 text-secondary">0</h3>
                            <small class="text-muted">Ya registradas</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded py-3">
                            <h3 id="resSinUbicacion" class="mb-0 text-warning">0</h3>
                            <small class="text-muted">Sin ubicación</small>
                        </div>
                    </div>
                </div>

                {{-- LAS QUE NO VAN A ENTRAR.

                     Esto es lo que faltaba: el análisis decía «sin
                     ubicación: 200» y la importación remataba con
                     «omitidas por datos incompletos: 200», sin decir
                     cuáles ni qué les faltaba. Aquí salen una por una,
                     ANTES de importar, que es cuando se pueden
                     corregir en la OLT. --}}
                <div id="analisisProblemas" class="card border-warning mb-3" style="display:none;">
                    <div class="card-header py-2 bg-warning">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Estas ONTs <strong>no se van a importar</strong>
                            <span class="badge badge-dark ml-1" id="resProblemasCont">0</span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            La OLT no informa en qué tarjeta y puerto PON están, y sin eso el
                            sistema no podría operarlas (ni cortarlas, ni reiniciarlas, ni saber
                            de qué NAP cuelgan). <strong>El dato falta en la OLT, no aquí.</strong>
                        </p>
                        <p class="text-muted small">
                            Suele pasar con ONTs que quedaron registradas en el equipo pero ya no
                            están conectadas, o con puertos de una tarjeta que la OLT no publica
                            por SNMP. Si estos equipos ya no existen, bórrelos de la OLT; si sí,
                            revise la tarjeta. Las demás ONTs se pueden importar ya.
                        </p>

                        <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>Serial</th>
                                    <th>ONT ID</th>
                                    <th>Descripción en la OLT</th>
                                    <th>Qué falta</th>
                                </tr>
                                </thead>
                                <tbody id="resProblemas"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <p class="text-muted">
                    <i class="fas fa-eye"></i> Muestra de las primeras ONTs que se importarían:
                </p>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                        <tr>
                            <th>Serial</th>
                            <th>Ubicación</th>
                            <th>ONT ID</th>
                            <th>Descripción en la OLT</th>
                            <th>Estado</th>
                            <th>Contrato</th>
                        </tr>
                        </thead>
                        <tbody id="resMuestra"></tbody>
                    </table>
                </div>

                {{-- Paso 2: confirmar --}}
                <div class="card border-success mt-3">
                    <div class="card-body d-flex justify-content-between align-items-center flex-wrap toque">
                        <div>
                            <strong>Paso 2 — Confirmar la importación</strong>
                            <small class="d-block text-muted">
                                El proceso corre en segundo plano; puede seguir usando el sistema.
                            </small>
                        </div>
                        <form method="POST" action="{{ route('onts.import.store') }}"
                              onsubmit="return confirm('¿Importar las ONTs nuevas de esta OLT a GestISP?');">
                            @csrf
                            <input type="hidden" name="olt_id" id="confirmOltId">
                            <button type="submit" class="btn btn-success btn-lg" id="btnImportar">
                                <i class="fas fa-file-import"></i> Importar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Paso 3: historial y avance de las importaciones
         ============================================================ --}}
    <div class="card">
        <div class="card-header bg-secondary text-white">
            <i class="fas fa-history"></i> Importaciones realizadas
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 tabla-movil">
                    <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>OLT</th>
                        <th>Usuario</th>
                        <th style="width:28%">Avance</th>
                        <th>Resultado</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($runs as $run)
                        <tr data-run-id="{{ $run->id }}" @class(['run-activa' => $run->enCurso()])>
                            {{-- Encabeza la ficha en el teléfono: la OLT es
                                 lo que distingue una importación de otra,
                                 con la fecha justo debajo. --}}
                            <td class="celda-principal text-nowrap" data-label="">
                                <strong>{{ $run->olt->name ?? '—' }}</strong>
                                <small class="d-block text-muted">
                                    {{ $run->created_at->format('d/m/Y h:i a') }}
                                </small>
                            </td>
                            <td class="solo-escritorio" data-label="OLT">{{ $run->olt->name ?? '—' }}</td>
                            <td data-label="Usuario">{{ $run->user->name ?? '—' }} {{ $run->user->last_name ?? '' }}</td>
                            <td data-label="Avance">
                                <div class="progress" style="height:20px;">
                                    <div class="progress-bar progress-bar-striped run-barra
                                        @if($run->status === 'failed') bg-danger
                                        @elseif($run->status === 'completed') bg-success
                                        @else progress-bar-animated @endif"
                                         style="width: {{ $run->porcentaje() }}%">
                                        {{ $run->porcentaje() }}%
                                    </div>
                                </div>
                                <small class="run-estado text-muted">{{ $run->estadoLegible() }}</small>
                            </td>
                            <td class="run-mensaje romper-texto" data-label="Resultado">
                                @if($run->status === 'failed')
                                    <span class="text-danger">{{ $run->message }}</span>
                                @else
                                    {{ $run->message }}
                                @endif

                                {{-- QUÉ ONT NO ENTRÓ Y POR QUÉ.
                                     Antes la corrida decía «200 omitidas por
                                     datos incompletos» y ahí se acababa: ni
                                     cuáles ni qué les faltaba, así que no
                                     había nada que corregir. --}}
                                @if(!empty($run->skipped_details))
                                    <button class="btn btn-link btn-sm p-0 mt-1" type="button"
                                            data-toggle="collapse" data-target="#omitidas-{{ $run->id }}">
                                        <i class="fas fa-list-ul"></i>
                                        Ver las {{ count($run->skipped_details) }} que no entraron
                                    </button>

                                    <div class="collapse mt-2" id="omitidas-{{ $run->id }}">
                                        <div class="table-responsive border rounded">
                                            <table class="table table-sm mb-0">
                                                <thead class="thead-light">
                                                <tr>
                                                    <th>Serial</th>
                                                    <th>Posición</th>
                                                    <th>Motivo</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($run->skipped_details as $omitida)
                                                    <tr>
                                                        <td class="text-monospace">{{ $omitida['sn'] ?? '—' }}</td>
                                                        <td class="text-nowrap">{{ $omitida['ubicacion'] ?? '—' }}</td>
                                                        <td>
                                                            {{ $omitida['motivo'] ?? '—' }}
                                                            @if(!empty($omitida['descripcion']))
                                                                <small class="d-block text-muted">{{ $omitida['descripcion'] }}</small>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>

                                        @if($run->skipped_invalid > count($run->skipped_details))
                                            <small class="text-muted d-block mt-1">
                                                Se detallan las primeras {{ count($run->skipped_details) }}
                                                de {{ $run->skipped_invalid }}.
                                            </small>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Aún no se ha importado ninguna OLT.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection

@section('js')
    <script>
        const previewUrl = "{{ route('onts.import.preview') }}";
        const statusUrlBase = "{{ url('onts/import') }}";
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        const oltSelect = document.getElementById('oltSelect');
        const btnAnalizar = document.getElementById('btnAnalizar');

        oltSelect.addEventListener('change', function () {
            btnAnalizar.disabled = !this.value;
            document.getElementById('analisisResultado').style.display = 'none';
            document.getElementById('analisisError').style.display = 'none';
        });

        /* ============================================================
           PASO 1 — Análisis previo

           Consulta la OLT y muestra qué se importaría, sin escribir
           nada. Así el usuario decide con información en la mano.
           ============================================================ */
        btnAnalizar.addEventListener('click', function () {
            const oltId = oltSelect.value;
            if (!oltId) return;

            const cargando = document.getElementById('analisisCargando');
            const resultado = document.getElementById('analisisResultado');
            const error = document.getElementById('analisisError');

            cargando.style.display = 'block';
            resultado.style.display = 'none';
            error.style.display = 'none';
            btnAnalizar.disabled = true;

            fetch(previewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ olt_id: oltId }),
            })
                .then(r => r.json())
                .then(res => {
                    cargando.style.display = 'none';
                    btnAnalizar.disabled = false;

                    if (!res.ok) {
                        document.getElementById('analisisErrorMsg').textContent = res.message;
                        error.style.display = 'block';
                        return;
                    }

                    document.getElementById('resTotal').textContent = res.total;
                    document.getElementById('resNuevas').textContent = res.nuevas;
                    document.getElementById('resExistentes').textContent = res.existentes;
                    document.getElementById('resSinUbicacion').textContent = res.sin_ubicacion;
                    document.getElementById('confirmOltId').value = oltId;

                    // Muestra de las ONTs a importar
                    const tbody = document.getElementById('resMuestra');
                    tbody.innerHTML = '';

                    // Las que no van a entrar, con su motivo
                    const problemas = res.problemas ?? [];
                    const caja = document.getElementById('analisisProblemas');
                    const cuerpoProblemas = document.getElementById('resProblemas');

                    caja.style.display = problemas.length ? 'block' : 'none';
                    document.getElementById('resProblemasCont').textContent = problemas.length;
                    cuerpoProblemas.innerHTML = '';

                    problemas.forEach(o => {
                        const fila = document.createElement('tr');
                        fila.innerHTML = `
                            <td><strong></strong></td>
                            <td class="text-center"></td>
                            <td class="descripcion"></td>
                            <td class="motivo"></td>`;
                        // textContent y no innerHTML: la descripción la
                        // escribe quien configura la OLT y puede traer
                        // cualquier cosa.
                        fila.querySelector('strong').textContent = o.sn ?? '—';
                        fila.children[1].textContent = o.onu_id ?? '—';
                        fila.querySelector('.descripcion').textContent = o.descripcion ?? '—';
                        fila.querySelector('.motivo').textContent = o.motivo ?? '—';
                        cuerpoProblemas.appendChild(fila);
                    });

                    if (res.muestra.length === 0) {
                        tbody.innerHTML =
                            '<tr><td colspan="6" class="text-center text-muted py-3">' +
                            (problemas.length
                                ? 'No hay ninguna ONT importable: las nuevas de esta OLT son las de arriba, ' +
                                  'y les falta la ubicación.'
                                : 'No hay ONTs nuevas: todas las de esta OLT ya están en GestISP.') +
                            '</td></tr>';
                        document.getElementById('btnImportar').disabled = true;
                    } else {
                        document.getElementById('btnImportar').disabled = false;

                        res.muestra.forEach(o => {
                            tbody.insertAdjacentHTML('beforeend', `
                                <tr>
                                    <td><strong>${o.sn}</strong></td>
                                    <td>${o.slot !== null ? '0/' + o.slot + '/' + o.port : '<span class="text-warning">sin ubicar</span>'}</td>
                                    <td>${o.onu_id}</td>
                                    <td>${o.description || '<span class="text-muted">sin descripción</span>'}</td>
                                    <td>${o.online
                                        ? '<span class="badge badge-success">En línea</span>'
                                        : '<span class="badge badge-secondary">Fuera de línea</span>'}</td>
                                    <td>${o.contract_number
                                        ? '<span class="badge badge-info">Contrato ' + o.contract_number + '</span>'
                                        : '<span class="text-muted">sin asignar</span>'}</td>
                                </tr>`);
                        });
                    }

                    resultado.style.display = 'block';
                })
                .catch(() => {
                    cargando.style.display = 'none';
                    btnAnalizar.disabled = false;
                    document.getElementById('analisisErrorMsg').textContent =
                        'No se pudo completar el análisis. Revise la conexión con la OLT.';
                    error.style.display = 'block';
                });
        });

        /* ============================================================
           PASO 3 — Seguimiento del avance

           Mientras haya importaciones en curso se consulta su estado
           cada 3 segundos y se actualiza la fila, sin recargar la
           página ni volver a consultar la OLT.
           ============================================================ */
        function seguirImportaciones() {
            const activas = document.querySelectorAll('tr.run-activa');

            if (activas.length === 0) return;

            activas.forEach(fila => {
                const id = fila.getAttribute('data-run-id');

                fetch(`${statusUrlBase}/${id}/status`)
                    .then(r => r.json())
                    .then(res => {
                        if (!res.ok) return;

                        const barra = fila.querySelector('.run-barra');
                        barra.style.width = res.porcentaje + '%';
                        barra.textContent = res.porcentaje + '%';

                        fila.querySelector('.run-estado').textContent = res.estado;
                        fila.querySelector('.run-mensaje').textContent = res.message ?? '';

                        if (!res.en_curso) {
                            // Terminó: fijar el color final y dejar de seguirla
                            barra.classList.remove('progress-bar-animated');
                            barra.classList.add(res.status === 'failed' ? 'bg-danger' : 'bg-success');
                            fila.classList.remove('run-activa');

                            // Y recargar: el detalle de lo que no entró se
                            // pinta en el servidor. Reconstruirlo aquí seria
                            // una segunda copia de la misma tabla.
                            if ((res.skipped_details ?? []).length > 0) {
                                setTimeout(() => window.location.reload(), 800);
                            }

                            if (res.status === 'completed') {
                                fila.querySelector('.run-mensaje').classList.add('text-success');
                            } else {
                                fila.querySelector('.run-mensaje').classList.add('text-danger');
                            }
                        }
                    })
                    .catch(() => { /* un fallo puntual no debe romper el seguimiento */ });
            });
        }

        setInterval(seguirImportaciones, 3000);
        document.addEventListener('DOMContentLoaded', seguirImportaciones);
    </script>
@endsection
