@extends('adminlte::page')

@section('title', 'Mis Órdenes Técnicas')

@section('content_header')
    <div class="card p-3">
        <h2>ÓRDENES TÉCNICAS DE {{ strtoupper(Auth::user()->name . ' ' . Auth::user()->last_name) }}</h2>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Órdenes asignadas al técnico autenticado.
         Desde aquí puede procesarlas (ir al detalle) o rechazarlas
         indicando el motivo.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="myOrdersTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th># Orden</th>
                        <th># Contrato</th>
                        <th>Cliente</th>
                        <th>Dirección</th>
                        <th>Tipo</th>
                        <th>Detalle</th>
                        <th>Comentario inicial</th>
                        <th>Creación</th>
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
                            <td>{{ $technical_order->contract->address }}</td>
                            <td>{{ $technical_order->type }}</td>
                            <td>{{ $technical_order->detail }}</td>
                            <td>{{ $technical_order->initial_comment ?? '—' }}</td>
                            <td>{{ $technical_order->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-nowrap">
                                {{-- Procesar la orden --}}
                                <a href="{{ route('technicals_orders.show', $technical_order->id) }}"
                                   title="Ver y procesar" class="btn btn-sm btn-success">
                                    <i class="fas fa-cogs"></i>
                                </a>

                                {{-- Rechazar la orden (con motivo) --}}
                                <button class="btn btn-sm btn-danger" title="Rechazar orden"
                                        data-toggle="modal"
                                        data-target="#rejectOrderModal{{ $technical_order->id }}">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modales de rechazo (fuera de la tabla por DataTables) --}}
    @foreach($technical_orders as $technical_order)
        <div class="modal fade" id="rejectOrderModal{{ $technical_order->id }}" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Rechazar orden {{ $technical_order->id }}</h5>
                        {{-- Sintaxis Bootstrap 4: la versión anterior usaba
                             btn-close/data-bs-dismiss (Bootstrap 5) y el
                             botón no cerraba el modal --}}
                        <button type="button" class="close text-white" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form action="{{ route('technical_orders.reject', $technical_order) }}" method="post">
                            @csrf
                            @method('put')
                            <label for="reason_{{ $technical_order->id }}">Motivo del rechazo de la orden</label>
                            <textarea name="reason" id="reason_{{ $technical_order->id }}"
                                      class="form-control" required></textarea>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-danger">Rechazar orden</button>
                            </div>
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
    {{-- Sin jQuery ni Bootstrap adicionales: AdminLTE ya los incluye.
         La versión anterior cargaba jQuery 3.6 + Bootstrap 5.3, lo que
         reinicializaba $ y mezclaba dos sistemas de modales. --}}
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#myOrdersTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No tienes órdenes asignadas.'
                },
                pageLength: 25,
                order: [[7, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [8] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });
    </script>
@endsection
