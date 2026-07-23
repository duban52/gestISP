@extends('adminlte::page')

@section('title', 'Órdenes Técnicas')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR ÓRDENES TÉCNICAS</h2>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Filtros de servidor (compartidos con el export a Excel).
         El input de valor cambia dinámicamente según el criterio:
         select con opciones fijas para estado/tipo/detalle/técnico,
         texto libre para cliente.
         ============================================================ --}}
    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('technicals_orders.index') }}">
                <div class="row align-items-end">
                    <div class="col-md-2">
                        <label for="filterField" class="form-label">Criterio</label>
                        <select id="filterField" class="form-control" name="filter_field">
                            <option value="status" {{ request('filter_field') == 'status' ? 'selected' : '' }}>Estado</option>
                            <option value="client" {{ request('filter_field') == 'client' ? 'selected' : '' }}>Cliente</option>
                            <option value="type" {{ request('filter_field') == 'type' ? 'selected' : '' }}>Tipo de orden</option>
                            <option value="detail" {{ request('filter_field') == 'detail' ? 'selected' : '' }}>Detalle de orden</option>
                            <option value="assigned_user" {{ request('filter_field') == 'assigned_user' ? 'selected' : '' }}>Técnico asignado</option>
                        </select>
                    </div>

                    <div class="col-md-2 mt-1 mb-1" id="filterValueContainer">
                        <label class="form-label">Valor</label>
                        <input type="text" id="filterInput" name="filter_value" class="form-control"
                               placeholder="Ingrese un valor" value="{{ request('filter_value') }}">
                    </div>

                    <div class="col-md-2 mt-1 mb-1">
                        <label for="start_date" class="form-label">Fecha Inicial</label>
                        <input type="date" id="start_date" name="start_date" class="form-control"
                               value="{{ request('start_date') }}">
                    </div>

                    <div class="col-md-2 mt-1 mb-1">
                        <label for="end_date" class="form-label">Fecha Final</label>
                        <input type="date" id="end_date" name="end_date" class="form-control"
                               value="{{ request('end_date') }}">
                    </div>

                    <div class="col-md-4 text-center text-md-right mt-1 mb-1">
                        <button type="submit" class="btn btn-primary" title="Aplicar filtro">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <a href="{{ route('technicals_orders.index') }}" class="btn btn-secondary" title="Limpiar filtros">
                            <i class="fas fa-times"></i> Limpiar
                        </a>
                        <a href="{{ route('orders.export', request()->all()) }}" class="btn btn-success"
                           title="Exportar órdenes filtradas a Excel">
                            <i class="fas fa-file-excel"></i> Exportar
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Aviso del comportamiento por defecto: solo órdenes activas --}}
    @if($showingActiveOnly)
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Mostrando las órdenes <strong>activas</strong> (no cerradas).
            Para consultar órdenes cerradas use el filtro de estado o el rango de fechas.
        </div>
    @endif

    {{-- ============================================================
         Tabla de órdenes (DataTables)
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="ordersTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th># Orden</th>
                        <th># Contrato</th>
                        <th>Cliente</th>
                        <th>Tipo</th>
                        <th>Detalle</th>
                        <th>Estado</th>
                        <th>Creación</th>
                        <th>Técnico</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($technical_orders as $technical_order)
                        <tr>
                            <td>{{ $technical_order->id }}</td>
                            <td>{{ $technical_order->contract->id }}</td>
                            <td>
                                {{ $technical_order->contract->client->name }}
                                {{ $technical_order->contract->client->last_name }}
                            </td>
                            <td>{{ $technical_order->type }}</td>
                            <td>{{ $technical_order->detail }}</td>
                            <td>@include('gestisp.technicals_orders.partials.status_badge', ['status' => $technical_order->status])</td>
                            <td>{{ $technical_order->created_at->format('Y-m-d H:i') }}</td>
                            <td>
                                {{ $technical_order->assignedUser->name ?? '—' }}
                                {{ $technical_order->assignedUser->last_name ?? '' }}
                            </td>
                            <td>
                                {{-- Asignar/reasignar según el estado --}}
                                @if($technical_order->status === 'Pendiente')
                                    <button class="btn btn-sm btn-info" data-toggle="modal"
                                            data-target="#assignOrderModal{{ $technical_order->id }}">
                                        Asignar
                                    </button>
                                @elseif(in_array($technical_order->status, ['Asignada', 'Rechazada']))
                                    <button class="btn btn-sm btn-warning" data-toggle="modal"
                                            data-target="#assignOrderModal{{ $technical_order->id }}">
                                        Reasignar
                                    </button>
                                @endif

                                <button type="button" class="btn btn-sm btn-primary" data-toggle="modal"
                                        data-target="#detailModal{{ $technical_order->id }}">
                                    Ver detalles
                                </button>

                                {{-- Comprobante PDF: soporte ante el cliente de la
                                     orden ya cerrada --}}
                                @if($technical_order->status === 'Cerrada')
                                    <a href="{{ route('technicals_orders.pdf', $technical_order->id) }}"
                                       class="btn btn-sm btn-danger" target="_blank" title="Descargar/ver PDF">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modales (fuera de la tabla: DataTables reordena/oculta filas
         y los modales dentro de <td> pueden quedar inaccesibles)
         ============================================================ --}}
    @foreach($technical_orders as $technical_order)
        {{-- Modal de detalles --}}
        <div class="modal fade" id="detailModal{{ $technical_order->id }}" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Detalles de orden {{ $technical_order->id }}</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        @include('gestisp.technicals_orders.partials.order_details', ['technical_order' => $technical_order])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Modal de asignación/reasignación --}}
        <div class="modal fade" id="assignOrderModal{{ $technical_order->id }}" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            {{ $technical_order->status === 'Pendiente' ? 'Asignar Orden' : 'Reasignar Orden' }}
                            #{{ $technical_order->id }}
                        </h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form action="{{ route('technicals_orders.update', $technical_order->id) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <div class="form-group">
                                <label for="assigned_user_id_{{ $technical_order->id }}">Seleccione un técnico:</label>
                                <select name="assigned_user_id" id="assigned_user_id_{{ $technical_order->id }}"
                                        class="form-control" required>
                                    @foreach($users as $user)
                                        <option value="{{ $user->id }}"
                                            {{ $technical_order->user_assigned == $user->id ? 'selected' : '' }}>
                                            {{ $user->name }} {{ $user->last_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                {{ $technical_order->status === 'Pendiente' ? 'Asignar' : 'Reasignar' }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#ordersTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay órdenes técnicas para mostrar.'
                },
                pageLength: 25,
                // Orden inicial: por fecha de creación descendente
                order: [[6, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [8] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        /**
         * Input dinámico del filtro: select con opciones fijas para
         * estado/tipo/detalle/técnico, texto libre para cliente.
         *
         * El valor del técnico es su NOMBRE (no el id): el controlador
         * busca por nombre vía la relación assignedUser.
         */
        document.addEventListener('DOMContentLoaded', () => {
            const filterField          = document.getElementById('filterField');
            const filterValueContainer = document.getElementById('filterValueContainer');
            const users                = @json($users->map(fn($u) => ['name' => $u->name . ' ' . $u->last_name, 'search' => $u->name]));
            const currentField         = @json(request('filter_field'));
            const currentValue         = @json(request('filter_value'));

            const selectOptions = {
                status: ['Pendiente', 'Asignada', 'Rechazada', 'Prefinalizada', 'Cerrada'],
                type:   ['Servicio', 'Incidencia'],
                detail: [
                    'Instalación de servicio', 'Retiro de servicio', 'Corte de servicio',
                    'Traslado de servicio', 'Adición de servicio', 'Reconexión',
                    'Sin servicio de TV', 'Sin servicio de Internet', 'Sin servicio',
                    'Configuraciones', 'Otros'
                ],
            };

            function buildInput() {
                const field = filterField.value;
                filterValueContainer.innerHTML = '';

                const label       = document.createElement('label');
                label.className   = 'form-label';
                label.textContent = 'Valor';
                filterValueContainer.appendChild(label);

                let input;

                if (selectOptions[field]) {
                    // Select con opciones predefinidas
                    input = document.createElement('select');
                    selectOptions[field].forEach(opt => {
                        const o = document.createElement('option');
                        o.value = opt;
                        o.textContent = opt;
                        input.appendChild(o);
                    });
                } else if (field === 'assigned_user') {
                    // Select de técnicos (el valor es el nombre)
                    input = document.createElement('select');
                    users.forEach(u => {
                        const o = document.createElement('option');
                        o.value = u.search;
                        o.textContent = u.name;
                        input.appendChild(o);
                    });
                } else {
                    // Texto libre (cliente: nombre, apellido o identidad)
                    input = document.createElement('input');
                    input.type = 'text';
                    input.placeholder = 'Nombre, apellido o identidad';
                }

                input.id        = 'filterInput';
                input.name      = 'filter_value';
                input.className = 'form-control';

                // Conservar el valor cuando la página carga con un
                // filtro ya aplicado (la versión anterior lo borraba)
                if (field === currentField && currentValue) {
                    input.value = currentValue;
                }

                filterValueContainer.appendChild(input);
            }

            filterField.addEventListener('change', buildInput);
            buildInput();
        });
    </script>
@endsection
