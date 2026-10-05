{{-- ============================================================
     Envío de correos: configuración, interruptor y bitácora

     Tres cosas en una pantalla, y las tres responden a la misma
     pregunta: «¿le llegó el correo al cliente?». Hasta ahora no había
     forma de saberlo — el SMTP vivía en el .env del servidor y un
     correo que no salía no dejaba rastro en ninguna parte.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Envío de correos')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-envelope mr-2"></i>Envío de correos</h1>

        <div class="acciones-movil">
            @if($ajustes->enabled)
                <span class="badge badge-success p-2">
                    <i class="fas fa-check-circle"></i> El envío está ACTIVO
                </span>
            @else
                <span class="badge badge-danger p-2">
                    <i class="fas fa-ban"></i> El envío está APAGADO
                </span>
            @endif
        </div>
    </div>
@endsection

@section('content')

    @if(session('success'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <strong>{{ session('error') }}</strong>
        </div>
    @endif

    @if(!$ajustes->enabled)
        <div class="alert alert-danger shadow-sm">
            <h5 class="mb-1"><i class="fas fa-ban"></i> No está saliendo ningún correo</h5>
            Ni facturas, ni avisos de vencimiento, ni órdenes técnicas, ni restablecimientos de
            contraseña. Los intentos se siguen anotando abajo como «no se envió», así que al
            encenderlo podrá ver qué se quedó sin mandar.
        </div>
    @endif

    {{-- ---------- Cifras de los últimos 30 días ---------- --}}
    <div class="row">
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-success">
                <div class="inner">
                    <h3>{{ number_format($resumen['enviados']) }}</h3>
                    <p class="mb-0">Enviados (30 días)</p>
                </div>
                <div class="icon"><i class="fas fa-paper-plane"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $resumen['fallidos'] > 0 ? 'bg-gradient-danger' : 'bg-gradient-secondary' }}">
                <div class="inner">
                    <h3>{{ number_format($resumen['fallidos']) }}</h3>
                    <p class="mb-0">Fallidos</p>
                </div>
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box {{ $resumen['omitidos'] > 0 ? 'bg-gradient-warning' : 'bg-gradient-light' }}">
                <div class="inner">
                    <h3>{{ number_format($resumen['omitidos']) }}</h3>
                    <p class="mb-0">No se enviaron</p>
                </div>
                <div class="icon"><i class="fas fa-ban"></i></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="small-box bg-gradient-info">
                <div class="inner">
                    <h3>{{ number_format($resumen['hoy']) }}</h3>
                    <p class="mb-0">Hoy</p>
                </div>
                <div class="icon"><i class="fas fa-calendar-day"></i></div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- ---------- Configuración ---------- --}}
        <div class="col-12 col-xl-5">
            <form method="POST" action="{{ route('mail.settings.update') }}">
                @csrf
                @method('PUT')

                <div class="card shadow-sm">
                    <div class="card-header py-2 bg-primary text-white">
                        <h3 class="card-title mb-0"><i class="fas fa-cog mr-1"></i> Servidor de salida</h3>
                    </div>
                    <div class="card-body">

                        {{-- EL INTERRUPTOR, ARRIBA DEL TODO.
                             Es lo que más rápido hay que poder encontrar
                             el día que algo se está mandando mal. --}}
                        <div class="custom-control custom-switch custom-switch-lg mb-3">
                            <input type="checkbox" class="custom-control-input" id="enabled" name="enabled"
                                   value="1" @checked(old('enabled', $ajustes->enabled))>
                            <label class="custom-control-label" for="enabled">
                                <strong>Enviar correos</strong>
                                <small class="d-block text-muted">
                                    Al apagarlo no sale ni uno, de ningún tipo.
                                </small>
                            </label>
                        </div>

                        <hr>

                        <div class="form-group">
                            <label for="preset">Proveedor</label>
                            <select name="preset" id="preset" class="form-control">
                                <option value="">— Elija para rellenar el servidor y el puerto —</option>
                                @foreach($preajustes as $clave => $datos)
                                    <option value="{{ $clave }}"
                                            data-host="{{ $datos['host'] }}"
                                            data-port="{{ $datos['port'] }}"
                                            data-encryption="{{ $datos['encryption'] }}"
                                            data-nota="{{ $datos['nota'] }}"
                                            @selected(old('preset', $ajustes->preset) === $clave)>
                                        {{ $datos['etiqueta'] }}
                                    </option>
                                @endforeach
                            </select>
                            <div id="notaProveedor" class="alert alert-warning py-2 mt-2 mb-0" style="display:none;"></div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-8">
                                <label for="host">Servidor (host)</label>
                                <input type="text" name="host" id="host" class="form-control"
                                       value="{{ old('host', $ajustes->host) }}"
                                       placeholder="{{ $delEntorno['host'] ?: 'smtp.ejemplo.com' }}">
                                @error('host')<small class="text-danger">{{ $message }}</small>@enderror
                            </div>
                            <div class="form-group col-4">
                                <label for="port">Puerto</label>
                                <input type="number" name="port" id="port" class="form-control"
                                       value="{{ old('port', $ajustes->port) }}"
                                       placeholder="{{ $delEntorno['port'] ?: '587' }}">
                                @error('port')<small class="text-danger">{{ $message }}</small>@enderror
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="encryption">Cifrado</label>
                            <select name="encryption" id="encryption" class="form-control">
                                <option value="tls" @selected(old('encryption', $ajustes->encryption) === 'tls')>TLS (puerto 587)</option>
                                <option value="ssl" @selected(old('encryption', $ajustes->encryption) === 'ssl')>SSL (puerto 465)</option>
                                <option value="" @selected(old('encryption', $ajustes->encryption) === '')>Ninguno</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="username">Usuario</label>
                            <input type="text" name="username" id="username" class="form-control"
                                   value="{{ old('username', $ajustes->username) }}" autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="password">Contraseña</label>
                            <input type="password" name="password" id="password" class="form-control"
                                   autocomplete="new-password"
                                   placeholder="{{ $ajustes->password ? '•••••••• (guardada)' : '' }}">
                            <small class="form-text text-muted">
                                {{ $ajustes->password
                                    ? 'Déjela vacía para no cambiarla. No se muestra nunca.'
                                    : 'No se muestra nunca, pero se guarda sin cifrar: quien acceda a la base de datos o a una copia de seguridad puede leerla.' }}
                            </small>
                            {{-- La causa número uno de que esto no funcione a
                                 la primera. Google la enseña en grupos de
                                 cuatro y la gente la pega con los espacios. --}}
                            <small class="form-text text-muted" id="pistaGmail" style="display:none;">
                                <i class="fas fa-key"></i>
                                En Gmail NO sirve la contraseña del correo: hace falta una
                                <strong>contraseña de aplicación</strong> (16 letras), creada en
                                <em>Cuenta de Google → Seguridad</em> con la verificación en dos pasos
                                activada, y de <strong>la misma cuenta</strong> que puso arriba como usuario.
                            </small>
                        </div>

                        <hr>

                        <div class="form-group">
                            <label for="from_address">Remitente</label>
                            <input type="email" name="from_address" id="from_address" class="form-control"
                                   value="{{ old('from_address', $ajustes->from_address) }}"
                                   placeholder="{{ $delEntorno['from'] ?: 'facturacion@sudominio.com' }}">
                            <small class="form-text text-muted">
                                La dirección desde la que el cliente ve llegar el correo. Debe ser de un
                                dominio que el proveedor tenga verificado, o irá a no deseados.
                            </small>
                            @error('from_address')<small class="text-danger">{{ $message }}</small>@enderror
                        </div>

                        <div class="form-group">
                            <label for="from_name">Nombre del remitente</label>
                            <input type="text" name="from_name" id="from_name" class="form-control"
                                   value="{{ old('from_name', $ajustes->from_name) }}"
                                   placeholder="{{ config('app.name') }}">
                        </div>

                        <div class="form-group mb-0">
                            <label for="per_minute">Correos por minuto</label>
                            <input type="number" name="per_minute" id="per_minute" class="form-control"
                                   value="{{ old('per_minute', $ajustes->per_minute) }}" placeholder="sin límite">
                            <small class="form-text text-muted">
                                Una corrida de facturación encola cientos de correos de golpe y casi todos
                                los proveedores tienen un límite por segundo. Dejarlo vacío es «sin freno».
                            </small>
                            @error('per_minute')<small class="text-danger">{{ $message }}</small>@enderror
                        </div>
                    </div>
                    <div class="card-footer py-2 d-flex justify-content-between align-items-center flex-wrap">
                        <small class="text-muted">
                            Lo que se deje en blanco sigue saliendo del <code>.env</code> del servidor.
                        </small>
                        <button class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar
                        </button>
                    </div>
                </div>
            </form>

            {{-- ---------- Prueba ---------- --}}
            <div class="card shadow-sm">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-vial mr-1"></i> Probar el envío</h3>
                </div>
                <form method="POST" action="{{ route('mail.settings.test') }}" class="card-body">
                    @csrf
                    <p class="text-muted">
                        Guarde primero. La prueba sale <strong>en el momento</strong>, no por la cola, para
                        que el error del servidor se vea aquí y no en un log.
                    </p>
                    <div class="input-group">
                        <input type="email" name="destino" class="form-control"
                               value="{{ old('destino', auth()->user()->email) }}"
                               placeholder="correo@donde.recibirlo" required>
                        <div class="input-group-append">
                            <button class="btn btn-outline-primary">
                                <i class="fas fa-paper-plane"></i> Enviar prueba
                            </button>
                        </div>
                    </div>
                    @error('destino')<small class="text-danger">{{ $message }}</small>@enderror
                </form>
            </div>
        </div>

        {{-- ---------- Bitácora ---------- --}}
        <div class="col-12 col-xl-7">
            <div class="card shadow-sm">
                <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <h3 class="card-title mb-0">
                        <i class="fas fa-list mr-1"></i> Bitácora de envíos
                        <span class="badge badge-secondary ml-1">{{ $logs->total() }}</span>
                    </h3>
                </div>

                <form method="GET" class="card-body py-2 border-bottom">
                    <div class="form-row align-items-end">
                        <div class="col-md-4 form-group mb-0">
                            <label class="mb-1 small">Estado</label>
                            <select name="estado" class="form-control form-control-sm">
                                <option value="">Todos</option>
                                <option value="enviado" @selected(request('estado') === 'enviado')>Enviados</option>
                                <option value="fallido" @selected(request('estado') === 'fallido')>Fallidos</option>
                                <option value="omitido" @selected(request('estado') === 'omitido')>No enviados</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-0">
                            <label class="mb-1 small">Buscar</label>
                            <input type="text" name="buscar" class="form-control form-control-sm"
                                   value="{{ request('buscar') }}" placeholder="Destinatario, asunto o tipo">
                        </div>
                        <div class="col-md-2 form-group mb-0">
                            <button class="btn btn-primary btn-sm btn-block">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Cuándo</th>
                                <th>Para</th>
                                <th>Qué</th>
                                <th class="text-center">Estado</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($logs as $log)
                                <tr>
                                    <td class="text-nowrap">
                                        {{ $log->created_at->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="romper-texto">{{ $log->to }}</td>
                                    <td class="romper-texto">
                                        {{ $log->subject ?: '—' }}
                                        @if($log->context)
                                            <small class="d-block text-muted">{{ $log->context }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-{{ $log->colorDeEstado() }}">
                                            {{ $log->etiquetaDeEstado() }}
                                        </span>

                                        {{-- EL MOTIVO, QUE ES LO QUE SE VIENE A BUSCAR.
                                             En claro arriba y el del servidor debajo: el
                                             traducido se entiende, el original es el
                                             único que sirve cuando el fallo no es
                                             ninguno de los conocidos. --}}
                                        @if($log->error)
                                            @if($log->motivoEnClaro())
                                                <small class="d-block text-danger mt-1">
                                                    {{ $log->motivoEnClaro() }}
                                                </small>
                                            @endif
                                            <small class="d-block text-muted" style="font-size:.72rem;">
                                                {{ \Illuminate\Support\Str::limit($log->error, 160) }}
                                            </small>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        Todavía no hay envíos registrados.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-footer py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <form method="POST" action="{{ route('mail.log.prune') }}" class="form-inline"
                          onsubmit="return confirm('¿Borrar los registros más antiguos que el plazo indicado?');">
                        @csrf
                        @method('DELETE')
                        <small class="text-muted mr-2">Borrar lo anterior a</small>
                        <select name="dias" class="form-control form-control-sm mr-2">
                            <option value="30">30 días</option>
                            <option value="90" selected>90 días</option>
                            <option value="180">180 días</option>
                            <option value="365">1 año</option>
                        </select>
                        <button class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-broom"></i> Podar
                        </button>
                    </form>

                    <div>{{ $logs->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
    <style>
        .custom-switch-lg .custom-control-label::before { width: 2.5rem; height: 1.35rem; border-radius: 1rem; }
        .custom-switch-lg .custom-control-label::after  { width: 1.05rem; height: 1.05rem; border-radius: 1rem; }
        .custom-switch-lg .custom-control-input:checked ~ .custom-control-label::after {
            transform: translateX(1.15rem);
        }
        .romper-texto { word-break: break-word; }
    </style>
@endsection

@section('js')
    <script>
        /**
         * Elegir proveedor rellena servidor, puerto y cifrado, y enseña
         * su aviso de límites. Quien no sabe de SMTP no tiene por qué
         * adivinar un puerto, y quien elige Gmail tiene que leer que
         * son 500 al día antes de confiarle mil facturas.
         */
        document.getElementById('preset').addEventListener('change', function () {
            const op = this.options[this.selectedIndex];
            const nota = document.getElementById('notaProveedor');

            if (!this.value) {
                nota.style.display = 'none';
                return;
            }

            if (op.dataset.host) { document.getElementById('host').value = op.dataset.host; }
            if (op.dataset.port) { document.getElementById('port').value = op.dataset.port; }
            if (op.dataset.encryption !== undefined) {
                document.getElementById('encryption').value = op.dataset.encryption;
            }

            nota.textContent = op.dataset.nota || '';
            nota.style.display = op.dataset.nota ? 'block' : 'none';

            document.getElementById('pistaGmail').style.display =
                this.value === 'gmail' ? 'block' : 'none';
        });

        // Al cargar, si ya venía guardado Gmail
        if (document.getElementById('preset').value === 'gmail') {
            document.getElementById('pistaGmail').style.display = 'block';
        }
    </script>
@endsection
