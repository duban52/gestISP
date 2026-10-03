{{-- ============================================================
     Revisión previa de la importación de cuentas PPPoE

     POR QUÉ EXISTE ESTA PANTALLA
     ----------------------------
     Antes se pulsaba «importar» y se escribía directo. Si el router
     traía dos secrets con el mismo usuario —cosa que un Mikrotik
     permite y pasa más de lo que parece—, la inserción masiva moría
     contra el índice único `router_id + username` y al operador le
     salía una pantalla de error con el SQL entero. No había forma de
     saber qué corregir, ni siquiera de saber que había algo que
     corregir.

     Ahora se mira primero: cuántas entran, cuántas ya estaban y
     cuáles tienen un problema que hay que resolver EN EL ROUTER.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Importar cuentas PPPoE')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-file-import mr-2"></i>Importar de {{ $router->name }}
        </h1>
        <div class="acciones-movil">
            <a href="{{ route('pppoe.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')

    @if($error)
        <div class="alert alert-danger shadow-sm">
            <i class="fas fa-exclamation-triangle mr-1"></i> {{ $error }}
        </div>

        <a href="{{ route('pppoe.import.review', $router) }}" class="btn btn-primary">
            <i class="fas fa-sync"></i> Reintentar
        </a>
    @else
        {{-- ---------- Cifras ---------- --}}
        <div class="row">
            <div class="col-6 col-lg-3">
                <div class="small-box bg-gradient-primary">
                    <div class="inner">
                        <h3>{{ $revision['total'] }}</h3>
                        <p class="mb-0">En el router</p>
                    </div>
                    <div class="icon"><i class="fas fa-server"></i></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small-box bg-gradient-success">
                    <div class="inner">
                        <h3>{{ count($revision['importables']) }}</h3>
                        <p class="mb-0">Se van a importar</p>
                    </div>
                    <div class="icon"><i class="fas fa-download"></i></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small-box bg-gradient-secondary">
                    <div class="inner">
                        <h3>{{ $revision['ya_estaban'] }}</h3>
                        <p class="mb-0">Ya registradas</p>
                    </div>
                    <div class="icon"><i class="fas fa-check-double"></i></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small-box {{ $revision['problemas'] ? 'bg-gradient-warning' : 'bg-gradient-light' }}">
                    <div class="inner">
                        <h3>{{ count($revision['problemas']) }}</h3>
                        <p class="mb-0">Hay que corregir</p>
                    </div>
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
            </div>
        </div>

        {{-- ---------- Lo que hay que corregir ---------- --}}
        @if($revision['problemas'])
            <div class="card shadow-sm border-warning">
                <div class="card-header py-2 bg-warning">
                    <h3 class="card-title mb-0">
                        <i class="fas fa-tools mr-1"></i>
                        Estas cuentas no se van a importar
                    </h3>
                </div>
                <div class="card-body">
                    <p>
                        El problema está <strong>en el router</strong>, no aquí: corríjalo en el
                        Mikrotik y vuelva a importar. Las demás cuentas sí se pueden importar ya —
                        estas no se perderán, entrarán en la siguiente importación.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Usuario</th>
                                <th>Perfil</th>
                                <th>Comentario en el router</th>
                                <th>Qué pasa</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($revision['problemas'] as $problema)
                                <tr>
                                    <td class="text-monospace">
                                        <strong>{{ $problema['username'] }}</strong>
                                    </td>
                                    <td>{{ $problema['perfil'] ?: '—' }}</td>
                                    <td>{{ $problema['comentario'] ?: '—' }}</td>
                                    <td>{{ $problema['motivo'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @else
            <div class="alert alert-success shadow-sm">
                <i class="fas fa-check-circle mr-1"></i>
                <strong>Todo en orden.</strong> No hay usuarios repetidos ni secrets sin nombre
                en este router.
            </div>
        @endif

        {{-- ---------- Lo que va a entrar ---------- --}}
        <div class="card shadow-sm">
            <div class="card-header py-2">
                <h3 class="card-title mb-0">
                    <i class="fas fa-list mr-1"></i> Cuentas nuevas
                    <span class="badge badge-secondary ml-1">{{ count($revision['importables']) }}</span>
                </h3>
            </div>
            <div class="card-body">
                @if($revision['importables'])
                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Usuario</th>
                                <th>Perfil</th>
                                <th>Servicio</th>
                                <th>IP</th>
                                <th class="text-center">Estado</th>
                                <th>Comentario</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($revision['importables'] as $cuenta)
                                <tr>
                                    <td class="text-monospace">{{ $cuenta['username'] }}</td>
                                    <td>{{ $cuenta['profile'] ?: '—' }}</td>
                                    <td>{{ $cuenta['service'] ?: '—' }}</td>
                                    <td>{{ $cuenta['remote_address'] ?: '—' }}</td>
                                    <td class="text-center">
                                        @if($cuenta['disabled'])
                                            <span class="badge badge-secondary">Deshabilitada</span>
                                        @else
                                            <span class="badge badge-success">Activa</span>
                                        @endif
                                    </td>
                                    <td>{{ $cuenta['comment'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted mb-0">
                        No hay cuentas nuevas que importar: todas las de este router
                        ya están registradas{{ $revision['problemas'] ? ' o tienen un problema que corregir' : '' }}.
                    </p>
                @endif
            </div>

            @if($revision['importables'])
                <div class="card-footer py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <small class="text-muted">
                        Las contraseñas y los perfiles se copian tal como están en el router.
                    </small>
                    <form method="POST" action="{{ route('pppoe.import', $router) }}"
                          data-procesando="Importando las cuentas del router...">
                        @csrf
                        <button class="btn btn-primary">
                            <i class="fas fa-download"></i>
                            Importar {{ count($revision['importables']) }} cuenta(s)
                        </button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    @include('gestisp.partials.resultado-accion')
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
