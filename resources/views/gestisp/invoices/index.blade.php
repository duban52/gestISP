@extends('adminlte::page')

@section('title', 'Facturas')

{{-- Agregar CSS de DataTables --}}
@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
@endsection

@section('content')
    <div class="card mt-3">
        <div class="card-head pt-3">
            <div class="row d-flex justify-content-between mb-4 pr-3">
                <div class="col-md-6">
                    <h2 class="ml-2 P3">LISTADO DE FACTURAS</h2>
                    {{-- LA ETIQUETA SIGUE AL FILTRO.
                         Suma los saldos de las facturas abiertas
                         —pendientes, parciales, con riesgo y vencidas— de
                         lo que se está viendo. Antes sumaba siempre toda
                         la sucursal, así que al filtrar por grupo decía
                         una cifra que no correspondía a la tabla de
                         abajo.
                         Se pinta aunque sea cero: un grupo sin nada
                         pendiente es un dato, y esconder la etiqueta
                         haría dudar de si el filtro se aplicó. --}}
                    <div class="ml-2">
                        <span class="badge badge-warning badge-lg">
                            Por recaudar: ${{ number_format($totalPendding, 2) }}
                        </span>
                        @if($gruposFiltrados->isNotEmpty())
                            <span class="badge badge-light border">
                                solo {{ $gruposFiltrados->pluck('name')->implode(', ') }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="col-md-2 text-center text-md-right mb-2">
                    <a href="{{ route('invoices.billing_runs') }}" class="btn btn-info col-10" title="Historial de corridas de facturación">
                        Reportes <i class="fas fa-chart-bar"></i>
                    </a>
                </div>
                {{-- ============================================================
                     Sucursal de la corrida y del PDF masivo.

                     Las dos operaciones son DE UNA SUCURSAL: recorren sus
                     contratos, usan su membrete y consumen su consecutivo.
                     En modo independiente la manda la sesión y esto no
                     aparece; en panel consolidado hay que decir cuál.

                     El selector vive FUERA del formulario y se asocia a él
                     con el atributo `form`, porque el mismo valor lo
                     necesitan dos acciones distintas: el POST de la corrida
                     y la petición AJAX del PDF.
                     ============================================================ --}}
                @php
                    $contextoFacturacion = app(\App\Tenancy\CurrentContext::class);
                    $elegirSucursal = $contextoFacturacion->hayQueElegirSucursal();
                @endphp

                @if($elegirSucursal)
                    <div class="col-md-2 mb-2">
                        <label for="branch_id" class="mb-1 small text-muted">
                            Sucursal <span class="text-danger">*</span>
                        </label>
                        <select name="branch_id" id="branch_id" form="formGenerarFacturas"
                                class="form-control form-control-sm" required>
                            <option value="">Seleccione…</option>
                            @foreach($contextoFacturacion->sucursalesElegibles() as $sucursal)
                                <option value="{{ $sucursal->id }}">{{ $sucursal->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="col-md-2 text-center text-md-right mb-2">
                    <form id="formGenerarFacturas" action="{{ route('invoices.generate') }}" method="POST" onsubmit="return confirm('¿Estás seguro de que deseas generar las facturas?');">
                        @csrf
                        <button type="submit" class="btn btn-primary col-10">Generar Facturas</button>
                    </form>
                </div>

                <div class="col-md-2 text-center text-md-left">
                    <a href="{{ route('invoices.generate_max_pdf') }}" id="generatePdfButton" class="btn btn-danger col-10" title="Generar PDF de facturas pendientes">
                        Generar PDF <i class="far fa-file-pdf"></i>
                    </a>
                </div>
            </div>

            @if(session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>
            @endif
        </div>

        {{-- ============================================================
             BUSCADOR

             Este listado no tenia ninguno: se cargaban todas las
             facturas del alcance y se buscaba con la caja de
             DataTables. Eso sirve para buscar un texto, no para
             acotar QUE se carga — y las facturas crecen sin limite.

             Van los dos filtros que la caja de busqueda no puede
             contestar: el grupo, que no es columna en ningun sitio, y
             la sucursal, que en una empresa con varias sedes reduce de
             verdad el volumen.
             ============================================================ --}}
        @if(app(\App\Tenancy\CurrentContext::class)->mostrarSucursal() || \App\Models\AffinityGroup::exists())
            <div class="card-body border-bottom pb-0">
                <form method="GET" action="{{ route('invoices.index') }}">
                    <div class="row align-items-end">
                        <x-filtro-sucursal :filtros="$filtros" clase="col-md-3" />
                        <x-filtro-grupo-afinidad :filtros="$filtros" clase="col-md-3" />

                        <div class="col-md-3 form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter mr-1"></i> Filtrar
                            </button>
                            @if(!empty(array_filter($filtros ?? [])))
                                <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary">
                                    Limpiar
                                </a>
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        @endif

        <div class="card-body">
            <div class="table-responsive">
                <table id="invoicesTable" class="table table-hover table-striped">
                    <thead>
                    <tr>
                        {{-- Selección para el PDF masivo. Va primero porque es
                             donde la gente la busca, y eso corre un puesto
                             TODOS los índices de columnDefs de más abajo: si
                             algún día se añade otra columna, hay que tocarlos
                             igual. --}}
                        <th class="col-seleccion text-center" style="width:32px;">
                            <input type="checkbox" id="marcarTodas"
                                   title="Marcar o desmarcar todo lo que se está viendo">
                        </th>
                        <th>Sucursal</th>
                        <th>Grupo</th>
                        <th>ID</th>
                        <th>N.º contrato</th>
                        <th>Identificación</th>
                        <th>Cliente</th>
                        <th>Período</th>
                        <th>Fecha de emisión</th>
                        <th>Fecha de vencimiento</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($invoices as $invoice)
                        <tr>
                            <td class="col-seleccion text-center">
                                <input type="checkbox" class="marca-factura"
                                       value="{{ $invoice->id }}">
                            </td>
                            {{-- Solo se ve en panel consolidado; DataTables la
                                 oculta en los demas modos (ver el columnDef de
                                 mas abajo). Se pinta siempre para que los
                                 indices de columna no cambien segun el modo. --}}
                            <td>{{ $invoice->branch?->name ?: '—' }}</td>
                            {{-- El grupo del contrato del que sale esta factura.
                                 Es informacion que no esta en ninguna otra
                                 columna: sin ella no hay forma de saber a que
                                 grupo pertenece sin abrir el contrato. --}}
                            <td>{{ $invoice->contract?->affinityGroup?->etiqueta() ?: '—' }}</td>
                            {{-- Número formal (prefijo-consecutivo); las facturas
                                 históricas sin numerar muestran su id --}}
                            <td>{{ $invoice->displayNumber() }}</td>
                            {{-- Número de contrato visible (no el id interno) e
                                 identificación: son los datos por los que se
                                 busca a un cliente en el mostrador --}}
                            <td>{{ $invoice->contract->contract_number ?? '—' }}</td>
                            <td>{{ $invoice->titular()?->identity_number ?? '—' }}</td>
                            <td>{{ trim(($invoice->titular()?->name ?? '') . ' ' . ($invoice->titular()?->last_name ?? '')) ?: '—' }}</td>
                            <td>{{ $invoice->billed_period_short ?? 'N/A' }}</td>
                            <td>{{ \Carbon\Carbon::parse($invoice->issue_date)->format('d/m/Y') }}</td>
                            <td>{{ \Carbon\Carbon::parse($invoice->due_date)->format('d/m/Y') }}</td>
                            <td>${{ number_format($invoice->total, 2) }}</td>
                            <td>
                                {{-- Estados canónicos: App\Billing\Enums\InvoiceStatus.
                                     El @case('pagada') anterior (minúscula) nunca
                                     coincidía con el valor real 'Pagada'. --}}
                                @switch($invoice->status)
                                    @case(\App\Billing\Enums\InvoiceStatus::Pendiente->value)
                                        <span class="badge badge-warning">{{ $invoice->status }}</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::PendienteParcial->value)
                                        <span class="badge badge-warning">{{ $invoice->status }}</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::PendienteRiesgoCorte->value)
                                        <span class="badge badge-danger">Riesgo de Corte</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::Vencida->value)
                                        <span class="badge badge-danger">{{ $invoice->status }}</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::Pagada->value)
                                        <span class="badge badge-success">{{ $invoice->status }}</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::CargadaANuevaFactura->value)
                                        <span class="badge badge-info">Cargada</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::Anulada->value)
                                        <span class="badge badge-dark" title="{{ $invoice->void_reason }}">Anulada</span>
                                        @break
                                    @default
                                        <span class="badge badge-secondary">{{ $invoice->status }}</span>
                                @endswitch
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="{{ route('invoices.show', $invoice->id) }}"
                                       class="btn btn-info btn-sm"
                                       title="Ver factura">
                                        <i class="far fa-eye"></i>
                                    </a>
                                    <a href="{{ route('invoices.download-pdf', $invoice->id) }}"
                                       class="btn btn-danger btn-sm"
                                       title="Descargar PDF"
                                       target="_blank">
                                        <i class="far fa-file-pdf"></i>
                                    </a>

                                    {{-- Anular (abre modal pidiendo motivo). Solo
                                         facturas abiertas sin pagos; el servidor
                                         aplica las reglas completas --}}
                                    @if(in_array($invoice->status, \App\Billing\Enums\InvoiceStatus::payable()))
                                        <button
                                            class="btn btn-secondary btn-sm btn-anular-factura"
                                            data-url="{{ route('invoices.void', $invoice) }}"
                                            data-numero="{{ $invoice->displayNumber() }}"
                                            title="Anular factura">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal para notificar al usuario -->
    {{-- ============================================================
         Qué entra en el PDF masivo

         Antes no se preguntaba: el trabajo tenía los estados fijos a
         «Pendiente» y «riesgo de corte», y dejaba fuera a las
         VENCIDAS. En una cartera con mora —que es toda cartera real—
         eso significaba un PDF casi vacío, sin un solo aviso de por
         qué. Preguntarlo hace visible una decisión que antes estaba
         enterrada en el código.
         ============================================================ --}}
    <div class="modal fade" id="pdfOpcionesModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="far fa-file-pdf mr-1"></i> ¿Qué facturas van en el PDF?
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="pdfModoEstados" name="pdfModo"
                                   class="custom-control-input" value="estados" checked>
                            <label class="custom-control-label" for="pdfModoEstados">
                                Por estado de la factura
                            </label>
                        </div>

                        <div id="pdfEstados" class="pl-4 mb-3">
                            <div class="mb-2">
                                <button type="button" class="btn btn-xs btn-outline-secondary" data-preset="deben">
                                    Las que deben
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-secondary" data-preset="vencidas">
                                    Solo vencidas
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-secondary" data-preset="pendientes">
                                    Solo pendientes
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-secondary" data-preset="todas">
                                    Todos los estados
                                </button>
                            </div>

                            @foreach(\App\Billing\Enums\InvoiceStatus::cases() as $estado)
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input pdf-estado"
                                           id="pdfEstado{{ $loop->index }}"
                                           value="{{ $estado->value }}"
                                           @checked(in_array($estado->value, \App\Billing\Enums\InvoiceStatus::payable(), true))>
                                    <label class="custom-control-label" for="pdfEstado{{ $loop->index }}">
                                        {{ $estado->value }}
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        <div class="custom-control custom-radio">
                            <input type="radio" id="pdfModoSeleccion" name="pdfModo"
                                   class="custom-control-input" value="seleccion">
                            <label class="custom-control-label" for="pdfModoSeleccion">
                                Solo las que marqué en el listado
                                (<span id="pdfCuentaSeleccion">0</span>)
                            </label>
                        </div>
                        <p class="text-muted small pl-4 mb-0">
                            Marque las casillas de la primera columna. El buscador y los
                            filtros de la tabla ayudan a encontrarlas.
                        </p>
                    </div>

                    <div id="pdfSinNada" class="alert alert-warning mb-0" style="display:none;">
                        No ha elegido ninguna factura: el PDF saldría vacío.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="pdfConfirmar">
                        <i class="far fa-file-pdf"></i> Generar PDF
                    </button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="pdfModal" tabindex="-1" role="dialog" aria-labelledby="pdfModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pdfModalLabel">PDF de Facturas Pendientes</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    {{-- Mientras se genera. Con miles de facturas esto son
                         minutos, y una ventana muda durante minutos se
                         interpreta como que el sistema se colgó. --}}
                    <div id="pdfProgreso" style="display:none;">
                        <p id="pdfProgresoTexto" class="mb-2">Encolando la generación…</p>

                        <div class="progress mb-3" style="height: 22px;">
                            <div id="pdfProgresoBarra"
                                 class="progress-bar progress-bar-striped progress-bar-animated"
                                 role="progressbar" style="width: 0%;"
                                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
                        </div>

                        <p class="text-muted small mb-0">
                            <i class="fas fa-info-circle"></i>
                            Puede cerrar esta ventana y seguir trabajando: el PDF se sigue
                            generando en el servidor y recibirá un aviso del navegador cuando
                            esté listo.
                        </p>
                    </div>

                    <div id="pdfVisor" style="display:none;">
                        <embed id="pdfViewer" src="" width="100%" height="500px" type="application/pdf">
                    </div>
                </div>
                <div class="modal-footer">
                    <a id="downloadLink" href="" class="btn btn-success" download>
                        <i class="fas fa-download"></i> Descargar PDF
                    </a>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modal de anulación de factura

         La factura nunca se elimina: cambia a estado Anulada
         registrando usuario, fecha y motivo (obligatorio). El
         servidor rechaza anular facturas con pagos registrados.
         ============================================================ --}}
    <div class="modal fade" id="modalAnularFactura" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Anular Factura</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form id="formAnularFactura" method="POST">
                    @csrf
                    <div class="modal-body">
                        <p>¿Está seguro que desea anular la factura
                            <strong id="anularFacturaNumero"></strong>?</p>

                        <div class="form-group">
                            <label for="void_reason">Motivo de la anulación <span class="text-danger">*</span></label>
                            <textarea name="void_reason" id="void_reason" class="form-control"
                                      maxlength="255" rows="2" required
                                      placeholder="Ej: error en el valor facturado"></textarea>
                        </div>

                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle"></i>
                            La factura no se elimina: queda anulada con su
                            historial completo. Si tiene pagos registrados,
                            la anulación será rechazada.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-dark">Sí, anular</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

{{-- Agregar JavaScript de DataTables --}}
@section('js')
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

    <script>
        /**
         * Abre el modal de anulación cargando el número de la
         * factura y la URL del formulario (generada con route()).
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-anular-factura')) {
                const btn = e.target.closest('.btn-anular-factura');

                document.getElementById('anularFacturaNumero').textContent =
                    btn.getAttribute('data-numero');
                document.getElementById('formAnularFactura').action =
                    btn.getAttribute('data-url');
                document.getElementById('void_reason').value = '';

                $('#modalAnularFactura').modal('show');
            }
        });

        $(document).ready(function() {
            // Inicializar DataTable
            $('#invoicesTable').DataTable({
                // Configuración básica
                "processing": true,
                "responsive": true,
                "autoWidth": false,

                // Configuración de idioma en español
                "language": {
                    "url": "https://cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json"
                },

                // Configuración de paginación
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"]],

                // Configuración de ordenamiento
                "order": [[3, "desc"]], // Ordenar por ID descendente por defecto

                // Configuración de columnas
                // Los índices se corrieron dos posiciones al agregar
                // las columnas de contrato e identificación, y una
                // tercera al agregar la casilla de selección (0).
                "columnDefs": [
                    {
                        // La casilla de selección del PDF: ni se ordena ni se
                        // busca. Sin `searchable:false`, escribir en el
                        // buscador de DataTables comparaba contra el HTML de
                        // la casilla y devolvía filas sin sentido.
                        "targets": [0],
                        "orderable": false,
                        "searchable": false,
                        "className": "col-seleccion text-center"
                    },
                    // La sucursal se pinta siempre para que los indices de
                    // columna no cambien con el modo de trabajo; DataTables
                    // la esconde cuando no hay varias sedes que distinguir.
                    { visible: {{ $mostrarSucursal ? 'true' : 'false' }}, targets: 1 },
                    {
                        "targets": [10], // Columna Total
                        "type": "num-fmt", // Para ordenamiento numérico
                        "className": "text-right"
                    },
                    {
                        "targets": [12], // Columna Acciones
                        "orderable": false,
                        "searchable": false,
                        "className": "text-center"
                    },
                    {
                        "targets": [8, 9], // Columnas de fechas
                        "type": "date",
                        "className": "text-center"
                    },
                    {
                        "targets": [11], // Columna Estado
                        "className": "text-center"
                    }
                ],

                // Botones de exportación
                "dom": 'Bfrtip',
                // La casilla de seleccion no se exporta: en un CSV o un
                // Excel saldria como una columna vacia delante de todo.
                "buttons": [
                    {
                        extend: 'copy',
                        text: '<i class="fas fa-copy"></i> Copiar',
                        className: 'btn btn-secondary btn-sm',
                        exportOptions: { columns: ':not(.col-seleccion)' }
                    },
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file-csv"></i> CSV',
                        className: 'btn btn-success btn-sm',
                        exportOptions: { columns: ':not(.col-seleccion)' }
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        exportOptions: { columns: ':not(.col-seleccion)' }
                    },
                    {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        orientation: 'landscape',
                        pageSize: 'A4',
                        exportOptions: { columns: ':not(.col-seleccion)' }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Imprimir',
                        className: 'btn btn-info btn-sm',
                        exportOptions: { columns: ':not(.col-seleccion)' }
                    }
                ]
            });

            // Funcionalidad existente para generar facturas
            $('form').on('submit', function() {
                const button = this.querySelector('button[type="submit"]');
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando...';
            });

            /* ==========================================================
               PDF masivo de facturas pendientes

               Antes esto encolaba el trabajo y acto seguido preguntaba
               «¿hay algún PDF de esta sucursal?». Como SIEMPRE había
               uno de alguna vez anterior, el visor se abría al instante
               con el documento viejo mientras el nuevo seguía en la
               cola — y con el filtro de sucursal roto, ese documento
               viejo era una página en blanco.

               Ahora se sigue POR ID la generación que se acaba de
               pedir, se enseña su avance, y el encargo queda apuntado
               en localStorage para que el aviso llegue aunque el
               usuario se vaya a otra pantalla (lo sondea el JS global).
               ========================================================== */
            const CLAVE_PDF = 'gestisp_pdf_facturas';
            let sondeoPdf = null;

            function pintarProgresoPdf(estado) {
                $('#pdfProgresoTexto').text(estado.texto);
                $('#pdfProgresoBarra')
                    .css('width', estado.porcentaje + '%')
                    .attr('aria-valuenow', estado.porcentaje)
                    .text(estado.porcentaje + '%');
            }

            function mostrarPdfListo(estado) {
                $('#pdfProgreso').hide();
                $('#pdfVisor').show();
                $('#pdfViewer').attr('src', estado.pdfPath);
                $('#downloadLink').attr('href', estado.pdfPath);
            }

            function mostrarFalloPdf(estado) {
                $('#pdfProgresoTexto').html(
                    '<span class="text-danger"><i class="fas fa-times-circle"></i> ' +
                    $('<div>').text(estado.texto).html() + '</span>'
                );
                $('#pdfProgresoBarra').addClass('bg-danger').css('width', '100%').text('');
            }

            function restaurarBotonPdf() {
                $('#generatePdfButton')
                    .html('Generar PDF <i class="far fa-file-pdf"></i>')
                    .removeClass('disabled');
            }

            function seguirPdf(url) {
                clearInterval(sondeoPdf);

                const mirar = function () {
                    $.getJSON(url)
                        .done(function (estado) {
                            pintarProgresoPdf(estado);

                            if (!estado.terminado) {
                                return;
                            }

                            clearInterval(sondeoPdf);
                            restaurarBotonPdf();

                            if (estado.pdfPath) {
                                mostrarPdfListo(estado);
                            } else {
                                mostrarFalloPdf(estado);
                            }
                        })
                        .fail(function (xhr) {
                            // 403/404: el informe ya no es alcanzable.
                            // Insistir no lo va a arreglar.
                            if (xhr.status === 403 || xhr.status === 404) {
                                clearInterval(sondeoPdf);
                                restaurarBotonPdf();
                                localStorage.removeItem(CLAVE_PDF);
                            }
                        });
                };

                mirar();
                sondeoPdf = setInterval(mirar, 2000);
            }

            /* ----- Selección de filas para el PDF ----- */

            const tabla = $('#invoicesTable').DataTable();

            function facturasMarcadas() {
                // Por la API de DataTables y no por el DOM: las filas de
                // las otras páginas no están en el documento, y
                // $('.marca-factura:checked') solo vería la página actual.
                return tabla.$('.marca-factura:checked').map(function () {
                    return parseInt(this.value, 10);
                }).get();
            }

            function refrescarCuenta() {
                $('#pdfCuentaSeleccion').text(facturasMarcadas().length);
            }

            // Marca lo que se está VIENDO (filtro y búsqueda aplicados),
            // no la tabla entera: si alguien buscó «MAZO» y pulsa la
            // cabecera, quiere esas, no las mil.
            $('#marcarTodas').on('change', function () {
                const marcar = this.checked;

                tabla.rows({ search: 'applied' }).nodes().to$()
                    .find('.marca-factura').prop('checked', marcar);

                refrescarCuenta();
            });

            $('#invoicesTable tbody').on('change', '.marca-factura', refrescarCuenta);

            /* ----- El modal de opciones ----- */

            const PRESETS = {
                deben: @json(\App\Billing\Enums\InvoiceStatus::payable()),
                vencidas: ['{{ \App\Billing\Enums\InvoiceStatus::Vencida->value }}'],
                pendientes: [
                    '{{ \App\Billing\Enums\InvoiceStatus::Pendiente->value }}',
                    '{{ \App\Billing\Enums\InvoiceStatus::PendienteParcial->value }}',
                    '{{ \App\Billing\Enums\InvoiceStatus::PendienteRiesgoCorte->value }}'
                ],
                todas: @json(array_column(\App\Billing\Enums\InvoiceStatus::cases(), 'value'))
            };

            $('[data-preset]').on('click', function () {
                const elegidos = PRESETS[$(this).data('preset')] || [];

                $('.pdf-estado').each(function () {
                    this.checked = elegidos.indexOf(this.value) !== -1;
                });

                $('#pdfModoEstados').prop('checked', true);
            });

            function estadosMarcados() {
                return $('.pdf-estado:checked').map(function () {
                    return this.value;
                }).get();
            }

            // El botón ya no genera: abre la pregunta. Lo que se genera
            // se decide aquí y no en una constante del código.
            $('#generatePdfButton').on('click', function (e) {
                e.preventDefault();
                refrescarCuenta();
                $('#pdfSinNada').hide();
                $('#pdfOpcionesModal').modal('show');
            });

            $('#pdfConfirmar').on('click', function () {
                const porSeleccion = $('#pdfModoSeleccion').is(':checked');
                const ids = porSeleccion ? facturasMarcadas() : [];
                const estados = porSeleccion ? [] : estadosMarcados();

                // Generar un PDF vacío y esperar a que termine para
                // descubrirlo es la peor forma de enterarse.
                if ((porSeleccion && !ids.length) || (!porSeleccion && !estados.length)) {
                    $('#pdfSinNada').show();
                    return;
                }

                $('#pdfOpcionesModal').modal('hide');
                lanzarPdf(estados, ids);
            });

            function lanzarPdf(estados, ids) {
                $('#generatePdfButton')
                    .html('<i class="fas fa-spinner fa-spin"></i> Generando PDF...')
                    .addClass('disabled');

                // El modal se abre YA, con la barra a cero: es lo que
                // convierte una espera muda en una espera con estado.
                $('#pdfVisor').hide();
                $('#pdfProgreso').show();
                $('#pdfProgresoBarra').removeClass('bg-danger').css('width', '0%').text('0%');
                $('#pdfProgresoTexto').text('Encolando la generación…');
                $('#pdfModal').modal('show');

                $.ajax({
                    url: "{{ route('invoices.generate_max_pdf') }}",
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        // La sucursal solo existe en panel consolidado;
                        // si no hay selector, el servidor la deduce.
                        branch_id: $('#branch_id').val() || '',
                        estados: estados,
                        ids: ids
                    }
                })
                    .done(function (res) {
                        // Se apunta para que el JS global lo siga
                        // aunque el usuario se vaya a otra pantalla.
                        localStorage.setItem(CLAVE_PDF, JSON.stringify({
                            id: res.id,
                            url: res.url_estado
                        }));

                        seguirPdf(res.url_estado);
                    })
                    // EL MOTIVO SE ENSEÑA, NO SE TIRA.
                    //
                    // Esto era un `.fail(function () {...})` sin
                    // parametros que pintaba una frase fija: con un 500,
                    // un 419 de sesion caducada o un 422 de validacion
                    // la pantalla decia exactamente lo mismo, y no habia
                    // forma de saber cual de los tres era sin abrir el
                    // log del servidor.
                    .fail(function (xhr) {
                        restaurarBotonPdf();

                        var detalle = 'No se pudo encolar la generación del PDF.';

                        if (xhr.status === 419) {
                            detalle = 'La sesión caducó. Recargue la página y vuelva a intentarlo.';
                        } else if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                            detalle = Object.values(xhr.responseJSON.errors).flat().join(' ');
                        } else if (xhr.responseJSON && xhr.responseJSON.message) {
                            detalle = xhr.responseJSON.message;
                        } else if (xhr.status) {
                            detalle += ' (error ' + xhr.status + ')';
                        }

                        $('#pdfProgresoTexto').html(
                            '<span class="text-danger"><i class="fas fa-times-circle"></i> ' +
                            $('<div>').text(detalle).html() + '</span>'
                        );
                    });
            }

            // Si había una generación en marcha al entrar a esta
            // pantalla, se retoma su seguimiento en vez de empezar otra.
            (function () {
                let encargo = null;

                try {
                    encargo = JSON.parse(localStorage.getItem(CLAVE_PDF));
                } catch (err) {
                    encargo = null;
                }

                if (encargo && encargo.url) {
                    $('#generatePdfButton')
                        .html('<i class="fas fa-spinner fa-spin"></i> Generando PDF...')
                        .addClass('disabled');
                    seguirPdf(encargo.url);
                }
            })();
        });
    </script>
@endsection



