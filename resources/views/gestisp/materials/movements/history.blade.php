@extends('adminlte::page')

@section('title', 'Historial de movimientos')

@section('content_header')
    <div class="card p-3">
        <h2>HISTORIAL DE MOVIMIENTOS DE ALMACÉN</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Formulario de filtros

         Los filtros se aplican en el SERVIDOR y son los mismos que
         usan los reportes PDF/Excel. La búsqueda rápida dentro de
         los resultados visibles la aporta DataTables.
         ============================================================ --}}
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('movements.history') }}">
                <div class="row align-items-end">
                    {{-- Campo por el que se busca --}}
                    <div class="col-md-2">
                        <label for="filterField" class="form-label">Criterio</label>
                        <select id="filterField" class="form-control" name="filter_field">
                            <option value="type" {{ request('filter_field') == 'type' ? 'selected' : '' }}>
                                Tipo de Movimiento
                            </option>
                            <option value="warehouse_origin" {{ request('filter_field') == 'warehouse_origin' ? 'selected' : '' }}>
                                Almacén de Origen
                            </option>
                            <option value="warehouse_destination" {{ request('filter_field') == 'warehouse_destination' ? 'selected' : '' }}>
                                Almacén de destino
                            </option>
                            <option value="material" {{ request('filter_field') == 'material' ? 'selected' : '' }}>
                                Material
                            </option>
                            <option value="supplier" {{ request('filter_field') == 'supplier' ? 'selected' : '' }}>
                                Proveedor
                            </option>
                            <option value="invoice_number" {{ request('filter_field') == 'invoice_number' ? 'selected' : '' }}>
                                Número de factura
                            </option>
                            <option value="serial_number" {{ request('filter_field') == 'serial_number' ? 'selected' : '' }}>
                                Número de serial
                            </option>
                        </select>
                    </div>

                    {{-- Valor a buscar --}}
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

                    {{-- Rango de fechas --}}
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

                        <a href="{{ route('movements.history') }}" class="btn btn-secondary" title="Limpiar filtros">
                            <i class="fas fa-times"></i> Limpiar
                        </a>

                        {{-- Exportar todo a Excel --}}
                        <a href="{{ route('movements.excel') }}" class="btn btn-success"
                           title="Exportar todos los movimientos a Excel">
                            <i class="fas fa-file-excel"></i>
                        </a>

                        {{-- Reporte PDF con los filtros actuales --}}
                        <a href="{{ route('movements.pdf', [
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
            Mostrando los movimientos del <strong>mes actual</strong>.
            Use el filtro de fechas para consultar otros períodos.
        </div>
    @endif

    {{-- ============================================================
         Tabla de OPERACIONES (DataTables)

         Una fila por movimiento, no por renglón: un equipo con serial
         genera un renglón por serial, así que una entrada de mil ONT
         llenaba mil filas y el historial no servía. El detalle —con los
         seriales y su comprobante— está a un clic, en «Ver».
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="movementsTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Sucursal</th>
                        <th>Fecha</th>
                        <th>N.º</th>
                        <th>Tipo</th>
                        <th>Almacén origen</th>
                        <th>Almacén destino</th>
                        <th>Qué se movió</th>
                        <th class="text-right">Unidades</th>
                        <th>Proveedor</th>
                        <th>Factura</th>
                        <th>Realizado por</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($operaciones as $operacion)
                        <tr>
                            {{-- El movimiento no tiene sucursal propia: es la de
                                 sus almacenes. Se mira primero el de origen y,
                                 si es una entrada, el de destino. --}}
                            <td>{{ $operacion->warehouseOrigin?->branch?->name
                                    ?? $operacion->warehouseDestination?->branch?->name
                                    ?: '—' }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($operacion->fecha)->format('Y-m-d H:i') }}</td>
                            <td class="text-monospace">{{ $operacion->operacion }}</td>

                            {{-- Tipo con badge de color según la operación --}}
                            <td>
                                @if($operacion->type === 'Entrada')
                                    <span class="badge badge-success">Entrada</span>
                                @elseif($operacion->type === 'Salida')
                                    <span class="badge badge-danger">Salida</span>
                                @else
                                    <span class="badge badge-info">Transferencia</span>
                                @endif
                            </td>

                            <td>{{ $operacion->warehouseOrigin->description ?? '—' }}</td>
                            <td>{{ $operacion->warehouseDestination->description ?? '—' }}</td>

                            {{-- Qué se movió: los materiales de la operación,
                                 con lo que entró o salió de cada uno. --}}
                            <td>
                                @php
                                    $lineas = $materialesPorOperacion[$operacion->operacion] ?? collect();
                                @endphp
                                @foreach($lineas->take(3) as $linea)
                                    <div>
                                        {{ $linea->material?->name ?? '—' }}:
                                        <strong>{{ rtrim(rtrim(number_format($linea->cantidad, 2, ',', '.'), '0'), ',') }}</strong>
                                        {{ $linea->unit_of_measurement }}
                                        @if($linea->seriales > 0)
                                            <small class="text-muted">({{ $linea->seriales }} con serial)</small>
                                        @endif
                                    </div>
                                @endforeach
                                @if($lineas->count() > 3)
                                    <small class="text-muted">… y {{ $lineas->count() - 3 }} material(es) más</small>
                                @endif
                            </td>

                            <td class="text-right">
                                {{ rtrim(rtrim(number_format($operacion->unidades, 2, ',', '.'), '0'), ',') }}
                                <small class="d-block text-muted">{{ $operacion->renglones }} renglón(es)</small>
                            </td>
                            <td>{{ $operacion->supplier ?: '—' }}</td>
                            <td>
                                {{ $operacion->invoice_number ?: '—' }}
                                @if($operacion->invoice_date)
                                    <small class="d-block text-muted">
                                        {{ \Illuminate\Support\Carbon::parse($operacion->invoice_date)->format('d/m/Y') }}
                                    </small>
                                @endif
                            </td>
                            <td>
                                {{ $operacion->user->name ?? '—' }}
                                {{ $operacion->user->last_name ?? '' }}
                            </td>
                            <td class="text-center">
                                <a href="{{ route('movements.operation', $operacion->operacion) }}"
                                   class="btn btn-sm btn-outline-primary" title="Ver el detalle">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
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
            $('#movementsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay movimientos en el período consultado.'
                },
                pageLength: 25,
                // Orden inicial: por fecha (columna 0) descendente
                order: [[1, 'desc']],
                columnDefs: [
                    // Se pinta siempre para no mover los indices de columna;
                    // DataTables la esconde cuando no hay varias sedes.
                    { visible: {{ $mostrarSucursal ? 'true' : 'false' }}, targets: 0 },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        /**
         * Cambia el placeholder del input de valor según el
         * criterio de búsqueda seleccionado.
         */
        document.addEventListener('DOMContentLoaded', () => {
            const filterField = document.getElementById('filterField');
            const filterInput = document.getElementById('filterInput');

            const placeholders = {
                type:                  'Entrada, Salida o Transferencia',
                warehouse_origin:      'Nombre del almacén de origen',
                warehouse_destination: 'Nombre del almacén de destino',
                material:              'Nombre del material',
                supplier:              'Nombre del proveedor',
                invoice_number:        'Número de la factura del proveedor',
                serial_number:         'Número de serial',
            };

            filterField.addEventListener('change', () => {
                filterInput.placeholder = placeholders[filterField.value] || 'Ingrese un valor';
            });
        });
    </script>
@endsection
