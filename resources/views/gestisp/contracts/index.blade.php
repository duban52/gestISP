{{-- ============================================================
     Listado de contratos con filtros combinables

     Todos los filtros se ACUMULAN: los que se llenen se aplican a la
     vez. Es lo que permite las preguntas con las que de verdad se
     trabaja —"los de tal barrio con dos meses de mora", "los
     activados en marzo que todavía no tienen ONT"— en vez del único
     campo/valor que había antes.

     Las COLUMNAS las elige el usuario. El catálogo vive en
     App\Services\ContractQuery y lo comparten esta tabla y la
     exportación, de modo que el Excel trae exactamente lo que se ve.
     La selección se recuerda en el navegador (localStorage): quien
     saca listados de cartera todos los días no debería reconfigurar
     las columnas cada mañana.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Contratos')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-file-contract mr-2"></i>Listado de contratos</h1>
@endsection

@section('content')

    @if(session('success-delete'))
        <div class="alert alert-danger">{{ session('success-delete') }}</div>
    @elseif(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- ============================================================
         Filtros
         ============================================================ --}}
    <form method="GET" action="{{ route('contracts.index') }}" id="formFiltros">
        <div class="card card-outline card-primary shadow-sm">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-filter mr-1"></i> Filtros</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
            </div>

            <div class="card-body">
                {{-- Búsqueda libre: es lo que se usa el 90% de las
                     veces ("búsqueme a este"), así que va primero y
                     ocupa toda la fila. --}}
                <div class="form-group">
                    <label for="q">Búsqueda rápida</label>
                    <input type="text" name="q" id="q" class="form-control form-control-lg"
                           value="{{ $filtros['q'] ?? '' }}"
                           placeholder="Contrato, cédula, nombre, teléfono, dirección, usuario PPPoE o serial...">
                </div>

                <div class="row">
                    <div class="col-md-3 form-group">
                        <label>Estado</label>
                        <select name="status[]" class="form-control select-multiple" multiple>
                            @foreach($estados as $estado)
                                <option value="{{ $estado->value }}"
                                    @selected(in_array($estado->value, (array) ($filtros['status'] ?? [])))>
                                    {{ $estado->value }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 form-group">
                        <label>Plan</label>
                        <select name="plan_id[]" class="form-control select-multiple" multiple>
                            @foreach($planes as $plan)
                                <option value="{{ $plan->id }}"
                                    @selected(in_array((string) $plan->id, (array) ($filtros['plan_id'] ?? [])))>
                                    {{ $plan->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 form-group">
                        <label>Estrato</label>
                        <select name="social_stratum[]" class="form-control select-multiple" multiple>
                            @foreach([1, 2, 3, 4, 5, 6] as $estrato)
                                <option value="{{ $estrato }}"
                                    @selected(in_array((string) $estrato, (array) ($filtros['social_stratum'] ?? [])))>
                                    Estrato {{ $estrato }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 form-group">
                        <label>Barrio</label>
                        <input type="text" name="neighborhood" class="form-control"
                               value="{{ $filtros['neighborhood'] ?? '' }}">
                    </div>

                    <div class="col-md-3 form-group">
                        <label>Dirección contiene</label>
                        <input type="text" name="address" class="form-control"
                               value="{{ $filtros['address'] ?? '' }}">
                    </div>

                    <div class="col-md-3 form-group">
                        <label>Municipio</label>
                        <input type="text" name="municipality" class="form-control"
                               value="{{ $filtros['municipality'] ?? '' }}">
                    </div>

                    <div class="col-md-3 form-group">
                        <label>Activado desde</label>
                        <input type="date" name="activation_from" class="form-control"
                               value="{{ $filtros['activation_from'] ?? '' }}">
                    </div>

                    <div class="col-md-3 form-group">
                        <label>Activado hasta</label>
                        <input type="date" name="activation_to" class="form-control"
                               value="{{ $filtros['activation_to'] ?? '' }}">
                    </div>
                </div>

                {{-- ---------- Cartera y equipos ---------- --}}
                <div class="row border-top pt-3">
                    <div class="col-md-3 form-group">
                        <label>
                            Meses de saldo (mínimo)
                            <i class="fas fa-question-circle text-muted"
                               title="Cada factura sin pagar es un mes. Escriba 2 para los que llevan dos meses debiendo."></i>
                        </label>
                        <input type="number" name="facturas_min" class="form-control" min="0" step="1"
                               value="{{ $filtros['facturas_min'] ?? '' }}" placeholder="Ej.: 2">
                    </div>

                    <div class="col-md-2 form-group">
                        <label>Saldo desde</label>
                        <input type="number" name="saldo_min" class="form-control" step="0.01"
                               value="{{ $filtros['saldo_min'] ?? '' }}">
                    </div>

                    <div class="col-md-2 form-group">
                        <label>Saldo hasta</label>
                        <input type="number" name="saldo_max" class="form-control" step="0.01"
                               value="{{ $filtros['saldo_max'] ?? '' }}">
                    </div>

                    <div class="col-md-2 form-group">
                        <label>Tiene ONT</label>
                        <select name="has_ont" class="form-control">
                            <option value="">Indiferente</option>
                            <option value="si" @selected(($filtros['has_ont'] ?? '') === 'si')>Sí</option>
                            <option value="no" @selected(($filtros['has_ont'] ?? '') === 'no')>No</option>
                        </select>
                    </div>

                    <div class="col-md-2 form-group">
                        <label>Tiene PPPoE</label>
                        <select name="has_pppoe" class="form-control">
                            <option value="">Indiferente</option>
                            <option value="si" @selected(($filtros['has_pppoe'] ?? '') === 'si')>Sí</option>
                            <option value="no" @selected(($filtros['has_pppoe'] ?? '') === 'no')>No</option>
                        </select>
                    </div>

                    <div class="col-md-1 form-group">
                        <label>Permanencia</label>
                        <select name="permanence_clause" class="form-control">
                            <option value="">—</option>
                            <option value="1" @selected(($filtros['permanence_clause'] ?? '') === '1')>Sí</option>
                            <option value="0" @selected(($filtros['permanence_clause'] ?? '') === '0')>No</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="{{ route('contracts.index') }}" class="btn btn-secondary">
                        <i class="fas fa-eraser"></i> Limpiar
                    </a>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-dark" data-toggle="modal" data-target="#modalColumnas">
                        <i class="fas fa-columns"></i> Columnas
                    </button>
                    {{-- Exporta lo filtrado, con las columnas activas.
                         El formulario se reenvía a la ruta de descarga
                         para no repetir aquí todos los parámetros. --}}
                    <button type="button" class="btn btn-success" id="btnExportar">
                        <i class="fas fa-file-excel"></i> Exportar lo filtrado
                    </button>
                </div>
            </div>
        </div>

        {{-- Las columnas activas viajan con el formulario --}}
        <div id="columnasOcultas"></div>
    </form>

    {{-- ---------- Resumen de lo filtrado ---------- --}}
    <div class="row">
        <div class="col-6 col-md-3">
            <div class="info-box shadow-sm">
                <span class="info-box-icon bg-info"><i class="fas fa-file-contract"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Contratos</span>
                    <span class="info-box-number">{{ $contracts->count() }}</span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="info-box shadow-sm">
                <span class="info-box-icon bg-danger"><i class="fas fa-wallet"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Saldo pendiente</span>
                    <span class="info-box-number">${{ number_format($totalSaldo, 0, ',', '.') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Tabla
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="contractsTable" class="table table-hover table-sm" style="width:100%">
                    <thead class="thead-light">
                    <tr>
                        @foreach($columnasActivas as $clave)
                            <th>{{ $columnas[$clave]['titulo'] }}</th>
                        @endforeach
                        <th class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($contracts as $contract)
                        <tr>
                            @foreach($columnasActivas as $clave)
                                <td>
                                    @switch($clave)
                                        @case('contract_number')
                                            <a href="{{ route('contracts.show', $contract) }}">
                                                <strong>{{ $contract->numero_visible }}</strong>
                                            </a>
                                            @break

                                        @case('status')
                                            <span class="badge badge-{{
                                                str_contains(strtolower($contract->status ?? ''), 'activo') ? 'success'
                                                : (str_contains(strtolower($contract->status ?? ''), 'suspend') ? 'danger'
                                                : (str_contains(strtolower($contract->status ?? ''), 'reconex') ? 'info' : 'warning'))
                                            }}">{{ $contract->status }}</span>
                                            @break

                                        @case('saldo_pendiente')
                                            <span class="{{ (float) ($contract->saldo_pendiente ?? 0) > 0 ? 'text-danger font-weight-bold' : 'text-muted' }}">
                                                ${{ number_format((float) ($contract->saldo_pendiente ?? 0), 0, ',', '.') }}
                                            </span>
                                            @break

                                        @case('facturas_pendientes')
                                            @if(($contract->facturas_pendientes ?? 0) > 0)
                                                <span class="badge badge-{{ $contract->facturas_pendientes >= 2 ? 'danger' : 'warning' }}">
                                                    {{ $contract->facturas_pendientes }}
                                                </span>
                                            @else
                                                <span class="text-muted">0</span>
                                            @endif
                                            @break

                                        @default
                                            {{ \App\Services\ContractQuery::valor($contract, $clave) ?: '—' }}
                                    @endswitch
                                </td>
                            @endforeach

                            <td class="text-center text-nowrap">
                                <a href="{{ route('contracts.show', $contract) }}"
                                   class="btn btn-sm btn-info" title="Ver contrato">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Selector de columnas
         ============================================================ --}}
    <div class="modal fade" id="modalColumnas" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-columns mr-1"></i> Columnas del listado</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">
                        Marque lo que quiere ver. La selección se recuerda en este navegador
                        y es la que se lleva la exportación.
                    </p>

                    @php
                        // Agrupadas para que la lista sea legible: son
                        // treinta columnas y en una sola fila no se
                        // encuentra nada.
                        //
                        // preserveKeys: true es IMPRESCINDIBLE. Sin él
                        // groupBy() descarta las claves y las reemplaza
                        // por 0,1,2… en CADA grupo: los id quedaban
                        // repetidos entre grupos (col_0 en Cliente y
                        // col_0 en Ubicación), así que al pulsar una
                        // casilla el navegador marcaba la primera con
                        // ese id — otra distinta. Y el valor enviado
                        // era "0" en vez del nombre de la columna, con
                        // lo que la selección se descartaba entera.
                        $porGrupo = collect($columnas)->groupBy('grupo', preserveKeys: true);
                    @endphp

                    @foreach($porGrupo as $grupo => $delGrupo)
                        <h6 class="text-uppercase text-muted mt-3">{{ $grupo }}</h6>
                        <div class="row">
                            @foreach($delGrupo as $clave => $columna)
                                <div class="col-md-4">
                                    <div class="custom-control custom-checkbox mb-1">
                                        <input type="checkbox" class="custom-control-input col-toggle"
                                               id="col_{{ $clave }}" value="{{ $clave }}"
                                               @checked(in_array($clave, $columnasActivas))>
                                        <label class="custom-control-label" for="col_{{ $clave }}">
                                            {{ $columna['titulo'] }}
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" id="btnColumnasDefecto">
                        Volver a las de siempre
                    </button>
                    <button type="button" class="btn btn-primary" id="btnAplicarColumnas">
                        <i class="fas fa-check"></i> Aplicar
                    </button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        /* Los multiselect se ven altos por defecto y descuadran la fila */
        .select2-container { width: 100% !important; }
        #contractsTable td, #contractsTable th { font-size: .875rem; }
    </style>
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(function () {
            const CLAVE_COLUMNAS = 'gestisp.contratos.columnas';

            $('.select-multiple').select2({
                placeholder: 'Todos',
                allowClear: true,
                closeOnSelect: false,
            });

            $('#contractsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Ningún contrato coincide con los filtros.'
                },
                pageLength: 50,
                order: [],
                columnDefs: [
                    { orderable: false, targets: -1 },
                    { defaultContent: '—', targets: '_all' }
                ]
            });

            /* ============================================================
               Columnas

               La selección se guarda en el navegador y se reenvía en
               cada filtrado. Quien saca listados de cartera todos los
               días no debería reconfigurarlas cada mañana.
               ============================================================ */
            function columnasElegidas() {
                return $('.col-toggle:checked').map((i, el) => el.value).get();
            }

            function escribirOcultas(columnas) {
                const $destino = $('#columnasOcultas').empty();

                columnas.forEach(function (clave) {
                    $destino.append(
                        $('<input>', { type: 'hidden', name: 'columnas[]', value: clave })
                    );
                });
            }

            $('#btnAplicarColumnas').on('click', function () {
                const elegidas = columnasElegidas();

                if (elegidas.length === 0) {
                    // Una tabla sin columnas no le sirve a nadie
                    alert('Deje al menos una columna marcada.');
                    return;
                }

                localStorage.setItem(CLAVE_COLUMNAS, JSON.stringify(elegidas));
                escribirOcultas(elegidas);

                $('#formFiltros').submit();
            });

            $('#btnColumnasDefecto').on('click', function () {
                localStorage.removeItem(CLAVE_COLUMNAS);
                window.location = "{{ route('contracts.index') }}";
            });

            // Al cargar: si el navegador recuerda una selección y la URL
            // no trae otra, se reaplica.
            const guardadas = localStorage.getItem(CLAVE_COLUMNAS);
            const urlTraeColumnas = window.location.search.includes('columnas');

            if (guardadas && !urlTraeColumnas) {
                const columnas = JSON.parse(guardadas);

                escribirOcultas(columnas);

                // Solo se recarga si de verdad cambian: si no, cada
                // visita al listado provocaría una recarga en bucle.
                const actuales = @json($columnasActivas);
                const iguales = columnas.length === actuales.length
                    && columnas.every(c => actuales.includes(c));

                if (!iguales) {
                    $('#formFiltros').submit();
                }
            } else {
                escribirOcultas(@json($columnasActivas));
            }

            /* ---------------- Exportar ---------------- */
            $('#btnExportar').on('click', function () {
                const $form = $('#formFiltros');
                const accionOriginal = $form.attr('action');

                // Se reenvía el MISMO formulario a la ruta de descarga:
                // así el Excel recibe idénticos filtros y columnas sin
                // duplicar aquí la lista de parámetros.
                $form.attr('action', "{{ route('contracts.export_filtered') }}").submit();
                $form.attr('action', accionOriginal);
            });
        });
    </script>
@endsection
