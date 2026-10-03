{{-- ============================================================
     Listado de servicios

     Mismo lenguaje que el resto de los módulos (ONTs, PPPoE, redes):
     las cifras primero, la tabla después. Aquí lo que se mira antes
     de nada es cuántos servicios hay sueltos por sucursal —el
     catálogo duplicado es el problema que la fase 13 vino a resolver—
     y cuántos están sin código de producto, que es lo que bloquea la
     factura electrónica.

     Los totales se calculan sobre la colección que ya está cargada:
     ninguna consulta extra.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Servicios')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-wifi mr-2"></i>Servicios</h1>

        {{-- En el telefono los botones se apilan a lo ancho (ver
             .acciones-movil en gestisp-movil.css): en una sola fila
             junto al titulo se salen de la pantalla. --}}
        <div class="acciones-movil">
            <a href="{{ route('plans.index') }}" class="btn btn-secondary">
                <i class="fas fa-layer-group"></i> Planes
            </a>
            <a href="{{ route('services.create') }}" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Crear servicio
            </a>
        </div>
    </div>
@endsection

@section('content')

    {{-- ---------- Alertas de sesión ---------- --}}
    @if(session('success-create'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success-create') }}
        </div>
    @elseif(session('success-update'))
        <div class="alert alert-warning alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-pen mr-1"></i> {{ session('success-update') }}
        </div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-trash mr-1"></i> {{ session('success-delete') }}
        </div>
    @elseif(session('error'))
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('error') }}
        </div>
    @endif

    @php
        $deLaEmpresa = $services->whereNull('branch_id')->count();
        $deSede      = $services->count() - $deLaEmpresa;
        // Sin código de producto no se puede emitir el renglón en el
        // XML de la DIAN: es el dato que el informe de completitud
        // fiscal reclama, y aquí es donde se arregla.
        $sinCodigo   = $services->filter(fn ($s) => blank($s->product_code))->count();
    @endphp

    {{-- ---------- Cifras principales ---------- --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $services->count() }}</h3>
                    <p class="mb-0">Servicios</p>
                </div>
                <div class="icon"><i class="fas fa-wifi"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $deLaEmpresa }}</h3>
                    <p class="mb-0">De la empresa</p>
                </div>
                <div class="icon"><i class="fas fa-building"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-secondary">
                <div class="inner">
                    <h3>{{ $deSede }}</h3>
                    <p class="mb-0">Exclusivos de una sede</p>
                </div>
                <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $sinCodigo > 0 ? 'bg-gradient-warning' : 'bg-gradient-success' }}">
                <div class="inner">
                    <h3>{{ $sinCodigo }}</h3>
                    <p class="mb-0">Sin código de producto</p>
                </div>
                <div class="icon"><i class="fas fa-barcode"></i></div>
            </div>
        </div>
    </div>

    @if($sinCodigo > 0)
        <div class="alert alert-light border py-2">
            <i class="fas fa-info-circle text-muted"></i>
            <strong>{{ $sinCodigo }}</strong> servicio(s) sin código de producto.
            Hace falta para emitir su renglón en el XML de la DIAN; se completa
            en <em>Datos fiscales</em>, al editar el servicio.
        </div>
    @endif

    {{-- ---------- Tabla ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0">
                <i class="fas fa-list mr-1"></i> Detalle
                <span class="badge badge-secondary ml-1">{{ $services->count() }}</span>
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="servicesTable" class="table table-hover table-sm tabla-movil" style="width:100%">
                    <thead class="thead-light">
                    <tr>
                        <th>Ámbito</th>
                        <th>Nombre</th>
                        <th class="text-right">Precio base</th>
                        <th class="text-center">IVA</th>
                        <th class="text-right">Precio final</th>
                        <th class="text-center" style="width: 120px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($services as $service)
                        @php
                            $precioFinal = $service->base_price * (1 + $service->tax_percentage / 100);
                        @endphp
                        <tr>
                            {{-- De la empresa o de una sede. Se ve SIEMPRE,
                                 tambien fuera del consolidado: el ambito
                                 decide donde se puede vender y no se
                                 deduce del modo de trabajo. --}}
                            <td data-label="Ámbito"><x-ambito-badge :de="$service" /></td>

                            {{-- Celda principal: encabeza la ficha en el
                                 telefono. Lleva el nombre y, debajo, el
                                 precio final, que ahi no se ve de otra forma. --}}
                            <td class="celda-principal" data-label="">
                                <span class="font-weight-bold">{{ $service->name }}</span>
                                <span class="d-md-none d-block mt-1">
                                    <span class="badge badge-primary">
                                        ${{ number_format($precioFinal, 0, ',', '.') }}
                                    </span>
                                    <span class="badge badge-light border ml-1">
                                        {{ $service->clasificacion()->etiqueta() }}
                                    </span>
                                </span>
                            </td>

                            {{-- data-order deja que DataTables ordene por el
                                 NÚMERO: sin él ordenaría el texto con puntos
                                 de millar y «9.000» quedaría tras «80.000». --}}
                            <td class="text-right" data-label="Precio base" data-order="{{ (float) $service->base_price }}">
                                ${{ number_format($service->base_price, 0, ',', '.') }}
                            </td>

                            <td class="text-center" data-label="IVA" data-order="{{ (float) $service->tax_percentage }}">
                                @if($service->tax_percentage > 0)
                                    <span class="badge badge-info">{{ $service->tax_percentage + 0 }}%</span>
                                @else
                                    <span class="badge badge-light border">
                                        {{ $service->clasificacion()->etiqueta() }}
                                    </span>
                                @endif
                            </td>

                            <td class="text-right" data-label="Precio final" data-order="{{ (float) $precioFinal }}">
                                <strong>${{ number_format($precioFinal, 0, ',', '.') }}</strong>
                            </td>

                            {{-- En el telefono los botones llevan texto: un
                                 icono suelto no dice que hace y ahi no hay
                                 raton que muestre el tooltip. --}}
                            <td class="text-center celda-acciones" data-label="">
                                <a class="btn btn-outline-warning btn-sm"
                                   href="{{ route('services.edit', $service) }}"
                                   title="Modificar">
                                    <i class="fas fa-pencil-alt"></i>
                                    <span class="d-md-none ml-1">Modificar</span>
                                </a>

                                {{-- La URL viene de route(), no armada en el JS:
                                     las rutas viven bajo el prefijo «gestisp/» y
                                     el `/services/${id}` que habia aqui apuntaba
                                     a una ruta que no existe. --}}
                                <button class="btn btn-outline-danger btn-sm btn-eliminar-service"
                                        data-url="{{ route('services.destroy', $service) }}"
                                        data-nombre="{{ $service->name }}"
                                        title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                    <span class="d-md-none ml-1">Eliminar</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modal de confirmación de eliminación

         Un único modal reutilizable: el botón de cada fila carga
         el nombre y la URL del formulario.
         ============================================================ --}}
    <div class="modal fade modal-movil" id="modalEliminarService" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar servicio</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el servicio
                        <strong id="eliminarServiceNombre"></strong>?</p>
                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer. Si el servicio tiene
                        planes asociados, la eliminación será bloqueada.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarService" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Sí, eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(function () {
            const enMovil = window.matchMedia('(max-width: 767.98px)').matches;

            $('#servicesTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay servicios registrados.'
                },
                // En el telefono cada servicio ocupa una ficha entera: 25
                // por pagina son un desplazamiento interminable.
                pageLength: enMovil ? 10 : 25,
                order: [[1, 'asc']],
                columnDefs: [
                    // El ambito se ve siempre: dice si el servicio es de la
                    // empresa o de una sede, y eso no depende del modo de
                    // trabajo. (Antes era la columna «Sucursal», que se
                    // escondia fuera del consolidado.)
                    { orderable: true, targets: 0 },
                    { orderable: false, targets: [5] },
                    { defaultContent: '—', targets: '_all' }
                ],
                // El selector "mostrar N" ocupa una linea entera y no
                // aporta en un telefono: se esconde.
                dom: enMovil ? 'ftip' : 'lfrtip',
            });
        });

        /**
         * Abre el modal de confirmación de eliminación.
         * Toma el nombre y la URL del botón pulsado.
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-service')) {
                const btn = e.target.closest('.btn-eliminar-service');

                document.getElementById('eliminarServiceNombre').textContent =
                    btn.getAttribute('data-nombre');
                document.getElementById('formEliminarService').action =
                    btn.getAttribute('data-url');

                $('#modalEliminarService').modal('show');
            }
        });
    </script>
@endsection
