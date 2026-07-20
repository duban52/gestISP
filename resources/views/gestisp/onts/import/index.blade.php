@extends('adminlte::page')

@section('title', 'Importar ONTs')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <h2>Importar ONTs desde una OLT</h2>
        <a href="{{ route('onts.authorized') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
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
                    <div class="card-body d-flex justify-content-between align-items-center">
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
                <table class="table table-hover mb-0">
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
                            <td class="text-nowrap">{{ $run->created_at->format('d/m/Y h:i a') }}</td>
                            <td>{{ $run->olt->name ?? '—' }}</td>
                            <td>{{ $run->user->name ?? '—' }} {{ $run->user->last_name ?? '' }}</td>
                            <td>
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
                            <td class="run-mensaje">
                                @if($run->status === 'failed')
                                    <span class="text-danger">{{ $run->message }}</span>
                                @else
                                    {{ $run->message }}
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

                    if (res.muestra.length === 0) {
                        tbody.innerHTML =
                            '<tr><td colspan="6" class="text-center text-muted py-3">' +
                            'No hay ONTs nuevas: todas las de esta OLT ya están en GestISP.</td></tr>';
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
                                    <td>${o.contract_id
                                        ? '<span class="badge badge-info">Contrato #' + o.contract_id + '</span>'
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
