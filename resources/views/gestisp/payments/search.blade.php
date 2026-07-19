@extends('adminlte::page')

@section('title', 'Pagos')

@section('content_header')
    <div class="card p-3">
        <h2>BUSCAR CLIENTE O CONTRATO PARA COBRO</h2>
    </div>
@endsection

@section('content')

    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    {{-- ============================================================
         Estado de la caja del usuario: todo cobro exige caja
         abierta, así que se recuerda aquí ANTES de buscar.
         ============================================================ --}}
    @if($activeCashRegister ?? null)
        <div class="alert alert-success d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-cash-register"></i>
                <strong>Caja #{{ $activeCashRegister->id }} abierta</strong>
                desde {{ $activeCashRegister->opened_at->format('d/m/Y h:i a') }}
                — base inicial ${{ number_format($activeCashRegister->initial_amount, 2) }}
            </span>
            <span class="badge badge-success" style="font-size: 0.95rem;">Listo para cobrar</span>
        </div>
    @else
        <div class="alert alert-danger d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-exclamation-triangle"></i>
                <strong>No tienes una caja abierta.</strong>
                Ningún cobro será aceptado hasta que abras tu caja.
            </span>
            <a href="{{ route('cashRegisters.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-cash-register"></i> Ir a Gestión de caja
            </a>
        </div>
    @endif

    {{-- ============================================================
         Buscador de facturas para cobro

         Búsqueda amplia: por defecto el término se busca en TODOS
         los campos a la vez (cédula, nombre, contrato, número de
         factura, teléfono, usuario PPPoE, dirección); el selector
         permite restringir a un criterio específico. El formulario
         es GET para que la paginación conserve la búsqueda.
         ============================================================ --}}
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('payments.searchView') }}">
                <div class="row align-items-end">
                    <div class="col-md-3 mt-1 mb-1">
                        <label for="search_field" class="mb-1">Buscar por</label>
                        <select name="search_field" id="search_field" class="form-control">
                            <option value="all" {{ request('search_field', 'all') == 'all' ? 'selected' : '' }}>Todos los campos</option>
                            <option value="identity" {{ request('search_field') == 'identity' ? 'selected' : '' }}>Identificación del cliente</option>
                            <option value="name" {{ request('search_field') == 'name' ? 'selected' : '' }}>Nombre del cliente</option>
                            <option value="contract" {{ request('search_field') == 'contract' ? 'selected' : '' }}>Número de contrato</option>
                            <option value="invoice" {{ request('search_field') == 'invoice' ? 'selected' : '' }}>Número de factura</option>
                            <option value="phone" {{ request('search_field') == 'phone' ? 'selected' : '' }}>Teléfono</option>
                            <option value="pppoe" {{ request('search_field') == 'pppoe' ? 'selected' : '' }}>Usuario PPPoE</option>
                            <option value="address" {{ request('search_field') == 'address' ? 'selected' : '' }}>Dirección / barrio</option>
                        </select>
                    </div>
                    <div class="col-md-4 mt-1 mb-1">
                        <label for="search_term" class="mb-1">Criterio</label>
                        <input
                            type="text"
                            id="search_term"
                            name="search_term"
                            class="form-control"
                            placeholder="Cédula, nombre, contrato, factura, teléfono..."
                            value="{{ request('search_term') }}"
                            autofocus>
                    </div>
                    <div class="col-md-2 mt-1 mb-1">
                        <label for="per_page" class="mb-1">Por página</label>
                        <select name="per_page" id="per_page" class="form-control">
                            <option value="8" {{ request('per_page') == 8 ? 'selected' : '' }}>8</option>
                            <option value="15" {{ request('per_page') == 15 ? 'selected' : '' }}>15</option>
                            <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                        </select>
                    </div>
                    <div class="col-md-3 text-center text-md-right mt-1 mb-1">
                        <button type="submit" class="btn btn-primary" title="Buscar">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <a href="{{ route('payments.searchView') }}" class="btn btn-secondary" title="Limpiar búsqueda">
                            <i class="fas fa-eraser"></i>
                        </a>
                    </div>
                </div>
                <small class="form-text text-muted">
                    Ejemplos: <code>12345678</code> (cédula) · <code>Juan Rodríguez</code> (nombre) ·
                    <code>FAC1-25</code> (factura) · <code>312...</code> (teléfono) ·
                    <code>pepito.perez</code> (usuario PPPoE)
                </small>
            </form>
        </div>
    </div>

    @if(isset($invoices) && $invoices->isNotEmpty())
        {{-- Resumen de la búsqueda: cuántas facturas abiertas y
             cuánto suman sus saldos (deuda total encontrada) --}}
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-file-invoice-dollar"></i>
                <strong>{{ $resultCount }}</strong> factura(s) abierta(s) encontrada(s)
            </span>
            <span class="h5 mb-0">
                Deuda total: <strong>${{ number_format($totalBalance, 2) }}</strong>
            </span>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th>Factura</th>
                        <th>Cliente</th>
                        <th>Contrato</th>
                        <th>Período</th>
                        <th>Vencimiento</th>
                        <th>Estado</th>
                        <th>Total</th>
                        <th>Saldo</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($invoices as $invoice)
                        <tr data-invoice-id="{{ $invoice->id }}"
                            class="{{ $invoice->status === \App\Billing\Enums\InvoiceStatus::Vencida->value ? 'table-danger' : '' }}">
                            <td><strong>{{ $invoice->displayNumber() }}</strong></td>
                            <td>
                                {{ $invoice->contract->client->name ?? 'N/A' }} {{ $invoice->contract->client->last_name ?? '' }}
                                <small class="d-block text-muted">CC {{ $invoice->contract->client->identity_number ?? '—' }}</small>
                            </td>
                            <td>#{{ $invoice->contract_id }}</td>
                            <td>{{ $invoice->billed_period_short }} {{ $invoice->billed_month_name }}</td>
                            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td>
                                @switch($invoice->status)
                                    @case(\App\Billing\Enums\InvoiceStatus::Vencida->value)
                                        <span class="badge badge-danger">Vencida</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::PendienteRiesgoCorte->value)
                                        <span class="badge badge-danger">Riesgo de corte</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::PendienteParcial->value)
                                        <span class="badge badge-info">Abonada</span>
                                        @break
                                    @default
                                        <span class="badge badge-warning">{{ $invoice->status }}</span>
                                @endswitch
                            </td>
                            <td>${{ number_format($invoice->total, 2) }}</td>
                            <td class="saldo-cell">${{ number_format($invoice->getPendingAmount(), 2) }}</td>
                            <td>
                                @if($invoice->getPendingAmount() > 0)
                                    <button
                                        class="btn btn-success btn-sm"
                                        data-toggle="modal"
                                        data-target="#paymentModal"
                                        data-invoice-id="{{ $invoice->id }}"
                                        data-invoice-number="{{ $invoice->displayNumber() }}"
                                        data-total="{{ $invoice->total }}"
                                        data-pending="{{ $invoice->getPendingAmount() }}">
                                        <i class="fas fa-hand-holding-usd"></i> Pagar
                                    </button>
                                @else
                                    <span class="badge badge-success">Pagada</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="text-center mt-3">
            {{ $invoices->links() }}
        </div>
    @elseif(request()->filled('search_term'))
        <div class="alert alert-info mt-3">
            No se encontraron facturas abiertas con ese criterio.
            Verifique el término o pruebe con "Todos los campos".
        </div>
    @endif

    <!-- Modal para confirmar pago -->
    <div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-labelledby="paymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <form id="paymentForm" method="POST" action="{{ route('payments.store') }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="paymentModalLabel">Registrar Pago</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="invoice_id" name="invoice_id">
                        <div class="form-group">
                            <label for="amount">Monto a Pagar</label>
                            <input type="number" step="0.01" id="amount" name="amount" class="form-control" required>
                            <small class="text-muted">Pendiente: <span id="pending_amount"></span></small>
                        </div>
                        <div class="form-group">
                            <label for="payment_method">Método de Pago</label>
                            <select id="payment_method" name="payment_method" class="form-control" required>
                                <option value="Efectivo">Efectivo</option>
                                <option value="Tarjeta">Tarjeta</option>
                                <option value="Transferencia">Transferencia</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="reference_number">Número de Referencia</label>
                            <input type="text" id="reference_number" name="reference_number" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="notes">Notas</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Confirmar Pago</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de Éxito de Pago -->
    <div class="modal fade" id="paymentSuccessModal" tabindex="-1" role="dialog" aria-labelledby="paymentSuccessModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="paymentSuccessModalLabel">Pago Registrado con Éxito</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
                        <h4 class="mt-3">¡Pago procesado correctamente!</h4>
                    </div>
                    <div class="payment-details">
                        <p><strong>Monto pagado:</strong> $<span id="successAmount"></span></p>
                        <p><strong>Saldo pendiente:</strong> $<span id="newBalance"></span></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" id="downloadPdf">
                        <i class="fas fa-download"></i> Descargar PDF
                    </button>
                    <button type="button" class="btn btn-info" id="printPdf">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Error de Pago -->
    <div class="modal fade" id="paymentErrorModal" tabindex="-1" role="dialog" aria-labelledby="paymentErrorModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="paymentErrorModalLabel">Error al Procesar el Pago</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-times-circle text-danger" style="font-size: 48px;"></i>
                        <h4 class="mt-3">¡Ocurrió un error!</h4>
                    </div>
                    <div class="alert alert-danger" id="errorMessage"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('js')
    <script>
        // Evento para cargar los datos en el modal de pago
        $('#paymentModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var invoiceId = button.data('invoice-id');
            var invoiceNumber = button.data('invoice-number');
            var total = button.data('total');
            var pending = button.data('pending');

            var modal = $(this);
            modal.find('#paymentModalLabel').text('Registrar Pago — Factura ' + invoiceNumber);
            modal.find('#invoice_id').val(invoiceId);
            modal.find('#amount').attr('max', pending).val(pending);
            modal.find('#pending_amount').text(pending);
        });

        // Manejar el envío del formulario de pago
        $('#paymentForm').on('submit', function(e) {
            e.preventDefault();

            // Mostrar indicador de carga
            $(this).find('button[type="submit"]').prop('disabled', true).html(
                '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Procesando...'
            );

            $.ajax({
                url: $(this).attr('action'),
                method: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    // Cerrar el modal de pago
                    $('#paymentModal').modal('hide');

                    if (response.success) {
                        // Actualizar los detalles en el modal de éxito
                        $('#successAmount').text(response.payment.amount);
                        $('#newBalance').text(response.new_balance);

                        // Guardar la URL del PDF
                        $('#downloadPdf, #printPdf').data('pdf-url', response.pdf_url);

                        // Mostrar el modal de éxito
                        $('#paymentSuccessModal').modal('show');

                        // Actualizar la tabla o recargar según el saldo
                        if (parseFloat(response.new_balance) === 0) {
                            // Recargar la página cuando se cierre el modal
                            $('#paymentSuccessModal').on('hidden.bs.modal', function() {
                                location.reload();
                            });
                        } else {
                            // Actualizar el saldo de la fila (la fila se
                            // identifica por data-invoice-id, no por posición)
                            $('tr[data-invoice-id="' + response.payment.invoice_id + '"] .saldo-cell')
                                .text('$' + response.new_balance);
                        }
                    }
                },
                error: function(xhr) {
                    // Cerrar el modal de pago
                    $('#paymentModal').modal('hide');

                    // Mostrar el mensaje de error
                    var errorMessage = xhr.responseJSON && xhr.responseJSON.error
                        ? xhr.responseJSON.error
                        : 'Ocurrió un error al procesar el pago.';

                    $('#errorMessage').text(errorMessage);
                    $('#paymentErrorModal').modal('show');
                },
                complete: function() {
                    // Restaurar el botón de submit
                    $('#paymentForm').find('button[type="submit"]').prop('disabled', false).html('Confirmar Pago');
                }
            });
        });

        // Manejar la descarga del PDF
        $('#downloadPdf').on('click', function() {
            var pdfUrl = $(this).data('pdf-url');
            window.open(pdfUrl, '_blank');
        });

        // Manejar la impresión del PDF
        $('#printPdf').on('click', function() {
            var pdfUrl = $(this).data('pdf-url');
            var printWindow = window.open(pdfUrl, '_blank');
            printWindow.onload = function() {
                printWindow.print();
            };
        });

        // Limpiar el formulario cuando se cierra el modal
        $('#paymentModal').on('hidden.bs.modal', function () {
            $('#paymentForm')[0].reset();
        });
    </script>
@endsection
