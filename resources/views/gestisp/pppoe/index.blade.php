@extends('adminlte::page')
@section('title', 'Cuentas PPPoE')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-user-lock mr-2"></i>Cuentas PPPoE</h1>
        <div>
            {{-- El enlace lleva los filtros actuales: el archivo tiene
                 que contener lo mismo que se está viendo, no todo. --}}
            <a href="{{ route('pppoe.export', request()->query()) }}" class="btn btn-outline-success">
                <i class="fas fa-file-excel"></i> Exportar a Excel
            </a>
            <a href="{{ route('pppoe.cutoff') }}" class="btn btn-outline-danger">
                <i class="fas fa-user-slash"></i> Cortes masivos
            </a>
        </div>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger">{{ session('success-delete') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Cifras de cabecera

         La distinción que importa: HABILITADA es administrativo —si se
         cortó o no—, CONECTADA es si el cliente tiene el equipo
         encendido. Mezclarlas hace que un listado de "caídos" incluya
         a los que están cortados a propósito.
         ============================================================ --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3>{{ $resumen['total'] }}</h3>
                    <p class="mb-0">Cuentas</p>
                </div>
                <div class="icon"><i class="fas fa-user-lock"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ $resumen['conectadas'] }}</h3>
                    <p class="mb-0">Conectadas ahora</p>
                </div>
                <div class="icon"><i class="fas fa-plug"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $resumen['caidas'] > 0 ? 'bg-gradient-warning' : 'bg-gradient-secondary' }}">
                <div class="inner">
                    <h3>{{ $resumen['caidas'] }}</h3>
                    <p class="mb-0">Habilitadas sin conexión</p>
                </div>
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-danger">
                <div class="inner">
                    <h3>{{ $resumen['suspendidas'] }}</h3>
                    <p class="mb-0">Suspendidas</p>
                </div>
                <div class="icon"><i class="fas fa-user-slash"></i></div>
            </div>
        </div>
    </div>

    {{-- Hasta cuándo es fiable lo que se ve. Sin esto, "conectada" se
         leería como si se acabara de preguntar al router, y no: es lo
         que vio el muestreador en su última pasada. El día que el
         muestreador se pare, la pantalla seguiría diciendo que todo el
         mundo está arriba y nadie se enteraría. --}}
    @if($ultimoSondeo)
        <p class="text-muted small mb-2">
            <i class="fas fa-clock"></i>
            Estado de conexión según el último muestreo,
            <strong>{{ $ultimoSondeo->diffForHumans() }}</strong>
            ({{ $ultimoSondeo->format('d/m/Y H:i') }}).
            @if($ultimoSondeo->lt(now()->subMinutes(20)))
                <span class="text-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Hace más de veinte minutos: revise que <code>pppoe:poll</code> siga corriendo.
                </span>
            @endif
        </p>
    @else
        <div class="alert alert-light border py-2">
            <i class="fas fa-info-circle text-muted"></i>
            Todavía no hay ningún muestreo: el estado de conexión aparecerá
            cuando corra <code>php artisan pppoe:poll</code> (cada cinco minutos por tarea programada).
        </div>
    @endif

    @if($resumen['sin_contrato'] > 0)
        <div class="alert alert-light border py-2">
            <i class="fas fa-info-circle text-muted"></i>
            <strong>{{ $resumen['sin_contrato'] }}</strong> cuenta(s) sin contrato asociado.
            <a href="{{ request()->fullUrlWithQuery(['contrato' => 'no']) }}">Verlas</a>
            para vincularlas o confirmar que son de la empresa.
        </div>
    @endif

    {{-- ---------- Filtros ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0"><i class="fas fa-filter mr-1"></i> Filtros</h3>
        </div>
        <form method="GET" class="card-body py-3">
            <div class="row align-items-end">
                <div class="col-md-3 form-group mb-2">
                    <label class="mb-1 small">Buscar</label>
                    <input type="text" name="q" value="{{ $filtros['q'] ?? '' }}"
                           class="form-control form-control-sm"
                           placeholder="Usuario, contrato, cédula, nombre, IP…">
                </div>
                <div class="col-md-2 form-group mb-2">
                    <label class="mb-1 small">Router</label>
                    <select name="router_id" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($routers as $router)
                            <option value="{{ $router->id }}"
                                @selected((string) ($filtros['router_id'] ?? '') === (string) $router->id)>
                                {{ $router->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 form-group mb-2">
                    <label class="mb-1 small">Estado</label>
                    <select name="estado" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        <option value="activa" @selected(($filtros['estado'] ?? '') === 'activa')>Habilitadas</option>
                        <option value="suspendida" @selected(($filtros['estado'] ?? '') === 'suspendida')>Suspendidas</option>
                    </select>
                </div>
                <div class="col-md-2 form-group mb-2">
                    <label class="mb-1 small">Conexión</label>
                    <select name="conexion" class="form-control form-control-sm">
                        <option value="">Indiferente</option>
                        <option value="conectada" @selected(($filtros['conexion'] ?? '') === 'conectada')>Conectadas</option>
                        <option value="desconectada" @selected(($filtros['conexion'] ?? '') === 'desconectada')>Desconectadas</option>
                        <option value="nunca" @selected(($filtros['conexion'] ?? '') === 'nunca')>Nunca conectadas</option>
                    </select>
                </div>
                <div class="col-md-2 form-group mb-2">
                    <label class="mb-1 small">Perfil</label>
                    <select name="profile" class="form-control form-control-sm">
                        <option value="">Todos</option>
                        @foreach($perfiles as $perfil)
                            <option value="{{ $perfil }}" @selected(($filtros['profile'] ?? '') === $perfil)>
                                {{ $perfil }}
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

            <div class="row align-items-end">
                <div class="col-md-3 form-group mb-0">
                    <label class="mb-1 small">Contrato</label>
                    <select name="contrato" class="form-control form-control-sm">
                        <option value="">Indiferente</option>
                        <option value="si" @selected(($filtros['contrato'] ?? '') === 'si')>Con contrato</option>
                        <option value="no" @selected(($filtros['contrato'] ?? '') === 'no')>Sin contrato</option>
                    </select>
                </div>
                <div class="col-md-9">
                    @if(array_filter($filtros ?? []))
                        <a href="{{ route('pppoe.index') }}" class="btn btn-link btn-sm pl-0">
                            <i class="fas fa-times"></i> Quitar todos los filtros
                        </a>
                    @endif
                </div>
            </div>
        </form>
    </div>

    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <div>
            {{-- Importar cuentas existentes de un router --}}
            <form method="POST" id="formImportar" class="form-inline d-inline-flex" action="">
                @csrf
                <select id="importRouterSelect" class="form-control form-control-sm mr-2">
                    <option value="">Importar desde router...</option>
                    @foreach($routers as $router)
                        <option value="{{ $router->id }}">{{ $router->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-outline-primary" disabled id="btnImportar">
                    <i class="fas fa-download"></i> Importar
                </button>
            </form>
        </div>
        <div>
            @can('pppoe.cutoff')
                <a href="{{ route('pppoe.cutoff') }}" class="btn btn-outline-danger mr-1">
                    <i class="fas fa-user-slash"></i> Cortes masivos
                </a>
            @endcan
            <button class="btn btn-primary" id="btnNuevaCuenta">
                <i class="fas fa-plus"></i> Nueva Cuenta PPPoE
            </button>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <div class="table-responsive">
                <table id="pppoeTable" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Router</th>
                        <th>Perfil</th>
                        <th>IP</th>
                        <th>N.º contrato</th>
                        <th>Cliente</th>
                        <th>Estado</th>
                        <th>Conexión</th>
                        <th>Última conexión</th>
                        <th>Comentario</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($accounts as $account)
                        <tr>
                            <td>{{ $account->username }}</td>
                            <td>{{ $account->router->name ?? 'N/A' }}</td>
                            <td>{{ $account->profile }}</td>
                            {{-- La IP que tiene AHORA, que es la que sirve para
                                 entrar al equipo. Si no está conectada se
                                 muestra la fija, si la tiene configurada. --}}
                            <td>
                                @if($account->last_address)
                                    <code>{{ $account->last_address }}</code>
                                @elseif($account->remote_address)
                                    <code class="text-muted">{{ $account->remote_address }}</code>
                                    <small class="d-block text-muted">fija</small>
                                @else
                                    —
                                @endif
                            </td>
                            {{-- Número de contrato, no el id interno: es el
                                 que el cliente tiene impreso y el que se pega
                                 en la pantalla de cortes masivos. --}}
                            <td>
                                @if($account->contract_id)
                                    <strong>{{ $account->contract->numero_visible }}</strong>
                                @else
                                    {{-- Las cuentas importadas del router llegan
                                         sin contrato: se vinculan desde su ficha --}}
                                    <a href="{{ route('pppoe.show', $account) }}"
                                       class="badge badge-warning"
                                       title="Vincular esta cuenta con un contrato">
                                        <i class="fas fa-unlink mr-1"></i> Sin contrato
                                    </a>
                                @endif
                            </td>
                            <td>
                                @if($account->contract_id)
                                    {{ $account->contract->client->name ?? '—' }}
                                    {{ $account->contract->client->last_name ?? '' }}
                                @else
                                    —
                                @endif
                            </td>
                            {{-- Dos columnas y no una: "suspendida" es una
                                 decisión de la empresa; "desconectada" es que
                                 el cliente tiene el equipo apagado. Con una
                                 sola columna no se distingue a quién hay que
                                 llamar y a quién hay que cobrarle. --}}
                            <td>
                                @if($account->disabled)
                                    <span class="badge badge-danger">Suspendida</span>
                                @else
                                    <span class="badge badge-success">Habilitada</span>
                                @endif
                            </td>
                            <td>
                                @if($account->estaConectada())
                                    <span class="badge badge-success">Conectada</span>
                                @elseif($account->last_polled_at)
                                    <span class="badge badge-secondary">Desconectada</span>
                                @else
                                    <span class="badge badge-light border">Sin datos</span>
                                @endif
                            </td>
                            @php
                                $ultima = $account->ultimaConexion();
                            @endphp
                            {{-- data-order con la marca de tiempo: sin él
                                 DataTables ordenaría el texto "hace 3 días" y
                                 saldría un orden que no significa nada. --}}
                            <td data-order="{{ $ultima?->timestamp ?? 0 }}">
                                @if($ultima)
                                    {{ $ultima->diffForHumans() }}
                                    <small class="d-block text-muted">{{ $ultima->format('d/m/Y H:i') }}</small>
                                @else
                                    <span class="text-muted">Nunca</span>
                                @endif
                            </td>
                            <td>{{ $account->comment ?? '—' }}</td>
                            <td>
                                <a href="{{ route('pppoe.show', $account) }}" class="btn btn-sm btn-info" title="Ver detalle">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button
                                    class="btn btn-sm btn-primary btn-editar-pppoe"
                                    data-id="{{ $account->id }}"
                                    data-username="{{ $account->username }}"
                                    data-profile="{{ $account->profile }}"
                                    data-remote="{{ $account->remote_address }}"
                                    data-comment="{{ $account->comment }}"
                                    data-router="{{ $account->router_id }}"
                                    title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <form method="POST" action="{{ route('pppoe.toggle', $account) }}" class="d-inline">
                                    @csrf
                                    @if($account->disabled)
                                        <button type="submit" class="btn btn-sm btn-success" title="Reactivar">
                                            <i class="fas fa-play"></i>
                                        </button>
                                    @else
                                        <button type="submit" class="btn btn-sm btn-warning" title="Suspender">
                                            <i class="fas fa-pause"></i>
                                        </button>
                                    @endif
                                </form>

                                <button
                                    class="btn btn-sm btn-danger btn-eliminar-pppoe"
                                    data-id="{{ $account->id }}"
                                    data-username="{{ $account->username }}"
                                    title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modal Crear Cuenta --}}
    <div class="modal fade" id="modalCrearPppoe" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form method="POST" action="{{ route('pppoe.store') }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Nueva Cuenta PPPoE</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        {{-- ============================================================
                             ¿A quién pertenece la cuenta?

                             Lo normal es que sea de un contrato. Pero no toda
                             cuenta le factura a alguien: enlaces entre sedes
                             propias, cámaras, antenas de la empresa, pruebas.
                             Esos casos obligaban antes a inventar un contrato
                             o a crear el secret a mano en el Mikrotik, por
                             fuera del sistema y de todo control.

                             La casilla llega DESMARCADA: el caso con contrato
                             es la norma y no debe costar un clic extra.
                             ============================================================ --}}
                        <div class="custom-control custom-switch mb-3">
                            <input type="checkbox" class="custom-control-input"
                                   id="pppoeSinContrato" name="sin_contrato" value="1">
                            <label class="custom-control-label" for="pppoeSinContrato">
                                Esta cuenta <strong>no pertenece a un contrato</strong>
                            </label>
                        </div>

                        <div id="pppoeBloqueContrato">
                            <div class="form-group">
                                <label>Buscar Contrato <span class="text-danger">*</span></label>
                                <input type="text" id="buscarContratoPppoe" class="form-control"
                                       placeholder="Identificación, nombre o # contrato...">
                                <div id="resultadosContratoPppoe" class="list-group mt-1"
                                     style="display:none; position:absolute; z-index:9999; width:90%;"></div>
                            </div>
                            <div class="form-group">
                                <label>Cliente seleccionado</label>
                                <input type="text" id="clientePppoeView" class="form-control" disabled
                                       placeholder="Ninguno seleccionado">
                            </div>
                        </div>

                        {{-- ============================================================
                             Datos del titular cuando NO hay contrato.

                             El caso típico no es "una cuenta de nadie", sino
                             "una cuenta cuyo titular está en otro sistema":
                             quien lleva la base de clientes por fuera de
                             gestISP igual necesita que el usuario, la clave y
                             el comentario se armen con las reglas de la casa.

                             Son OPCIONALES a propósito: también hay cuentas
                             que de verdad no tienen titular —una cámara, una
                             antena, un enlace entre sedes— y esas se llenan a
                             mano. Lo único obligatorio sin contrato es el
                             comentario.
                             ============================================================ --}}
                        <div class="card card-outline card-info d-none" id="pppoeBloqueTitular">
                            <div class="card-header py-2">
                                <h3 class="card-title" style="font-size: .95rem;">
                                    <i class="fas fa-user-edit mr-1"></i> Datos del titular
                                    <small class="text-muted">(opcional — para generar la cuenta)</small>
                                </h3>
                            </div>
                            <div class="card-body py-2">
                                <div class="row">
                                    <div class="col-md-6 form-group mb-2">
                                        <label class="mb-1">Nombres</label>
                                        <input type="text" id="titularNombres" class="form-control form-control-sm"
                                               maxlength="120" autocomplete="off">
                                    </div>
                                    <div class="col-md-6 form-group mb-2">
                                        <label class="mb-1">Apellidos</label>
                                        <input type="text" id="titularApellidos" class="form-control form-control-sm"
                                               maxlength="120" autocomplete="off">
                                    </div>
                                    <div class="col-md-6 form-group mb-2">
                                        <label class="mb-1">Identificación</label>
                                        <input type="text" id="titularIdentificacion" class="form-control form-control-sm"
                                               maxlength="40" autocomplete="off">
                                    </div>
                                    <div class="col-md-6 form-group mb-2">
                                        <label class="mb-1">N.º de contrato o cliente</label>
                                        <input type="text" id="titularReferencia" class="form-control form-control-sm"
                                               maxlength="60" autocomplete="off"
                                               placeholder="Del sistema externo">
                                    </div>
                                </div>
                                <small class="text-muted">
                                    Con estos datos se arman solos el usuario, la contraseña y el comentario,
                                    con las mismas reglas que una cuenta con contrato. Todo queda editable.
                                    Si la cuenta no tiene titular (una antena, una cámara), deje esto vacío
                                    y escriba los campos de abajo a mano.
                                </small>
                            </div>
                        </div>

                        <input type="hidden" name="contract_id" id="pppoeContractId">

                        <div class="form-group">
                            <label>Router <span class="text-danger">*</span></label>
                            <select name="router_id" id="crearRouterSelect" class="form-control" required>
                                <option value="">Seleccione un router</option>
                                @foreach($routers as $router)
                                    <option value="{{ $router->id }}">{{ $router->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Perfil (Plan) <span class="text-danger">*</span></label>
                            <select name="profile" id="crearProfileSelect" class="form-control" required>
                                <option value="">Seleccione primero un router</option>
                            </select>
                        </div>

                        {{-- Credenciales autogeneradas (editables) --}}
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Usuario <span class="text-danger">*</span></label>
                                    <input type="text" name="username" id="pppoeUsername" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Contraseña <span class="text-danger">*</span></label>
                                    <input type="text" name="password" id="pppoePassword" class="form-control" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>
                                Comentario
                                <span class="text-danger d-none" id="pppoeComentarioObligatorio">*</span>
                            </label>
                            <input type="text" name="comment" id="pppoeComment" class="form-control" maxlength="255">
                            <small class="form-text text-muted" id="pppoeAyudaComentario">
                                Se arma solo con los datos del contrato.
                            </small>
                        </div>
                        <div class="form-group">
                            <label>IP Remota (opcional)</label>
                            <input type="text" name="remote_address" class="form-control"
                                   placeholder="Dejar vacío para IP dinámica del pool">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Crear Cuenta
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Editar Cuenta --}}
    <div class="modal fade" id="modalEditarPppoe" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form method="POST" id="formEditarPppoe" action="">
                @csrf
                @method('PUT')
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Editar Cuenta PPPoE</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Usuario <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="editarUsername" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Contraseña</label>
                            <input type="text" name="password" class="form-control"
                                   placeholder="Dejar vacío para conservar la actual">
                        </div>
                        <div class="form-group">
                            <label>Perfil (Plan) <span class="text-danger">*</span></label>
                            <select name="profile" id="editarProfileSelect" class="form-control" required>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>IP Remota</label>
                            <input type="text" name="remote_address" id="editarRemote" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Comentario</label>
                            <input type="text" name="comment" id="editarComment" class="form-control">
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle"></i>
                            Al guardar, la sesión activa del usuario se reiniciará para aplicar los cambios.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Actualizar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Eliminar Cuenta --}}
    <div class="modal fade" id="modalEliminarPppoe" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Cuenta PPPoE</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar la cuenta <strong id="eliminarPppoeUsername"></strong>?</p>
                    <p class="text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Se eliminará del router y de la base de datos. La sesión del cliente se desconectará.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarPppoe" method="POST">
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
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#pppoeTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay cuentas PPPoE registradas.'
                },
                pageLength: 25,
                columnDefs: [
                    // Acciones: la última columna. Se ha corrido dos
                    // veces al añadir columnas (número de contrato,
                    // y luego conexión y última conexión), así que se
                    // referencia desde el final y deja de importar
                    // cuántas haya delante.
                    { orderable: false, targets: -1 },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        // ============ Importar desde router ============
        document.getElementById('importRouterSelect').addEventListener('change', function () {
            const btn = document.getElementById('btnImportar');
            btn.disabled = !this.value;
            document.getElementById('formImportar').action = this.value
                ? `/pppoe/import/${this.value}`
                : '';
        });

        // ============ Cargar perfiles al elegir router ============
        document.getElementById('crearRouterSelect').addEventListener('change', function () {
            loadProfiles(this.value, 'crearProfileSelect');
        });

        function loadProfiles(routerId, targetSelectId, selectedProfile = null) {
            const select = document.getElementById(targetSelectId);
            select.innerHTML = '<option value="">Cargando perfiles...</option>';

            if (!routerId) {
                select.innerHTML = '<option value="">Seleccione primero un router</option>';
                return;
            }

            fetch(`/api/routers/${routerId}/profiles`)
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        select.innerHTML = `<option value="">${data.error}</option>`;
                        return;
                    }

                    let html = '<option value="">Seleccione un perfil</option>';
                    data.forEach(p => {
                        const rateInfo = p.rate_limit ? ` (${p.rate_limit})` : '';
                        const selected = (selectedProfile && p.name === selectedProfile) ? 'selected' : '';
                        html += `<option value="${p.name}" ${selected}>${p.name}${rateInfo}</option>`;
                    });
                    select.innerHTML = html;
                })
                .catch(() => {
                    select.innerHTML = '<option value="">Error al cargar perfiles</option>';
                });
        }

        /* ============================================================
           GENERACIÓN AUTOMÁTICA DE CREDENCIALES

           Las reglas ya no viven aquí: las aplica el servidor
           (App\Services\PppoeCredentialGenerator). El motivo es que
           además de armar el nombre hay que comprobar que esté LIBRE
           en el router, y eso solo se puede consultar contra la base.
           Si el nombre ya existe, el servidor devuelve uno con
           diferenciador (_2, _3, ...).

           Como la unicidad es por router, cualquier cambio de router
           obliga a volver a pedir la propuesta.
           ============================================================ */
        let credencialesTimeout = null;

        /** Datos con los que se pide la propuesta, segun el modo. */
        function datosParaCredenciales() {
            const sinContrato = document.getElementById('pppoeSinContrato').checked;
            const routerId    = document.getElementById('crearRouterSelect').value || null;

            if (!sinContrato) {
                const contratoId = document.getElementById('pppoeContractId').value;

                return contratoId ? { router_id: routerId, contract_id: contratoId } : null;
            }

            const nombres   = document.getElementById('titularNombres').value.trim();
            const apellidos = document.getElementById('titularApellidos').value.trim();

            // Sin al menos nombre o apellido no hay nada que proponer:
            // es el caso de la camara o la antena, que se llena a mano.
            if (nombres === '' && apellidos === '') {
                return null;
            }

            return {
                router_id: routerId,
                nombres: nombres,
                apellidos: apellidos,
                identificacion: document.getElementById('titularIdentificacion').value.trim(),
                referencia: document.getElementById('titularReferencia').value.trim(),
            };
        }

        /** Pide la propuesta y rellena los campos (todos editables). */
        function proponerCredenciales() {
            const datos = datosParaCredenciales();

            if (!datos) {
                return;
            }

            fetch('{{ route('pppoe.suggest') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
                body: JSON.stringify(datos),
            })
                .then(r => r.ok ? r.json() : null)
                .then(propuesta => {
                    if (!propuesta) {
                        return;
                    }

                    // Solo se rellena lo que venga con valor: asi una
                    // propuesta incompleta no borra lo que el operador
                    // ya escribio a mano.
                    if (propuesta.username) {
                        document.getElementById('pppoeUsername').value = propuesta.username;
                    }
                    if (propuesta.password) {
                        document.getElementById('pppoePassword').value = propuesta.password;
                    }
                    if (propuesta.comment) {
                        document.getElementById('pppoeComment').value = propuesta.comment;
                    }
                })
                .catch(() => { /* el operador puede escribirlas a mano */ });
        }

        /** Version con retardo, para no pedir en cada tecla. */
        function proponerCredencialesConRetardo() {
            clearTimeout(credencialesTimeout);
            credencialesTimeout = setTimeout(proponerCredenciales, 400);
        }

        ['titularNombres', 'titularApellidos', 'titularIdentificacion', 'titularReferencia']
            .forEach(function (id) {
                document.getElementById(id).addEventListener('input', proponerCredencialesConRetardo);
            });

        // El diferenciador depende del router: al cambiarlo hay que
        // recalcular o se podria proponer un nombre ya ocupado alli.
        document.getElementById('crearRouterSelect').addEventListener('change', proponerCredencialesConRetardo);

        /* ============================================================
           CUENTA CON O SIN CONTRATO

           Un interruptor cambia el modo. Sin contrato no hay de dónde
           sacar usuario, clave ni comentario, así que se escriben a
           mano y el comentario pasa a ser obligatorio: es lo único
           que dirá para qué existe la cuenta (el servidor lo exige
           igual, esto solo lo anticipa en pantalla).
           ============================================================ */
        document.getElementById('pppoeSinContrato').addEventListener('change', function () {
            aplicarModoContratoPppoe(this.checked);
        });

        function aplicarModoContratoPppoe(sinContrato) {
            document.getElementById('pppoeBloqueContrato').classList.toggle('d-none', sinContrato);
            document.getElementById('pppoeBloqueTitular').classList.toggle('d-none', !sinContrato);
            document.getElementById('pppoeComentarioObligatorio').classList.toggle('d-none', !sinContrato);

            ['titularNombres', 'titularApellidos', 'titularIdentificacion', 'titularReferencia']
                .forEach(id => document.getElementById(id).value = '');

            // Al cambiar de modo se limpia lo del anterior: si no,
            // queda un contrato elegido en un formulario que declara
            // que no tiene contrato.
            document.getElementById('pppoeContractId').value            = '';
            document.getElementById('clientePppoeView').value           = '';
            document.getElementById('buscarContratoPppoe').value        = '';
            document.getElementById('resultadosContratoPppoe').style.display = 'none';
            document.getElementById('pppoeUsername').value              = '';
            document.getElementById('pppoePassword').value              = '';
            document.getElementById('pppoeComment').value               = '';

            const comentario = document.getElementById('pppoeComment');

            comentario.required = sinContrato;

            document.getElementById('pppoeAyudaComentario').textContent = sinContrato
                ? 'Obligatorio. Ej.: "Enlace sede Yarumal" o "Cámara parque principal".'
                : 'Se arma solo con los datos del contrato.';
        }

        // ============ Abrir modal crear ============
        document.getElementById('btnNuevaCuenta').addEventListener('click', function () {
            // Siempre se abre en el modo por defecto: que quede
            // marcada la casilla de la cuenta anterior sería la forma
            // más fácil de dejar una cuenta huérfana por descuido.
            document.getElementById('pppoeSinContrato').checked = false;
            aplicarModoContratoPppoe(false);

            $('#modalCrearPppoe').modal('show');
        });

        // ============ Abrir modal editar ============
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-editar-pppoe')) {
                const btn = e.target.closest('.btn-editar-pppoe');

                document.getElementById('editarUsername').value = btn.getAttribute('data-username');
                document.getElementById('editarRemote').value   = btn.getAttribute('data-remote') || '';
                document.getElementById('editarComment').value  = btn.getAttribute('data-comment') || '';

                document.getElementById('formEditarPppoe').action =
                    `/pppoe/${btn.getAttribute('data-id')}`;

                loadProfiles(
                    btn.getAttribute('data-router'),
                    'editarProfileSelect',
                    btn.getAttribute('data-profile')
                );

                $('#modalEditarPppoe').modal('show');
            }
        });

        // ============ Abrir modal eliminar ============
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-pppoe')) {
                const btn = e.target.closest('.btn-eliminar-pppoe');

                document.getElementById('eliminarPppoeUsername').textContent =
                    btn.getAttribute('data-username');
                document.getElementById('formEliminarPppoe').action =
                    `/pppoe/${btn.getAttribute('data-id')}`;

                $('#modalEliminarPppoe').modal('show');
            }
        });

        // ============ Buscador de contratos ============
        let buscarTimeoutPppoe = null;

        document.getElementById('buscarContratoPppoe').addEventListener('input', function () {
            const q          = this.value.trim();
            const resultados = document.getElementById('resultadosContratoPppoe');

            clearTimeout(buscarTimeoutPppoe);

            if (q.length < 2) {
                resultados.style.display = 'none';
                resultados.innerHTML     = '';
                return;
            }

            buscarTimeoutPppoe = setTimeout(() => {
                fetch(`/api/contratos/buscar?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        resultados.innerHTML = '';

                        if (data.length === 0) {
                            resultados.innerHTML =
                                '<div class="list-group-item text-muted">Sin resultados</div>';
                            resultados.style.display = 'block';
                            return;
                        }

                        data.forEach(contrato => {
                            const item       = document.createElement('button');
                            item.type        = 'button';
                            item.className   = 'list-group-item list-group-item-action';
                            item.textContent = contrato.label;

                            item.addEventListener('click', function () {
                                document.getElementById('pppoeContractId').value  = contrato.id;
                                document.getElementById('clientePppoeView').value = contrato.label;
                                document.getElementById('buscarContratoPppoe').value = '';
                                resultados.style.display = 'none';
                                resultados.innerHTML     = '';

                                // Autogenerar usuario, contraseña y comentario
                                proponerCredenciales();
                            });

                            resultados.appendChild(item);
                        });

                        resultados.style.display = 'block';
                    });
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('#buscarContratoPppoe') && !e.target.closest('#resultadosContratoPppoe')) {
                document.getElementById('resultadosContratoPppoe').style.display = 'none';
            }
        });
    </script>
@endsection
