@extends('adminlte::page')

@section('title', 'Pagos')

@section('content_header')
    <div class="card p-3">
        <h2>HISTORIAL DE MOVIMIENTOS DE CAJA</h2>
    </div>
@endsection

@section('content')
    <!-- Formulario para filtrar -->
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('transactions.history') }}">
                <div class="row align-items-center">
                    <!-- Select para el campo a buscar -->
                    <div class="col-md-2">
                        <label for="start_date" class="form-label">Criterio</label>
                        <select id="filterField" class="form-control" name="filter_field">
                            <option value="transaction_type" {{ request('filter_field') == 'transaction_type' ? 'selected' : '' }}>Tipo de transacción</option>
                            <option value="payment_method" {{ request('filter_field') == 'payment_method' ? 'selected' : '' }}>Medio de pago</option>
                            <option value="amount" {{ request('filter_field') == 'amount' ? 'selected' : '' }}>Monto</option>
                        </select>
                    </div>

                    <!-- Input dinámico -->
                    <div class="col-md-2 mt-1 mb-1">
                        <label for="start_date" class="form-label">Valor</label>
                        <input
                            type="text"
                            id="filterInput"
                            name="filter_value"
                            class="form-control"
                            placeholder="Ingrese un valor"
                            value="{{ request('filter_value') }}">
                    </div>

                    <!-- Filtros por rango de fechas -->
                    <div class="col-md-1 mt-1 mb-1">
                        <label for="start_date" class="form-label">Fecha Inicial</label>
                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            class="form-control"
                            value="{{ request('start_date') }}">
                    </div>

                    <div class="col-md-1 mt-1 mb-1">
                        <label for="end_date" class="form-label">Fecha Final</label>
                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            class="form-control"
                            value="{{ request('end_date') }}">
                    </div>

                    <!--Formulario de paginación-->
                    <div class="col-md-2 mt-1 mb-1">
                        <label for="start_date" class="form-label">Cantidad de resultados</label>
                        {{-- SIN <form> PROPIO. Aquí había un formulario dentro
                             del formulario de filtros, y HTML no los admite
                             anidados: el navegador descartaba la etiqueta de
                             apertura y el </form> de este cerraba el DE FUERA.
                             El botón «Filtrar», que viene después, quedaba
                             fuera de todo formulario y no hacía nada; solo se
                             filtraba al cambiar este desplegable.

                             El select pertenece al formulario de filtros sin
                             más, y `this.form.submit()` lo envía con el resto.

                             De paso, las opciones mandaban valores cruzados:
                             decía 12 y enviaba 8, decía 50 y enviaba 25. --}}
                        <select name="per_page" id="per_page" class="form-control mr-2" onchange="this.form.submit()">
                            <option value="">Resultados por página</option>
                            @foreach([12, 15, 25, 50] as $cuantos)
                                <option value="{{ $cuantos }}" @selected((int) request('per_page') === $cuantos)>{{ $cuantos }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Botones -->
                    <div class="col-md-4 text-center text-md-right">
                        <button type="submit" class="btn btn-primary" title="Aplicar filtro">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <a href="{{ route('transactions.history') }}" class="btn btn-secondary" title="Limpiar filtros">
                            <i class="fas fa-times"></i> Limpiar
                        </a>

                        <a href="{{ route('transactions.export-excel') }}" class="btn btn-success" title="Exportar todos los movimientos a Excel">
                            <i class="fas fa-file-excel"></i>
                        </a>
                        <a href="{{ route('transactions.export', [
                            'filter_field' => request('filter_field'),
                            'filter_value' => request('filter_value'),
                            'start_date' => request('start_date'),
                            'end_date' => request('end_date'),
                        ]) }}" class="btn btn-danger" title="Reporte en PDF">
                            <i class="far fa-file-pdf"></i>
                        </a>

                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover">
                <tbody>
                <tr>
                    <th>ID</th>
                    <th>Tipo de transacción</th>
                    <th>Monto</th>
                    <th>Medio de pago</th>
                    <th>Fecha</th>
                    <th>Usuario</th>
                </tr>
                @foreach($transactions as $transaction)
                    <tr>
                        <td>{{ $transaction->id }}</td>
                        <td>{{ $transaction->transaction_type }}</td>
                        <td>{{ number_format($transaction->amount, 2) }}</td>
                        <td>{{ $transaction->payment_method }}</td>
                        <td>{{ $transaction->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $transaction->user->name ?? 'N/A' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="text-center">
            {{ $transactions->links() }}
        </div>
    </div>
@endsection

@section('js')

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const filterField = document.getElementById('filterField');
            const filterInput = document.getElementById('filterInput');

            filterField.addEventListener('change', () => {
                const selectedField = filterField.value;

                // Cambia el placeholder o tipo del input según el campo seleccionado
                switch (selectedField) {
                    case 'transaction_type':
                        filterInput.placeholder = 'Tipo de transacción';
                        filterInput.type = 'text';
                        break;
                    case 'payment_method':
                        filterInput.placeholder = 'Medio de pago';
                        filterInput.type = 'text';
                        break;
                    case 'amount':
                        filterInput.placeholder = 'Monto';
                        filterInput.type = 'number';
                        break;
                    default:
                        filterInput.placeholder = 'Ingrese un valor';
                        filterInput.type = 'text';
                }
            });
        });
    </script>
@endsection
