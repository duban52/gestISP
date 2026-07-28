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
                        <th>ID</th>
                        <th>Identidad cliente</th>
                        <th>Cliente</th>
                        <th>N.º contrato</th>
                        <th>Monto</th>
                        <th>Método</th>
                        <th>Fecha de pago</th>
                        <th>Cobrado por</th>
                        <th class="text-center">Recibo</th>
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
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    {{-- Total de lo mostrado al pie de la tabla --}}
                    <tfoot>
                    <tr>
                        <th colspan="4" class="text-right">Total mostrado:</th>
                        <th>${{ number_format($payments->sum('amount'), 0, ',', '.') }}</th>
                        <th colspan="4"></th>
                    </tr>
                    </tfoot>
                </table>
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
                order: [[6, 'desc']],

                columnDefs: [
                    // La columna del recibo son botones: no se ordena
                    { orderable: false, targets: 8 },
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
