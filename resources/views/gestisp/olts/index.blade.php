{{-- ============================================================
     Listado de OLTs

     SE DIBUJA AL INSTANTE con lo que hay en la base y consulta cada
     equipo DESPUÉS, en segundo plano y una petición por OLT.

     Antes la pantalla esperaba a que el servidor abriera una sesión
     SSH contra cada equipo antes de pintar la primera fila: con tres
     OLTs eran varios segundos y, si una estaba apagada, había que
     aguantar su tiempo de espera completo mirando una tabla vacía.
     Ahora una OLT caída solo retrasa su propia fila.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'OLTs')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-server mr-2"></i>Administrar OLT's</h1>
@endsection

@section('content')

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <div class="text-muted">
            <strong>{{ $olts->count() }}</strong> OLT(s) ·
            <strong>{{ $olts->sum('onts_count') }}</strong> ONT(s) registradas
        </div>
        <div>
            <button type="button" class="btn btn-outline-secondary mr-1" id="btnRefrescar">
                <i class="fas fa-sync"></i> Consultar estados
            </button>
            <a href="{{ route('olts.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Agregar OLT
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table id="oltsTable" class="table table-hover" style="width:100%">
                    <thead class="thead-light">
                    <tr>
                        <th>Nombre</th>
                        <th>Dirección IP</th>
                        <th>Modelo</th>
                        <th class="text-center">ONTs</th>
                        <th>Estado</th>
                        <th>Temperatura</th>
                        <th>Uptime</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($olts as $olt)
                        <tr data-olt-id="{{ $olt->id }}"
                            data-status-url="{{ route('api.olts.status', $olt) }}">
                            <td>
                                <a href="{{ route('olts.show', $olt) }}"><strong>{{ $olt->name }}</strong></a>
                                <small class="d-block text-muted">{{ $olt->brand ?? '—' }}</small>
                            </td>
                            <td>{{ $olt->ip_address }}</td>
                            <td>{{ $olt->model ?? '—' }}</td>
                            <td class="text-center">
                                <a href="{{ route('onts.authorized') }}?olt={{ $olt->id }}"
                                   class="badge badge-secondary" style="font-size: .9rem;"
                                   title="Ver las ONTs de esta OLT">
                                    {{ $olt->onts_count }}
                                </a>
                            </td>

                            {{-- Estas tres celdas las rellena el JS con la
                                 consulta al equipo. Mientras tanto muestran
                                 lo último que se supo, indicando de cuándo
                                 es: una OLT apagada ayer no puede verse
                                 igual que una recién consultada. --}}
                            <td class="celda-estado">
                                @if($olt->status_checked_at)
                                    <span class="badge badge-{{ $olt->status ? 'success' : 'danger' }}">
                                        {{ $olt->status ? 'Conectado' : 'Desconectado' }}
                                    </span>
                                    <small class="d-block text-muted">{{ $olt->estado_consultado }}</small>
                                @else
                                    <span class="badge badge-light border">Sin consultar</span>
                                @endif
                            </td>
                            <td class="celda-temperatura">
                                @if($olt->temperature !== null)
                                    <i class="fas fa-thermometer-half text-muted"></i> {{ $olt->temperature }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="celda-uptime">{{ $olt->uptime ?? '—' }}</td>

                            <td class="text-center text-nowrap">
                                <a href="{{ route('olts.show', $olt) }}" class="btn btn-sm btn-info" title="Ver detalle">
                                    <i class="fas fa-chart-pie"></i>
                                </a>
                                <a href="{{ route('olts.edit', $olt) }}" class="btn btn-sm btn-primary" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                No hay OLTs registradas en esta sucursal.
                                <a href="{{ route('olts.create') }}">Agregar la primera</a>.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <style>
        /* La fila se atenúa mientras se consulta su equipo */
        tr.consultando .celda-estado,
        tr.consultando .celda-temperatura,
        tr.consultando .celda-uptime { opacity: .45; }
    </style>
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(function () {
            $('#oltsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay OLTs registradas en esta sucursal.'
                },
                pageLength: 25,
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [7] },
                    { defaultContent: '—', targets: '_all' }
                ]
            });

            /* ============================================================
               Estado en vivo, una petición POR OLT

               Se lanzan todas a la vez y cada fila se actualiza cuando
               llega su respuesta. Es la diferencia con el diseño
               anterior: allí una sola petición traía todas las OLTs, y
               el tiempo de espera de la que estaba apagada retrasaba
               también a las que sí respondían.
               ============================================================ */
            function consultarEstados() {
                $('#oltsTable tbody tr[data-olt-id]').each(function () {
                    consultarUna($(this));
                });
            }

            function consultarUna($fila) {
                $fila.addClass('consultando');

                fetch($fila.data('status-url'))
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(function (datos) {
                        $fila.find('.celda-estado').html(
                            '<span class="badge badge-' + (datos.conectada ? 'success' : 'danger') + '">' +
                            datos.status_text + '</span>' +
                            (datos.consultado ? '<small class="d-block text-muted">' + datos.consultado + '</small>' : '')
                        );

                        $fila.find('.celda-temperatura').html(
                            datos.temperature && datos.temperature !== 'N/A'
                                ? '<i class="fas fa-thermometer-half text-muted"></i> ' + datos.temperature
                                : '<span class="text-muted">—</span>'
                        );

                        $fila.find('.celda-uptime').text(
                            datos.uptime && datos.uptime !== 'N/A' ? datos.uptime : '—'
                        );
                    })
                    .catch(function () {
                        // No se pudo ni preguntar: se dice así, en vez de
                        // dejar el dato viejo aparentando ser actual.
                        $fila.find('.celda-estado').html(
                            '<span class="badge badge-warning">Sin respuesta</span>'
                        );
                    })
                    .finally(function () {
                        $fila.removeClass('consultando');
                    });
            }

            $('#btnRefrescar').on('click', function () {
                const $boton = $(this);

                $boton.prop('disabled', true)
                    .html('<span class="spinner-border spinner-border-sm"></span> Consultando...');

                consultarEstados();

                // Las peticiones son independientes: en vez de esperar a
                // todas, se rehabilita el botón pasado un momento.
                setTimeout(function () {
                    $boton.prop('disabled', false).html('<i class="fas fa-sync"></i> Consultar estados');
                }, 4000);
            });

            consultarEstados();
        });
    </script>
@endsection
