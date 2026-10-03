{{-- ============================================================
     Listado de clientes

     Mismo lenguaje que el resto de los módulos (ONTs, PPPoE,
     servicios): las cifras primero, los filtros en su panel y la
     tabla después.

     QUÉ TENÍA ESTA PANTALLA
     -----------------------
     Las columnas «Número de contrato», «Servicio» y «Estado» eran
     `<td></td>` vacíos —se pintaban y no se llenaban nunca—, el ojo
     de la última columna era un `href=""` que no iba a ningún sitio,
     y el selector de «resultados por página» vivía dentro de otro
     formulario, que es HTML inválido. El modal de «seleccionar
     columnas» escondía celdas con aritmética de índices y descuadraba
     la tabla.

     Todo eso se reemplaza por lo que el resto del sistema ya hacía.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Clientes')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-users mr-2"></i>Clientes</h1>

        {{-- En el telefono los botones se apilan a lo ancho (ver
             .acciones-movil en gestisp-movil.css): en una sola fila
             junto al titulo se salen de la pantalla. --}}
        <div class="acciones-movil">
            @can('clients.import')
                <a href="{{ route('clients.import.index') }}" class="btn btn-secondary">
                    <i class="fas fa-file-import"></i> Importar
                </a>
            @endcan
            @can('clients.create')
                <a href="{{ route('clients.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Crear cliente
                </a>
            @endcan
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

    {{-- ---------- Cifras principales ----------
         Se cuentan sobre LO FILTRADO: un total de toda la empresa no
         explicaría la tabla que se está mirando. --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $resumen['total'] }}</h3>
                    <p class="mb-0">Clientes</p>
                </div>
                <div class="icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ $resumen['con_contrato'] }}</h3>
                    <p class="mb-0">Con contrato</p>
                </div>
                <div class="icon"><i class="fas fa-file-signature"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $resumen['sin_contrato'] > 0 ? 'bg-gradient-warning' : 'bg-gradient-secondary' }}">
                <div class="inner">
                    <h3>{{ $resumen['sin_contrato'] }}</h3>
                    <p class="mb-0">Sin contrato</p>
                </div>
                <div class="icon"><i class="fas fa-user-slash"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ $resumen['juridicos'] }}</h3>
                    <p class="mb-0">Personas jurídicas</p>
                </div>
                <div class="icon"><i class="fas fa-building"></i></div>
            </div>
        </div>
    </div>

    @if($resumen['sin_contrato'] > 0)
        <div class="alert alert-light border py-2">
            <i class="fas fa-info-circle text-muted"></i>
            <strong>{{ $resumen['sin_contrato'] }}</strong> cliente(s) sin contrato: están
            registrados pero no se les factura nada.
            <a href="{{ request()->fullUrlWithQuery(['contratos' => 'no', 'page' => null]) }}">Verlos</a>.
        </div>
    @endif

    {{-- ---------- Filtros ---------- --}}
    @php
        $hayFiltros = $clientesFiltrados = filled(request('filter_value'))
            || filled(request('contratos'))
            || filled(request('estado'));
    @endphp
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0"><i class="fas fa-filter mr-1"></i> Filtros</h3>
            {{-- El desplegable solo existe en el telefono: en escritorio
                 el panel esta siempre visible por la clase d-md-block. --}}
            <div class="card-tools d-md-none">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-toggle="collapse" data-target="#filtrosClientes">
                    <i class="fas fa-sliders-h"></i>
                    {{ $hayFiltros ? 'Filtros activos' : 'Filtrar' }}
                </button>
            </div>
        </div>

        {{-- UN SOLO formulario. El de «resultados por página» estaba
             anidado dentro de este, que es HTML inválido: el navegador
             se come el interior y el select acababa enviándose por el
             de fuera de pura casualidad. --}}
        <form method="GET" action="{{ route('clients.index') }}"
              class="card-body py-3 collapse d-md-block filtros-movil toque {{ $hayFiltros ? 'show' : '' }}"
              id="filtrosClientes">
            <div class="row align-items-end">
                <div class="col-md-3 form-group mb-2">
                    <label class="mb-1 small">Buscar por</label>
                    <select id="filterField" name="filter_field" class="form-control form-control-sm">
                        <option value="name" @selected(request('filter_field', 'name') === 'name')>Nombre o apellido</option>
                        <option value="identity_number" @selected(request('filter_field') === 'identity_number')>Documento</option>
                        <option value="contract_number" @selected(request('filter_field') === 'contract_number')>N.º de contrato</option>
                        <option value="number_phone" @selected(request('filter_field') === 'number_phone')>Teléfono</option>
                        <option value="email" @selected(request('filter_field') === 'email')>Correo</option>
                        <option value="type_client" @selected(request('filter_field') === 'type_client')>Tipo de cliente</option>
                    </select>
                </div>

                <div class="col-md-3 form-group mb-2">
                    <label class="mb-1 small">Valor</label>
                    <input type="text" id="filterInput" name="filter_value" class="form-control form-control-sm"
                           placeholder="Nombre o apellido" value="{{ request('filter_value') }}">
                </div>

                <div class="col-md-2 form-group mb-2">
                    <label class="mb-1 small">Contratos</label>
                    <select name="contratos" class="form-control form-control-sm">
                        <option value="">Indiferente</option>
                        <option value="si" @selected(request('contratos') === 'si')>Con contrato</option>
                        <option value="no" @selected(request('contratos') === 'no')>Sin contrato</option>
                    </select>
                </div>

                <div class="col-md-2 form-group mb-2">
                    <label class="mb-1 small">Estado del contrato</label>
                    <select name="estado" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach(\App\Billing\Enums\ContractStatus::cases() as $estado)
                            <option value="{{ $estado->value }}" @selected(request('estado') === $estado->value)>
                                {{ $estado->value }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-1 form-group mb-2">
                    <label class="mb-1 small">Por página</label>
                    <select name="per_page" class="form-control form-control-sm">
                        @foreach([15, 25, 50, 100] as $cantidad)
                            <option value="{{ $cantidad }}" @selected((int) request('per_page', 15) === $cantidad)>
                                {{ $cantidad }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-1 form-group mb-2">
                    <button class="btn btn-primary btn-sm btn-block" title="Aplicar">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    @if($hayFiltros)
                        <a href="{{ route('clients.index') }}" class="btn btn-link btn-sm pl-0">
                            <i class="fas fa-times"></i> Quitar todos los filtros
                        </a>
                    @endif
                </div>

                {{-- Las exportaciones llevan los filtros puestos: lo que
                     se descarga es lo que se está viendo. --}}
                @can('clients.export')
                    <div class="acciones-movil">
                        <a href="{{ route('clients.export', request()->query()) }}"
                           class="btn btn-success btn-sm" title="Exportar a Excel">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <a href="{{ route('clients.export-pdf', request()->query()) }}"
                           class="btn btn-danger btn-sm" title="Exportar a PDF">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                    </div>
                @endcan
            </div>
        </form>
    </div>

    {{-- ---------- Tabla ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0">
                <i class="fas fa-list mr-1"></i> Detalle
                <span class="badge badge-secondary ml-1">{{ $clients->total() }}</span>
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-sm tabla-movil" style="width:100%">
                    <thead class="thead-light">
                    <tr>
                        <th>Documento</th>
                        <th>Cliente</th>
                        <th class="text-center">Tipo</th>
                        <th>Contacto</th>
                        <th>Contratos</th>
                        <th>Plan</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center" style="width: 120px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($clients as $client)
                        <tr>
                            <td data-label="Documento">
                                {{ $client->identity_number ?: '—' }}
                                @if($client->verification_digit !== null && $client->verification_digit !== '')
                                    <small class="text-muted">-{{ $client->verification_digit }}</small>
                                @endif
                            </td>

                            {{-- Celda principal: encabeza la ficha en el
                                 telefono, asi que carga el nombre y, debajo,
                                 el documento y el estado, que ahi no se ven
                                 de otra forma. --}}
                            <td class="celda-principal" data-label="">
                                <a href="{{ route('clients.edit', $client) }}" class="font-weight-bold">
                                    {{ trim($client->name . ' ' . ($client->last_name ?? '')) ?: '—' }}
                                </a>
                                <span class="d-md-none d-block mt-1">
                                    <span class="badge badge-light border">{{ $client->identity_number ?: 'Sin documento' }}</span>
                                    @if($client->contracts_count)
                                        <span class="badge badge-success ml-1">{{ $client->contracts_count }} contrato(s)</span>
                                    @else
                                        <span class="badge badge-warning ml-1">Sin contrato</span>
                                    @endif
                                </span>
                            </td>

                            <td class="text-center solo-escritorio" data-label="Tipo">
                                <span class="badge badge-light border">{{ $client->type_client ?: '—' }}</span>
                            </td>

                            <td data-label="Contacto">
                                {{ $client->number_phone ?: '—' }}
                                @if($client->email)
                                    <small class="d-block text-muted">{{ $client->email }}</small>
                                @endif
                            </td>

                            {{-- Los contratos del cliente, que antes era una
                                 celda vacía. Un cliente puede tener varios:
                                 se enseñan hasta tres y se dice cuántos más. --}}
                            <td data-label="Contratos" data-order="{{ $client->contracts_count }}">
                                @forelse($client->contracts->take(3) as $contrato)
                                    <a href="{{ route('contracts.show', $contrato) }}" class="d-block">
                                        {{ $contrato->numero_visible ?: '—' }}
                                    </a>
                                @empty
                                    <span class="badge badge-warning">Sin contrato</span>
                                @endforelse
                                @if($client->contracts_count > 3)
                                    <small class="text-muted">… y {{ $client->contracts_count - 3 }} más</small>
                                @endif
                            </td>

                            <td data-label="Plan">
                                @php
                                    $planes = $client->contracts->pluck('plan.name')->filter()->unique();
                                @endphp
                                @forelse($planes->take(2) as $plan)
                                    <span class="badge badge-info">{{ $plan }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                                @if($planes->count() > 2)
                                    <small class="text-muted d-block">… y {{ $planes->count() - 2 }} más</small>
                                @endif
                            </td>

                            <td class="text-center" data-label="Estado">
                                @forelse($client->contracts->pluck('status')->filter()->unique() as $estado)
                                    <span class="badge badge-{{
                                        \App\Billing\Enums\ContractStatus::esFinal($estado) ? 'secondary'
                                        : (str_contains(strtolower($estado), 'activo') ? 'success'
                                        : (str_contains(strtolower($estado), 'suspend') ? 'danger'
                                        : (str_contains(strtolower($estado), 'reconex') ? 'info' : 'warning')))
                                    }}">{{ $estado }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>

                            {{-- En el telefono los botones llevan texto: un
                                 icono suelto no dice que hace y ahi no hay
                                 raton que muestre el tooltip.

                                 El ojo de antes era `href=""`: no iba a
                                 ningun sitio. Se reemplaza por lo que de
                                 verdad se hace desde aqui —abrir la ficha
                                 y darle un contrato—, que es lo que ya
                                 enlazaba el buscador de clientes.
                                 (ClientController::show sigue vacio: la
                                 ficha del cliente es la de edicion.) --}}
                            <td class="text-center celda-acciones" data-label="">
                                @can('clients.edit')
                                    <a href="{{ route('clients.edit', $client) }}"
                                       class="btn btn-outline-warning btn-sm" title="Ver y modificar">
                                        <i class="fas fa-pencil-alt"></i>
                                        <span class="d-md-none ml-1">Modificar</span>
                                    </a>
                                @endcan
                                @can('contracts.create')
                                    <a href="{{ route('contracts.create', $client) }}"
                                       class="btn btn-outline-primary btn-sm" title="Darle un contrato">
                                        <i class="fas fa-file-signature"></i>
                                        <span class="d-md-none ml-1">Nuevo contrato</span>
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                No se encontraron clientes con esos filtros.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- La paginación conserva los filtros: withQueryString() en
                 el controlador. Sin eso, pasar a la página 2 los perdía. --}}
            <div class="mt-3">
                {{ $clients->links() }}
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection

@section('js')
    <script>
        /**
         * El placeholder sigue al campo elegido: un cuadro que dice
         * «ingrese un valor» no dice qué se está buscando.
         */
        document.addEventListener('DOMContentLoaded', () => {
            const campo = document.getElementById('filterField');
            const valor = document.getElementById('filterInput');

            const pistas = {
                name: 'Nombre o apellido',
                identity_number: 'Número de documento',
                contract_number: 'N.º de contrato',
                number_phone: 'Teléfono',
                email: 'Correo electrónico',
                type_client: 'Natural o Jurídica',
            };

            campo.addEventListener('change', () => {
                valor.placeholder = pistas[campo.value] ?? 'Ingrese un valor';
            });
        });
    </script>
@endsection
