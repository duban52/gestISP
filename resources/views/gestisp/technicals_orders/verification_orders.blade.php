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
                                    {{ $technical_order->contract?->client?->fullName() ?: '—' }}
                                </span>
                            </td>
                            {{-- El consecutivo del contrato, no el id interno --}}
                            <td data-label="Contrato">{{ $technical_order->contract?->numero_visible ?: '—' }}</td>
                            <td data-label="Cliente">
                                {{ $technical_order->contract?->client?->fullName() ?: '—' }}
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

                        {{-- CONTROL DE CALIDAD: con que potencia quedo el servicio.
                             El mismo dato y los mismos colores que el listado de
                             ONTs autorizadas: onts.rx_power, que el sondeo SNMP
                             renueva cada 5 min. «Leer ahora» usa el mismo refresco
                             que el icono de ese listado. --}}
                        @php
                            $ontOrden = $technical_order->contract?->ont;
                            $banda = ($ontOrden && $ontOrden->rx_power !== null && $ontOrden->rx_power !== '')
                                ? \App\Services\OltStatistics::bandaDe((float) $ontOrden->rx_power)
                                : null;
                            $definicionBanda = $banda ? \App\Services\OltStatistics::bandas()[$banda] : null;
                        @endphp

                        @if($ontOrden)
                            <div class="border rounded p-3 mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="text-uppercase text-muted small mb-0">
                                        <i class="fas fa-signal mr-1"></i> Potencia óptica del servicio
                                    </h6>
                                    @can('onts.show')
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-potencia-orden"
                                                data-url="{{ route('onts.sync-power', $ontOrden) }}"
                                                data-destino="potencia-{{ $technical_order->id }}">
                                            <i class="fas fa-sync"></i> Leer ahora
                                        </button>
                                    @endcan
                                </div>

                                <div id="potencia-{{ $technical_order->id }}">
                                    @if($definicionBanda)
                                        <span class="badge badge-{{ $definicionBanda['color'] }} p-2" style="font-size: 1rem;">
                                            {{ number_format((float) $ontOrden->rx_power, 2) }} dBm
                                        </span>
                                        <strong class="ml-2 text-{{ $definicionBanda['color'] }}">{{ $definicionBanda['etiqueta'] }}</strong>
                                        <small class="text-muted">({{ $definicionBanda['rango'] }})</small>
                                    @elseif($ontOrden->status)
                                        <span class="badge badge-secondary p-2" style="font-size: 1rem;">Sin lectura</span>
                                    @else
                                        <span class="badge badge-danger p-2" style="font-size: 1rem;">Caída</span>
                                        <span class="ml-2 text-danger">La ONT no está en línea.</span>
                                    @endif
                                </div>

                                <small class="d-block text-muted mt-2">
                                    ONT
                                    @can('onts.show')
                                        <a href="{{ route('onts.show', $ontOrden) }}" target="_blank" rel="noopener">{{ $ontOrden->sn }}</a>
                                    @else
                                        {{ $ontOrden->sn }}
                                    @endcan
                                </small>
                            </div>
                        @endif
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
        // «Leer ahora»: potencia en vivo por SNMP (~1 s), el mismo
        // endpoint que el icono de refrescar de las ONTs autorizadas.
        $(document).on('click', '.btn-potencia-orden', function () {
            const $boton = $(this);
            const destino = document.getElementById($boton.data('destino'));

            $boton.prop('disabled', true).find('i').addClass('fa-spin');

            fetch($boton.data('url'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) {
                        destino.innerHTML = '<span class="text-danger">No se pudo leer la potencia ahora.</span>';
                    } else if (d.color) {
                        // color, etiqueta y rango vienen del servidor
                        // (OltStatistics): los umbrales no se repiten aqui.
                        destino.innerHTML =
                            `<span class="badge badge-${d.color} p-2" style="font-size: 1rem;">${Number(d.rx_power).toFixed(2)} dBm</span>`
                            + ` <strong class="ml-2 text-${d.color}">${d.etiqueta}</strong>`
                            + ` <small class="text-muted">(${d.rango}) · leída ahora</small>`;
                    } else {
                        destino.innerHTML = '<span class="badge badge-danger p-2" style="font-size: 1rem;">Caída</span>'
                            + ' <span class="ml-2 text-danger">La ONT no está en línea.</span>';
                    }
                })
                .catch(() => {
                    destino.innerHTML = '<span class="text-danger">No se pudo leer la potencia ahora.</span>';
                })
                .finally(() => $boton.prop('disabled', false).find('i').removeClass('fa-spin'));
        });

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
