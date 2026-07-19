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
                <div class="col-md-8">
                    <h2 class="ml-2 P3">LISTADO DE FACTURAS</h2>
                    @if($totalPendding > 0)
                        <div class="ml-2">
                            <span class="badge badge-warning badge-lg">
                                Total Pendiente: ${{ number_format($totalPendding, 2) }}
                            </span>
                        </div>
                    @endif
                </div>
                <div class="col-md-2 text-center text-md-right mb-2">
                    <form action="{{ route('invoices.generate') }}" method="POST" onsubmit="return confirm('¿Estás seguro de que deseas generar las facturas?');">
                        @csrf
                        <button type="submit" class="btn btn-primary col-8">Generar Facturas</button>
                    </form>
                </div>

                <div class="col-md-2 text-center text-md-left">
                    <a href="{{ route('invoices.generate_max_pdf') }}" id="generatePdfButton" class="btn btn-danger col-8" title="Generar PDF de facturas pendientes">
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

        <div class="card-body">
            <div class="table-responsive">
                <table id="invoicesTable" class="table table-hover table-striped">
                    <thead>
                    <tr>
                        <th>ID</th>
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
                            {{-- Número formal (prefijo-consecutivo); las facturas
                                 históricas sin numerar muestran su id --}}
                            <td>{{ $invoice->displayNumber() }}</td>
                            <td>{{ $invoice->contract->client->name }} {{ $invoice->contract->client->last_name }}</td>
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
                    <embed id="pdfViewer" src="" width="100%" height="500px" type="application/pdf">
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
                "order": [[0, "desc"]], // Ordenar por ID descendente por defecto

                // Configuración de columnas
                "columnDefs": [
                    {
                        "targets": [5], // Columna Total
                        "type": "num-fmt", // Para ordenamiento numérico
                        "className": "text-right"
                    },
                    {
                        "targets": [7], // Columna Acciones
                        "orderable": false,
                        "searchable": false,
                        "className": "text-center"
                    },
                    {
                        "targets": [3, 4], // Columnas de fechas
                        "type": "date",
                        "className": "text-center"
                    },
                    {
                        "targets": [6], // Columna Estado
                        "className": "text-center"
                    }
                ],

                // Botones de exportación
                "dom": 'Bfrtip',
                "buttons": [
                    {
                        extend: 'copy',
                        text: '<i class="fas fa-copy"></i> Copiar',
                        className: 'btn btn-secondary btn-sm'
                    },
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file-csv"></i> CSV',
                        className: 'btn btn-success btn-sm'
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm'
                    },
                    {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        orientation: 'landscape',
                        pageSize: 'A4'
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Imprimir',
                        className: 'btn btn-info btn-sm'
                    }
                ]
            });

            // Funcionalidad existente para generar facturas
            $('form').on('submit', function() {
                const button = this.querySelector('button[type="submit"]');
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando...';
            });

            // Funcionalidad existente para PDF masivo
            $('#generatePdfButton').on('click', function(e) {
                e.preventDefault();

                // Cambiar el botón para mostrar que está procesando
                const originalText = $(this).html();
                $(this).html('<i class="fas fa-spinner fa-spin"></i> Generando PDF...');
                $(this).addClass('disabled');

                console.log("Iniciando proceso de generación de PDF...");

                $.ajax({
                    url: $(this).attr('href'),
                    method: 'GET',
                    success: function(response) {
                        console.log("PDF generado. Iniciando verificación del estado...");
                        checkPdfStatus();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al generar el PDF:', error);

                        // Restaurar el botón
                        $('#generatePdfButton').html(originalText);
                        $('#generatePdfButton').removeClass('disabled');

                        // Mostrar error
                        alert('Error al generar el PDF. Por favor, inténtelo de nuevo.');
                    }
                });
            });

            // Función para verificar el estado del PDF
            function checkPdfStatus() {
                console.log("Realizando solicitud AJAX para verificar el estado del PDF...");

                $.ajax({
                    url: "{{ route('invoices.check-pdf-status') }}",
                    method: 'GET',
                    success: function(response) {
                        console.log("Respuesta recibida:", response);

                        if (response.pdfPath) {
                            console.log("PDF listo. Ruta del PDF:", response.pdfPath);

                            // Restaurar el botón
                            $('#generatePdfButton').html('Generar PDF <i class="far fa-file-pdf"></i>');
                            $('#generatePdfButton').removeClass('disabled');

                            // Mostrar el modal con el PDF
                            $('#pdfViewer').attr('src', response.pdfPath + '?t=' + response.timestamp);
                            $('#downloadLink').attr('href', response.pdfPath + '?t=' + response.timestamp);
                            $('#pdfModal').modal({
                                show: true,
                                backdrop: 'static'
                            });

                            console.log("Modal disparado correctamente.");
                        } else {
                            console.log("PDF no está listo. Reintentando en 5 segundos...");
                            setTimeout(checkPdfStatus, 5000);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al verificar el estado del PDF:', error);
                        setTimeout(checkPdfStatus, 5000);
                    }
                });
            }
        });
    </script>
@endsection



