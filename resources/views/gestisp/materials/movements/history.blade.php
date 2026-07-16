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
         Tabla de movimientos (DataTables)

         Las relaciones vienen precargadas con with() desde el
         controlador para evitar consultas N+1.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="movementsTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Almacén origen</th>
                        <th>Almacén destino</th>
                        <th>Material</th>
                        <th>Cantidad</th>
                        <th>Unidad</th>
                        <th>Serial</th>
                        <th>Motivo</th>
                        <th>Realizado por</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($movements as $movement)
                        <tr>
                            <td>{{ $movement->created_at->format('Y-m-d H:i') }}</td>

                            {{-- Tipo con badge de color según la operación --}}
                            <td>
                                @if($movement->type === 'Entrada')
                                    <span class="badge badge-success">Entrada</span>
                                @elseif($movement->type === 'Salida')
                                    <span class="badge badge-danger">Salida</span>
                                @else
                                    <span class="badge badge-info">Transferencia</span>
                                @endif
                            </td>

                            <td>{{ $movement->warehouseOrigin->description ?? '—' }}</td>
                            <td>{{ $movement->warehouseDestination->description ?? '—' }}</td>
                            <td>{{ $movement->material->name ?? '—' }}</td>
                            <td>{{ $movement->quantity }}</td>
                            <td>{{ $movement->unit_of_measurement }}</td>
                            <td>{{ $movement->serial_number ?? '—' }}</td>
                            <td>{{ $movement->reason }}</td>
                            <td>
                                {{ $movement->user->name ?? '—' }}
                                {{ $movement->user->last_name ?? '' }}
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
                order: [[0, 'desc']],
                columnDefs: [
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
                serial_number:         'Número de serial',
            };

            filterField.addEventListener('change', () => {
                filterInput.placeholder = placeholders[filterField.value] || 'Ingrese un valor';
            });
        });
    </script>
@endsection
