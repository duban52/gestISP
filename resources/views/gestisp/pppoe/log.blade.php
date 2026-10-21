{{-- ============================================================
     Registro de PPPoE del router

     La pantalla que contesta «¿por qué este cliente no entra?» sin
     abrir Winbox. Se lee del Mikrotik en vivo —no se guarda nada— y
     cada línea viene traducida, clasificada y con qué hacer.

     El mensaje original se enseña siempre debajo: RouterOS cambia
     textos entre versiones, y el día que aparezca uno que no se
     reconoce el operador tiene que poder leerlo igual.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Registro PPPoE')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-clipboard-list mr-2"></i>Registro PPPoE
            @if($router)
                <small class="text-muted">— {{ $router->name }}</small>
            @endif
        </h1>
        <div class="acciones-movil">
            <a href="{{ route('pppoe.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Cuentas PPPoE
            </a>
            @if($router)
                <a href="{{ request()->fullUrl() }}" class="btn btn-primary">
                    <i class="fas fa-sync"></i> Actualizar
                </a>
            @endif
        </div>
    </div>
@endsection

@section('content')

    @if($routers->isEmpty())
        <div class="alert alert-info shadow-sm">
            <i class="fas fa-info-circle"></i>
            No hay routers registrados en esta sucursal.
        </div>
    @else

        {{-- ---------- Filtros ---------- --}}
        <div class="card shadow-sm">
            <div class="card-header py-2">
                <h3 class="card-title mb-0"><i class="fas fa-filter mr-1"></i> Filtros</h3>
            </div>
            <form method="GET" class="card-body py-3">
                <div class="row align-items-end">
                    <div class="col-md-4 form-group mb-2">
                        <label class="mb-1 small">Router</label>
                        <select name="router" class="form-control form-control-sm" onchange="this.form.submit()">
                            @foreach($routers as $r)
                                <option value="{{ $r->id }}" @selected($router?->id === $r->id)>
                                    {{ $r->name }} ({{ $r->ip_address }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 form-group mb-2">
                        <label class="mb-1 small">Qué pasó</label>
                        <select name="tipo" class="form-control form-control-sm">
                            <option value="">Todo</option>
                            @foreach($tipos as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected(($filtros['tipo'] ?? '') === $clave)>
                                    {{ $etiqueta }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 form-group mb-2">
                        <label class="mb-1 small">Usuario o texto</label>
                        <input type="text" name="buscar" class="form-control form-control-sm"
                               value="{{ $filtros['buscar'] ?? '' }}"
                               placeholder="Usuario PPPoE, IP, lo que sea">
                    </div>
                    <div class="col-md-1 form-group mb-2">
                        <button class="btn btn-primary btn-sm btn-block" title="Aplicar">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>

                @if(array_filter($filtros, fn ($v, $k) => $k !== 'router' && filled($v), ARRAY_FILTER_USE_BOTH))
                    <a href="{{ route('pppoe.log', ['router' => $router?->id]) }}" class="btn btn-link btn-sm pl-0">
                        <i class="fas fa-times"></i> Quitar los filtros
                    </a>
                @endif
            </form>
        </div>

        {{-- ---------- El router no contesta ---------- --}}
        @if($error)
            <div class="alert alert-danger shadow-sm">
                <h5 class="mb-1"><i class="fas fa-plug"></i> No se pudo leer el registro</h5>
                <p class="mb-1">{{ $error }}</p>
                <small>
                    Compruebe que el router responde y que el usuario de la API tiene permiso de lectura.
                </small>
            </div>
        @endif

        {{-- ---------- El router no está registrando PPPoE ---------- --}}
        @if($sinRegistroDePpp)
            <div class="alert alert-warning shadow-sm">
                <h5 class="mb-1"><i class="fas fa-exclamation-triangle"></i> Este router no está guardando el registro de PPPoE</h5>
                <p class="mb-2">
                    El router respondió, pero en su registro no hay ni una línea de cuentas PPPoE. No significa que
                    todo vaya bien: significa que <strong>no se está anotando</strong>, así que tampoco se verán los
                    fallos cuando los haya.
                </p>
                <p class="mb-1">Se activa una sola vez, en el router:</p>
                <pre class="mb-1" style="background:#fff; border:1px solid #f0d9a8; padding:10px; border-radius:4px;"><code>/system logging add topics=ppp,!debug action=memory
/system logging add topics=pppoe,!debug action=memory</code></pre>
                <small class="text-muted">
                    El registro de RouterOS vive en memoria: guarda unas cientos de líneas y se pierde al reiniciar
                    el equipo. Para conservar más, en el router se puede mandar a disco o a un servidor syslog.
                </small>
            </div>
        @endif

        @if($resumen && $resumen['total'] > 0)
            {{-- ---------- Cifras ---------- --}}
            <div class="row">
                <div class="col-6 col-lg-3">
                    <div class="small-box bg-gradient-primary">
                        <div class="inner">
                            <h3>{{ $resumen['total'] }}</h3>
                            <p class="mb-0">Eventos</p>
                        </div>
                        <div class="icon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small-box bg-gradient-success">
                        <div class="inner">
                            <h3>{{ $resumen['conexiones'] }}</h3>
                            <p class="mb-0">Conexiones</p>
                        </div>
                        <div class="icon"><i class="fas fa-plug"></i></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small-box bg-gradient-secondary">
                        <div class="inner">
                            <h3>{{ $resumen['desconexiones'] }}</h3>
                            <p class="mb-0">Desconexiones</p>
                        </div>
                        <div class="icon"><i class="fas fa-unlink"></i></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small-box {{ $resumen['fallos'] > 0 ? 'bg-gradient-danger' : 'bg-gradient-light' }}">
                        <div class="inner">
                            <h3>{{ $resumen['fallos'] }}</h3>
                            <p class="mb-0">Fallos</p>
                        </div>
                        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                </div>
            </div>

            {{-- ---------- Quién falla más ----------
                 Es lo que convierte un registro en un parte de averías:
                 un usuario con treinta fallos en una hora es un CPE mal
                 configurado reintentando solo, no treinta incidencias. --}}
            @if($resumen['reincidentes']->isNotEmpty())
                <div class="card shadow-sm border-warning">
                    <div class="card-header py-2">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-redo mr-1"></i> Los que más fallan
                        </h3>
                    </div>
                    <div class="card-body py-2">
                        @foreach($resumen['reincidentes'] as $usuario => $veces)
                            <a href="{{ route('pppoe.log', ['router' => $router->id, 'buscar' => $usuario]) }}"
                               class="btn btn-sm btn-outline-danger mr-1 mb-1">
                                <strong>{{ $usuario }}</strong>
                                <span class="badge badge-danger ml-1">{{ $veces }}</span>
                            </a>
                        @endforeach
                        <small class="d-block text-muted mt-1">
                            Muchos fallos seguidos del mismo usuario suelen ser un equipo reintentando solo con
                            credenciales equivocadas, no incidencias distintas.
                        </small>
                    </div>
                </div>
            @endif
        @endif

        {{-- ---------- El registro ---------- --}}
        <div class="card shadow-sm">
            <div class="card-header py-2">
                <h3 class="card-title mb-0">
                    <i class="fas fa-list mr-1"></i> Eventos
                    <span class="badge badge-secondary ml-1">{{ $eventos->count() }}</span>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="logTable" class="table table-hover table-sm mb-0 tabla-movil">
                        <thead class="thead-light">
                        <tr>
                            <th style="width:110px;">Hora</th>
                            <th style="width:150px;">Usuario</th>
                            <th>Qué pasó</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($eventos as $evento)
                            <tr>
                                <td class="text-nowrap text-monospace" data-label="Hora">{{ $evento['hora'] }}</td>
                                <td data-label="Usuario">
                                    @if($evento['usuario'])
                                        <a href="{{ route('pppoe.log', ['router' => $router->id, 'buscar' => $evento['usuario']]) }}"
                                           title="Ver solo lo de este usuario">
                                            <strong>{{ $evento['usuario'] }}</strong>
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="celda-principal" data-label="">
                                    <span class="badge badge-{{ \App\Services\Mikrotik\RegistroPppoe::colorDe($evento['tipo']) }}">
                                        <i class="fas {{ \App\Services\Mikrotik\RegistroPppoe::iconoDe($evento['tipo']) }}"></i>
                                        {{ $evento['titulo'] }}
                                    </span>

                                    @if($evento['explicacion'])
                                        <span class="d-block mt-1">{{ $evento['explicacion'] }}</span>
                                    @endif

                                    @if($evento['que_hacer'])
                                        <small class="d-block text-primary mt-1">
                                            <i class="fas fa-lightbulb"></i> {{ $evento['que_hacer'] }}
                                        </small>
                                    @endif

                                    {{-- El original, siempre: es el único que
                                         sirve cuando el evento no es ninguno
                                         de los conocidos. --}}
                                    <small class="d-block text-muted mt-1" style="font-size:.72rem;">
                                        <code>{{ $evento['mensaje'] }}</code>
                                    </small>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">
                                    @if($sinRegistroDePpp)
                                        El router no está guardando el registro de PPPoE (vea el aviso de arriba).
                                    @elseif($error)
                                        No se pudo leer el registro del router.
                                    @else
                                        No hay eventos de PPPoE con esos filtros.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($eventos->isNotEmpty())
                <div class="card-footer py-2">
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i>
                        Se lee del router en el momento: no se guarda copia. El registro de RouterOS vive en
                        memoria y se pierde al reiniciar el equipo.
                    </small>
                </div>
            @endif
        </div>
    @endif
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
            const tabla = document.getElementById('logTable');

            // Sin filas no se inicializa: DataTables sobre la fila de
            // «no hay eventos» la cuenta como un registro y escribe
            // «Mostrando 1 de 1», que es mentira.
            if (!tabla || tabla.tBodies[0].rows.length === 0 || !tabla.querySelector('td[data-label="Hora"]')) {
                return;
            }

            $(tabla).DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
                pageLength: 50,
                // Lo último primero: cuando alguien llama, lo que
                // importa es lo que acaba de pasar.
                order: [[0, 'desc']],
                columnDefs: [{ orderable: false, targets: [2] }],
            });
        });
    </script>
@endsection
