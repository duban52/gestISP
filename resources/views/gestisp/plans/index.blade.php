{{-- ============================================================
     Listado de planes de servicio

     Mismo lenguaje que el resto de los módulos (ONTs, PPPoE, redes):
     las cifras primero, la tabla después. Lo que se mira de un
     vistazo es cuántos se están ofreciendo, cuántos quedaron
     retirados arrastrando contratos, y cuántos viven sueltos en una
     sola sede.

     Los totales salen de la colección ya cargada —con `withCount` de
     contratos desde el controlador—: ninguna consulta extra.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Planes')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-layer-group mr-2"></i>Planes de servicio</h1>

        {{-- En el telefono los botones se apilan a lo ancho (ver
             .acciones-movil en gestisp-movil.css): en una sola fila
             junto al titulo se salen de la pantalla. --}}
        <div class="acciones-movil">
            <a href="{{ route('services.index') }}" class="btn btn-secondary">
                <i class="fas fa-wifi"></i> Servicios
            </a>
            <a href="{{ route('plans.create') }}" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Crear plan
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
        $seOfrecen   = $plans->where('active', true)->count();
        $retirados   = $plans->count() - $seOfrecen;
        $deSede      = $plans->whereNotNull('branch_id')->count();
        $contratados = $plans->sum('contracts_count');
        // Un plan retirado que todavía tiene contratos sigue
        // facturándose: no es papelera, es catálogo cerrado.
        $retiradosConContratos = $plans
            ->where('active', false)
            ->filter(fn ($p) => $p->contracts_count > 0)
            ->count();
        $sinServicios = $plans->filter(fn ($p) => $p->services->isEmpty())->count();
    @endphp

    {{-- ---------- Cifras principales ---------- --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $seOfrecen }}</h3>
                    <p class="mb-0">Se ofrecen</p>
                </div>
                <div class="icon"><i class="fas fa-layer-group"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-secondary">
                <div class="inner">
                    <h3>{{ $retirados }}</h3>
                    <p class="mb-0">Retirados</p>
                </div>
                <div class="icon"><i class="fas fa-box-open"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $contratados }}</h3>
                    <p class="mb-0">Contratos con plan</p>
                </div>
                <div class="icon"><i class="fas fa-file-signature"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $deSede > 0 ? 'bg-gradient-warning' : 'bg-gradient-success' }}">
                <div class="inner">
                    <h3>{{ $deSede }}</h3>
                    <p class="mb-0">Exclusivos de una sede</p>
                </div>
                <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
            </div>
        </div>
    </div>

    @if($retiradosConContratos > 0 || $sinServicios > 0)
        <div class="alert alert-light border py-2">
            <i class="fas fa-info-circle text-muted"></i>
            @if($retiradosConContratos > 0)
                <strong>{{ $retiradosConContratos }}</strong> plan(es) retirado(s) todavía con
                contratos: se les sigue facturando, solo dejaron de ofrecerse a los nuevos.
            @endif
            @if($sinServicios > 0)
                <strong>{{ $sinServicios }}</strong> sin servicios asociados: un contrato
                con ese plan no tendría nada que facturar.
            @endif
        </div>
    @endif

    {{-- ---------- Tabla ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0">
                <i class="fas fa-list mr-1"></i> Detalle
                <span class="badge badge-secondary ml-1">{{ $plans->count() }}</span>
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="plansTable" class="table table-hover table-sm tabla-movil" style="width:100%">
                    <thead class="thead-light">
                    <tr>
                        <th>Ámbito</th>
                        <th>Nombre</th>
                        <th class="text-center">Estado</th>
                        <th>Servicios incluidos</th>
                        <th class="text-right">Precio total</th>
                        <th class="text-center" style="width: 150px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($plans as $plan)
                        @php
                            /**
                             * Precio total del plan: suma del precio final
                             * (base + impuesto) de cada servicio asociado.
                             */
                            $total = $plan->services->sum(
                                fn($s) => $s->base_price * (1 + $s->tax_percentage / 100)
                            );
                        @endphp
                        <tr>
                            {{-- De la empresa o de una sede. Se ve SIEMPRE,
                                 tambien fuera del consolidado: el ambito
                                 decide donde se puede vender y no se
                                 deduce del modo de trabajo. --}}
                            <td data-label="Ámbito"><x-ambito-badge :de="$plan" /></td>

                            {{-- Celda principal: encabeza la ficha en el
                                 telefono, asi que carga el nombre y, debajo,
                                 el estado y el precio, que ahi no se ven de
                                 otra forma. --}}
                            <td class="celda-principal" data-label="">
                                <span class="font-weight-bold">{{ $plan->name }}</span>
                                <span class="d-md-none d-block mt-1">
                                    @if($plan->active)
                                        <span class="badge badge-success">Se ofrece</span>
                                    @else
                                        <span class="badge badge-secondary">Retirado</span>
                                    @endif
                                    <span class="badge badge-primary ml-1">
                                        ${{ number_format($total, 0, ',', '.') }}
                                    </span>
                                </span>
                            </td>

                            <td class="text-center solo-escritorio" data-label="Estado">
                                @if($plan->active)
                                    <span class="badge badge-success">Se ofrece</span>
                                @else
                                    <span class="badge badge-secondary"
                                          title="No aparece al dar de alta contratos nuevos. Los que ya lo tienen lo conservan.">
                                        Retirado
                                    </span>
                                @endif
                                @if($plan->contracts_count)
                                    <small class="text-muted d-block">{{ $plan->contracts_count }} contrato(s)</small>
                                @endif
                            </td>

                            {{-- Servicios del plan como badges --}}
                            <td data-label="Servicios incluidos">
                                @forelse($plan->services as $service)
                                    <span class="badge badge-info">{{ $service->name }}</span>
                                @empty
                                    <span class="badge badge-warning">Sin servicios asociados</span>
                                @endforelse
                            </td>

                            {{-- data-order deja que DataTables ordene por el
                                 NÚMERO: sin él ordenaría el texto con puntos
                                 de millar y «9.000» quedaría tras «80.000». --}}
                            <td class="text-right" data-label="Precio total" data-order="{{ (float) $total }}">
                                <strong>${{ number_format($total, 0, ',', '.') }}</strong>
                            </td>

                            {{-- En el telefono los botones llevan texto: un
                                 icono suelto no dice que hace y ahi no hay
                                 raton que muestre el tooltip. --}}
                            <td class="text-center celda-acciones" data-label="">
                                <a class="btn btn-outline-warning btn-sm"
                                   href="{{ route('plans.edit', $plan) }}"
                                   title="Modificar">
                                    <i class="fas fa-pencil-alt"></i>
                                    <span class="d-md-none ml-1">Modificar</span>
                                </a>

                                {{-- Retirar del catálogo, o devolverlo.
                                     Es lo que sustituye al borrado cuando el plan
                                     tiene contratos: desde la fase 13 la clave
                                     foránea impide borrarlo, porque un contrato sin
                                     plan no tiene nada que facturar. --}}
                                @can('plans.edit')
                                    <form method="POST" action="{{ route('plans.toggle', $plan) }}" class="d-inline">
                                        @csrf @method('PATCH')
                                        <button class="btn btn-sm {{ $plan->active ? 'btn-outline-secondary' : 'btn-outline-success' }}"
                                                title="{{ $plan->active ? 'Dejar de ofrecerlo en contratos nuevos' : 'Volver a ofrecerlo' }}">
                                            <i class="fas {{ $plan->active ? 'fa-box-open' : 'fa-undo' }}"></i>
                                            <span class="ml-1">{{ $plan->active ? 'Retirar' : 'Reactivar' }}</span>
                                        </button>
                                    </form>
                                @endcan

                                {{-- Eliminar solo si NO tiene contratos: con ellos, la
                                     base lo rechaza y el botón solo produce un error.

                                     La URL viene de route(), no armada en el JS: las
                                     rutas viven bajo el prefijo «gestisp/» y el
                                     `/plans/${id}` que habia aqui apuntaba a una
                                     ruta que no existe. --}}
                                @if(!$plan->contracts_count)
                                    <button class="btn btn-outline-danger btn-sm btn-eliminar-plan"
                                            data-url="{{ route('plans.destroy', $plan) }}"
                                            data-nombre="{{ $plan->name }}"
                                            title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                        <span class="d-md-none ml-1">Eliminar</span>
                                    </button>
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
         Modal de confirmación de eliminación

         Un único modal reutilizable: el botón de cada fila carga
         el nombre y la URL del formulario.
         ============================================================ --}}
    <div class="modal fade modal-movil" id="modalEliminarPlan" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar plan</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el plan
                        <strong id="eliminarPlanNombre"></strong>?</p>
                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer. Si el plan tiene
                        contratos asociados, la eliminación será bloqueada.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarPlan" method="POST">
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

            $('#plansTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay planes registrados.'
                },
                // En el telefono cada plan ocupa una ficha entera: 25 por
                // pagina son un desplazamiento interminable.
                pageLength: enMovil ? 10 : 25,
                order: [[1, 'asc']],
                columnDefs: [
                    // El ambito se ve siempre: dice si el plan es de la
                    // empresa o de una sede, y eso no depende del modo de
                    // trabajo. (Antes era la columna «Sucursal», que se
                    // escondia fuera del consolidado.)
                    { orderable: true, targets: 0 },
                    // Servicios (badges) y acciones no son ordenables
                    { orderable: false, targets: [3, 5] },
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
            if (e.target.closest('.btn-eliminar-plan')) {
                const btn = e.target.closest('.btn-eliminar-plan');

                document.getElementById('eliminarPlanNombre').textContent =
                    btn.getAttribute('data-nombre');
                document.getElementById('formEliminarPlan').action =
                    btn.getAttribute('data-url');

                $('#modalEliminarPlan').modal('show');
            }
        });
    </script>
@endsection
