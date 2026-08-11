@extends('adminlte::page')

@section('title', 'Verificar Órdenes Técnicas')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-clipboard-check mr-2"></i>Verificar órdenes</h1>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Órdenes Prefinalizadas pendientes de verificación.
         Desde el modal de cada una, el supervisor revisa el trabajo
         reportado (solución, fotos, materiales) y la cierra o la
         devuelve a Pendiente con su comentario.
         ============================================================ --}}
    <div class="card toque">
        <div class="card-body">
            <div class="table-responsive">
                <table id="verificationTable" class="table table-hover tabla-movil" style="width:100%">
                    <thead>
                    <tr>
                        <th># Orden</th>
                        <th># Contrato</th>
                        <th>Cliente</th>
                        <th>Tipo</th>
                        <th>Detalle</th>
                        <th>Creación</th>
                        <th>Técnico</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($technical_orders as $technical_order)
                        <tr>
                            {{-- Celda principal: encabeza la ficha en el teléfono. --}}
                            <td class="celda-principal" data-label="">
                                <strong>Orden {{ $technical_order->id }}</strong>
                                <span class="badge badge-light border ml-1">{{ $technical_order->type }}</span>
                                <span class="d-block d-md-none text-muted small mt-1">
                                    {{ $technical_order->contract->client->name }}
                                    {{ $technical_order->contract->client->last_name }}
                                </span>
                            </td>
                            {{-- El consecutivo del contrato, no el id interno --}}
                            <td data-label="Contrato">{{ $technical_order->contract->numero_visible }}</td>
                            <td data-label="Cliente">
                                {{ $technical_order->contract->client->name }}
                                {{ $technical_order->contract->client->last_name }}
                            </td>
                            <td data-label="Tipo" class="solo-escritorio">{{ $technical_order->type }}</td>
                            <td data-label="Detalle">{{ $technical_order->detail }}</td>
                            <td data-label="Creada">{{ $technical_order->created_at->format('Y-m-d H:i') }}</td>
                            <td data-label="Técnico">
                                {{ $technical_order->assignedUser->name ?? '—' }}
                                {{ $technical_order->assignedUser->last_name ?? '' }}
                            </td>
                            <td class="celda-acciones" data-label="">
                                <button type="button" class="btn btn-sm btn-primary" data-toggle="modal"
                                        data-target="#detailModal{{ $technical_order->id }}">
                                    <i class="fas fa-clipboard-check"></i> Verificar
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modales de verificación (fuera de la tabla por DataTables) --}}
    @foreach($technical_orders as $technical_order)
        <div class="modal fade modal-movil" id="detailModal{{ $technical_order->id }}" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Verificar orden {{ $technical_order->id }}</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        @include('gestisp.technicals_orders.partials.order_details', ['technical_order' => $technical_order])
                    </div>

                    {{-- Formulario de verificación: el botón pulsado
                         (close_order / reject_order) determina la acción --}}
                    <div class="p-3 border-top">
                        <form action="{{ route('technical_order.verification_process', $technical_order) }}" method="post">
                            @csrf
                            @method('put')
                            <label for="verification_comment_{{ $technical_order->id }}">Comentario de verificación</label>
                            <textarea class="form-control" name="verification_comment"
                                      id="verification_comment_{{ $technical_order->id }}" required></textarea>
                            <div class="text-center mt-2">
                                <button type="submit" name="close_order" class="btn btn-success">
                                    <i class="fas fa-check"></i> CERRAR ORDEN
                                </button>
                                <button type="submit" name="reject_order" class="btn btn-danger">
                                    <i class="fas fa-undo"></i> RECHAZAR ORDEN
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    @include('gestisp.partials.leaflet-styles')
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    @include('gestisp.partials.leaflet-script')

    <script>
        $(document).ready(function () {
            const enMovil = window.matchMedia('(max-width: 767.98px)').matches;

            $('#verificationTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay órdenes pendientes de verificación.'
                },
                pageLength: enMovil ? 10 : 25,
                dom: enMovil ? 'ftip' : 'lfrtip',
                order: [[5, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [7] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });
    </script>
@endsection
