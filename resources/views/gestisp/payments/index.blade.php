@extends('adminlte::page')

@section('title', 'Pagos')

@section('content_header')
    <div class="card p-3">
        <h2>HISTORIAL DE PAGOS</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Formulario de filtros

         Los filtros se aplican en el SERVIDOR (rango de fechas y
         búsqueda por cliente) y son los mismos que usan los
         reportes PDF/Excel. La búsqueda rápida dentro de los
         resultados visibles la aporta DataTables en el navegador.
         ============================================================ --}}
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('payments.index') }}">
                <div class="row align-items-end">
                    {{-- Campo por el que se busca --}}
                    <div class="col-md-2">
                        <label for="filterField" class="form-label">Criterio</label>
                        <select id="filterField" class="form-control" name="filter_field">
                            <option value="client.identity_number" {{ request('filter_field') == 'client.identity_number' ? 'selected' : '' }}>
                                Número de identidad
                            </option>
                            <option value="client.name" {{ request('filter_field') == 'client.name' ? 'selected' : '' }}>
                                Nombre
                            </option>
                            <option value="client.last_name" {{ request('filter_field') == 'client.last_name' ? 'selected' : '' }}>
                                Apellido
                            </option>
                        </select>
                    </div>

                    {{-- Valor a buscar (el placeholder cambia según el criterio) --}}
                    <div class="col-md-2 mt-1 mb-1">
                        <label for="filterInput" class="form-label">Valor</label>
                        <input
                            type="text"
                            id="filterInput"
                            name="filter_value"
                            class="form-control"
                            placeholder="Ingrese un valor"
                            value="{{ request('filter_value') }}">
                    </div>

                    {{-- Rango de fechas de pago --}}
                    <div class="col-md-2 mt-1 mb-1">
                        <label for="start_date" class="form-label">Fecha Inicial</label>
                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            class="form-control"
                            value="{{ request('start_date') }}">
                    </div>

                    <div class="col-md-2 mt-1 mb-1">
                        <label for="end_date" class="form-label">Fecha Final</label>
                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            class="form-control"
                            value="{{ request('end_date') }}">
                    </div>

                    {{-- Acciones: filtrar, limpiar y exportar --}}
                    <div class="col-md-4 text-center text-md-right mt-1 mb-1">
                        <button type="submit" class="btn btn-primary" title="Aplicar filtro">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>

                        <a href="{{ route('payments.index') }}" class="btn btn-secondary" title="Limpiar filtros">
                            <i class="fas fa-times"></i> Limpiar
                        </a>

                        {{-- Exportar todo a Excel --}}
                        <a href="{{ route('payments.export-excel') }}" class="btn btn-success"
                           title="Exportar todos los pagos a Excel">
                            <i class="fas fa-file-excel"></i>
                        </a>

                        {{-- Reporte PDF con los filtros actuales --}}
                        <a href="{{ route('payments.export', [
                            'filter_field' => request('filter_field'),
                            'filter_value' => request('filter_value'),
                            'start_date'   => request('start_date'),
                            'end_date'     => request('end_date'),
                        ]) }}" class="btn btn-danger" title="Reporte en PDF">
                            <i class="far fa-file-pdf"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Aviso cuando se está mostrando el rango por defecto --}}
    @if($usingDefaultRange)
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Mostrando los pagos del <strong>mes actual</strong>.
            Use el filtro de fechas para consultar otros períodos.
        </div>
    @endif

    {{-- ============================================================
         Tabla de pagos (DataTables)

         Búsqueda rápida, ordenamiento y paginación corren en el
         navegador sobre los resultados ya filtrados por el servidor.
         Las relaciones vienen precargadas con with() desde el
         controlador para evitar consultas N+1.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="paymentsTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Sucursal</th>
                        <th>Grupo</th>
                        <th>ID</th>
                        <th>Identidad cliente</th>
                        <th>Cliente</th>
                        <th>N.º contrato</th>
                        <th>Monto</th>
                        <th>Método</th>
                        <th>Fecha de pago</th>
                        <th>Cobrado por</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($payments as $payment)
                        @php
                            // Un ANTICIPO no tiene factura: su contrato
                            // cuelga directamente del pago.
                            $contrato = $payment->invoice?->contract ?? $payment->contract;
                            $cliente = $contrato?->client;
                        @endphp
                        <tr>
                            {{-- Solo se ve en panel consolidado; DataTables la
                                 oculta en los demas modos (ver el columnDef de
                                 mas abajo). Se pinta siempre para que los
                                 indices de columna no cambien segun el modo. --}}
                            <td>{{ $payment->invoice?->contract?->branch?->name ?? $payment->contract?->branch?->name ?: '—' }}</td>
                            {{-- El grupo del contrato que se esta pagando. Los
                                 anticipos cuelgan del contrato directamente y
                                 los demas pagos de la factura: de ahi los dos
                                 caminos, los mismos que usa el filtro. --}}
                            <td>{{ $payment->invoice?->contract?->affinityGroup?->etiqueta()
                                    ?? $payment->contract?->affinityGroup?->etiqueta() ?: '—' }}</td>
                            <td>{{ $payment->id }}</td>
                            <td>{{ $cliente->identity_number ?? '—' }}</td>
                            <td>
                                {{ $cliente->name ?? '—' }}
                                {{ $cliente->last_name ?? '' }}
                            </td>

                            {{-- Número de contrato, no el id interno --}}
                            <td>{{ $contrato->numero_visible ?? '—' }}</td>

                            {{-- Monto con formato de moneda --}}
                            <td>
                                ${{ number_format($payment->amount, 0, ',', '.') }}
                                @if($payment->type === 'anticipo')
                                    <span class="badge badge-info">Anticipo</span>
                                @endif
                            </td>

                            <td>{{ $payment->payment_method }}</td>

                            {{-- Fecha en formato legible --}}
                            <td>{{ $payment->payment_date->format('Y-m-d') }}</td>

                            <td>
                                {{ $payment->user->name ?? '—' }}
                                {{ $payment->user->last_name ?? '' }}
                            </td>

                            {{-- Reimprimir: el cliente que perdió su
                                 recibo es un caso diario en el mostrador. --}}
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-recibo"
                                        data-url="{{ route('payments.receipt', $payment) }}"
                                        data-pdf="{{ route('payments.receipt.pdf', $payment) }}"
                                        title="Ver el recibo de caja">
                                    <i class="fas fa-receipt"></i>
                                </button>

                                {{-- Reversar: el dinero mal recibido no se
                                     arregla editando el pago, se deshace
                                     entero y queda el rastro. --}}
                                @can('payments.destroy')
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-reversar"
                                            data-info="{{ route('payments.reversionInfo', $payment) }}"
                                            data-url="{{ route('payments.destroy', $payment) }}"
                                            title="Reversar este pago">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    {{-- Total de lo mostrado al pie de la tabla --}}
                    <tfoot>
                    <tr>
                        <th colspan="{{ $mostrarSucursal ? 6 : 5 }}" class="text-right">Total mostrado:</th>
                        <th>${{ number_format($payments->sum('amount'), 0, ',', '.') }}</th>
                        <th colspan="4"></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Reversión de un pago.

         Antes de pedir nada se muestra QUÉ va a pasar: cuánto vuelve,
         de qué factura y de qué caja sale, y qué efectos NO se
         deshacen. Si no se puede —la caja ya se cerró— se dice por
         qué y no se deja escribir el motivo.
         ============================================================ --}}
    <div class="modal fade" id="reverseModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-undo mr-1"></i> Reversar el pago</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div id="reverseLoading" class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                        <div class="mt-2">Comprobando qué pasaría...</div>
                    </div>

                    <div id="reverseBody" class="d-none">
                        <dl class="row mb-2" id="reverseDatos"></dl>

                        <div class="alert alert-danger d-none" id="reverseImpedimento"></div>

                        <div id="reverseFormulario">
                            <ul class="list-unstyled small text-muted mb-3" id="reverseAvisos"></ul>

                            <div class="form-group mb-0">
                                <label for="reverseMotivo" class="font-weight-bold">
                                    ¿Por qué se reversa? <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="reverseMotivo" rows="3" maxlength="500"
                                          placeholder="Ej.: se cobró al contrato equivocado; el cliente anuló la transferencia"></textarea>
                                <small class="form-text text-muted">
                                    Queda en la trazabilidad junto con quién lo reversó y cuándo.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="reverseConfirmar" disabled>
                        <i class="fas fa-undo"></i> Reversar el pago
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Recibo de caja: se ve aquí mismo, sin salir del listado.
         Es el mismo modal y la misma tirilla del momento del cobro.
         ============================================================ --}}
    <div class="modal fade" id="receiptModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 420px;">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="fas fa-receipt mr-1"></i> Recibo de caja</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-0">
                    <iframe id="receiptFrame" title="Recibo de caja"
                            style="width: 100%; height: 440px; border: 0;"></iframe>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    <div>
                        <a href="#" class="btn btn-outline-primary" id="receiptDownload" target="_blank">
                            <i class="fas fa-download"></i> Guardar PDF
                        </a>
                        <button type="button" class="btn btn-primary" id="receiptPrint">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

{{-- ============================================================
     Estilos de DataTables (tema Bootstrap 4, compatible con AdminLTE)
     ============================================================ --}}
@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

{{-- ============================================================
     Scripts: DataTables + placeholder dinámico del filtro
     ============================================================ --}}
@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        const CSRF_PAGOS = '{{ csrf_token() }}';

        $(document).ready(function () {
            $('#paymentsTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay pagos en el período consultado.'
                },

                // Registros visibles por página (DataTables agrega su propio
                // selector de cantidad, reemplazando el select per_page anterior)
                pageLength: 25,

                // Orden inicial: por fecha de pago (columna 6) descendente.
                // Se corrió un puesto al agregar la columna de contrato.
                order: [[8, 'desc']],

                columnDefs: [
                    // La sucursal se pinta siempre para que los indices de
                    // columna no cambien con el modo de trabajo; DataTables
                    // la esconde cuando no hay varias sedes que distinguir.
                    { visible: {{ $mostrarSucursal ? 'true' : 'false' }}, targets: 0 },
                    // La columna del recibo son botones: no se ordena
                    { orderable: false, targets: 10 },
                    // Evita el warning de DataTables cuando una celda llega vacía
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        /* ------------------------------------------------------------
           Reimpresión del recibo

           Se imprime el HTML del iframe y no el PDF: la impresora
           térmica corta el papel donde termina el contenido, y el
           navegador le entrega justo ese alto. Un PDF tiene página de
           alto fijo y sacaría papel en blanco.
           ------------------------------------------------------------ */
        $(document).on('click', '.btn-recibo', function () {
            $('#receiptFrame').attr('src', $(this).data('url'));
            $('#receiptDownload').attr('href', $(this).data('pdf'));
            $('#receiptModal').modal('show');
        });

        /* ------------------------------------------------------------
           Reversión de un pago

           Dos pasos a propósito: primero se le pregunta al servidor
           qué pasaría —el estado de la caja puede haber cambiado desde
           que se cargó la pantalla— y solo entonces se habilita el
           botón. El servidor lo vuelve a comprobar todo al reversar:
           esto es comodidad, no seguridad.
           ------------------------------------------------------------ */
        let reverseUrl = null;

        $(document).on('click', '.btn-reversar', function () {
            reverseUrl = $(this).data('url');

            $('#reverseLoading').removeClass('d-none');
            $('#reverseBody').addClass('d-none');
            $('#reverseImpedimento').addClass('d-none').text('');
            $('#reverseFormulario').removeClass('d-none');
            $('#reverseMotivo').val('');
            $('#reverseConfirmar').prop('disabled', true);
            $('#reverseModal').modal('show');

            $.get($(this).data('info'))
                .done(function (d) {
                    const fila = (t, v) => v
                        ? '<dt class="col-5">' + t + '</dt><dd class="col-7">' + v + '</dd>'
                        : '';

                    $('#reverseDatos').html(
                        fila('Pago', '#' + d.pago + ' &middot; ' + d.fecha)
                        + fila('Valor', '<strong class="text-danger">$' + d.monto + '</strong> (' + d.metodo + ')')
                        + fila('Cliente', d.cliente)
                        + fila('Contrato', d.contrato)
                        + fila(d.es_anticipo ? 'Tipo' : 'Factura', d.es_anticipo ? 'Anticipo' : d.factura)
                    );

                    if (d.impedimento) {
                        $('#reverseImpedimento').text(d.impedimento).removeClass('d-none');
                        $('#reverseFormulario').addClass('d-none');
                    } else {
                        $('#reverseAvisos').html(
                            (d.avisos || []).map(function (a) {
                                return '<li><i class="fas fa-info-circle mr-1"></i>' + a + '</li>';
                            }).join('')
                        );
                        $('#reverseConfirmar').prop('disabled', false);
                    }

                    $('#reverseLoading').addClass('d-none');
                    $('#reverseBody').removeClass('d-none');
                })
                .fail(function () {
                    $('#reverseLoading').addClass('d-none');
                    $('#reverseBody').removeClass('d-none');
                    $('#reverseImpedimento')
                        .text('No se pudo consultar el estado de este pago. Intente de nuevo.')
                        .removeClass('d-none');
                    $('#reverseFormulario').addClass('d-none');
                });
        });

        $('#reverseConfirmar').on('click', function () {
            const motivo = $('#reverseMotivo').val().trim();

            if (motivo.length < 5) {
                $('#reverseMotivo').focus();
                return;
            }

            const boton = $(this);
            boton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Reversando...');

            $.ajax({
                url: reverseUrl,
                type: 'POST',
                data: {
                    _method: 'DELETE',
                    _token: $('meta[name="csrf-token"]').attr('content') || CSRF_PAGOS,
                    motivo: motivo
                }
            })
                .done(function () { window.location.reload(); })
                .fail(function (xhr) {
                    const r = xhr.responseJSON || {};
                    $('#reverseImpedimento')
                        .text(r.error
                            || (r.errors && r.errors.motivo ? r.errors.motivo[0] : 'No se pudo reversar el pago.'))
                        .removeClass('d-none');
                    boton.prop('disabled', false).html('<i class="fas fa-undo"></i> Reversar el pago');
                });
        });

        $('#receiptPrint').on('click', function () {
            const marco = document.getElementById('receiptFrame');

            if (marco && marco.contentWindow) {
                marco.contentWindow.focus();
                marco.contentWindow.print();
            }
        });

        /**
         * Cambia el placeholder y tipo del input de valor según
         * el criterio de búsqueda seleccionado.
         */
        document.addEventListener('DOMContentLoaded', () => {
            const filterField = document.getElementById('filterField');
            const filterInput = document.getElementById('filterInput');

            filterField.addEventListener('change', () => {
                switch (filterField.value) {
                    case 'client.identity_number':
                        filterInput.placeholder = 'Número de identidad';
                        filterInput.type = 'number';
                        break;
                    case 'client.name':
                        filterInput.placeholder = 'Nombre del cliente';
                        filterInput.type = 'text';
                        break;
                    case 'client.last_name':
                        filterInput.placeholder = 'Apellido del cliente';
                        filterInput.type = 'text';
                        break;
                    default:
                        filterInput.placeholder = 'Ingrese un valor';
                        filterInput.type = 'text';
                }
            });
        });
    </script>
@endsection
