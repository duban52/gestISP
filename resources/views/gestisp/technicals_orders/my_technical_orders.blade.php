{{-- ============================================================
     Mis órdenes técnicas

     Es LA pantalla móvil del sistema: el técnico la abre en la calle,
     con una mano, para ver qué le toca y cerrarlo. Todo lo que aquí se
     decide está pensado para ese uso.

     En pantalla grande sigue siendo la tabla de siempre. En el
     teléfono, cada fila se convierte en una ficha (ver
     public/css/gestisp-movil.css): se mantiene la misma tabla y el
     mismo DataTable —así el buscador y la paginación siguen
     funcionando— y es el CSS el que cambia la presentación.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Mis Órdenes Técnicas')

@section('content_header')
    <h1 class="mb-0">
        <i class="fas fa-clipboard-list mr-2"></i>Mis órdenes
        <small class="text-muted d-block d-md-inline" style="font-size: .95rem;">
            {{ Auth::user()->name }} {{ Auth::user()->last_name }}
        </small>
    </h1>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('error') }}
        </div>
    @endif

    {{-- ============================================================
         Órdenes asignadas al técnico autenticado.
         Desde aquí puede procesarlas (ir al detalle) o rechazarlas
         indicando el motivo.
         ============================================================ --}}
    <div class="card toque">
        <div class="card-body">
            <div class="table-responsive">
                <table id="myOrdersTable" class="table table-hover tabla-movil" style="width:100%">
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
                        @php
                            // Órdenes que el supervisor devolvió: vuelven a esta
                            // bandeja y el técnico tiene que saberlo de un vistazo,
                            // sin abrirlas una por una.
                            $motivoDevolucion = $technical_order->returnReason();
                        @endphp
                        <tr @class(['table-warning' => $motivoDevolucion])>
                            {{-- Celda principal: es la que encabeza la ficha en el
                                 teléfono, así que lleva lo que identifica la orden
                                 de un vistazo. --}}
                            <td class="celda-principal" data-label="">
                                <strong>Orden {{ $technical_order->id }}</strong>
                                <span class="badge badge-light border ml-1">{{ $technical_order->type }}</span>
                                @if($motivoDevolucion)
                                    <span class="badge badge-warning ml-1">Devuelta</span>
                                @endif
                                <span class="d-md-none d-block text-muted small mt-1">
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
                            <td data-label="Dirección" class="romper-texto">
                                {{ $technical_order->contract->address }}
                                {{-- Enlace a mapas solo en el teléfono: es donde
                                     sirve, porque el técnico va a ir hasta allí. --}}
                                @if($technical_order->contract->address)
                                    <a class="d-md-none d-inline-block ml-1"
                                       href="https://www.google.com/maps/search/?api=1&query={{ urlencode($technical_order->contract->address) }}"
                                       target="_blank" rel="noopener" title="Cómo llegar">
                                        <i class="fas fa-directions"></i>
                                    </a>
                                @endif
                            </td>
                            <td data-label="Tipo" class="solo-escritorio">{{ $technical_order->type }}</td>
                            <td data-label="Detalle">{{ $technical_order->detail }}</td>
                            <td data-label="Comentario inicial">
                                {{ $technical_order->initial_comment ?? '—' }}
                                @if($motivoDevolucion)
                                    <div class="text-danger small mt-1">
                                        <i class="fas fa-undo"></i>
                                        <strong>Devuelta:</strong> {{ $motivoDevolucion }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="Creada">{{ $technical_order->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-nowrap celda-acciones" data-label="">
                                {{-- Procesar la orden. En el teléfono el botón lleva
                                     texto: un icono suelto no dice qué hace y aquí
                                     no hay tooltip que valga. --}}
                                <a href="{{ route('technicals_orders.show', $technical_order->id) }}"
                                   title="Ver y procesar" class="btn btn-sm btn-success">
                                    <i class="fas fa-cogs"></i>
                                    <span class="d-md-none ml-1">Procesar</span>
                                </a>

                                {{-- Rechazar la orden (con motivo) --}}
                                <button class="btn btn-sm btn-danger" title="Rechazar orden"
                                        data-toggle="modal"
                                        data-target="#rejectOrderModal{{ $technical_order->id }}">
                                    <i class="fas fa-times-circle"></i>
                                    <span class="d-md-none ml-1">Rechazar</span>
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
        <div class="modal fade modal-movil" id="rejectOrderModal{{ $technical_order->id }}" tabindex="-1" role="dialog">
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
                    <div class="modal-body toque">
                        <form action="{{ route('technical_orders.reject', $technical_order) }}" method="post">
                            @csrf
                            @method('put')
                            <label for="reason_{{ $technical_order->id }}">Motivo del rechazo de la orden</label>
                            <textarea name="reason" id="reason_{{ $technical_order->id }}"
                                      class="form-control" rows="4" required></textarea>
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
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection

@section('js')
    {{-- Sin jQuery ni Bootstrap adicionales: AdminLTE ya los incluye.
         La versión anterior cargaba jQuery 3.6 + Bootstrap 5.3, lo que
         reinicializaba $ y mezclaba dos sistemas de modales. --}}
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            const enMovil = window.matchMedia('(max-width: 767.98px)').matches;

            $('#myOrdersTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No tienes órdenes asignadas.'
                },
                // En el teléfono se muestran menos por página: cada
                // orden ocupa una ficha entera y veinticinco fichas son
                // un desplazamiento larguísimo.
                pageLength: enMovil ? 10 : 25,
                order: [[7, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [8] },
                    { defaultContent: '—', targets: '_all' }
                ],
                // El selector de "mostrar N" no aporta en un teléfono y
                // ocupa una línea entera: se esconde ahí.
                dom: enMovil ? 'ftip' : 'lfrtip'
            });
        });
    </script>
@endsection
