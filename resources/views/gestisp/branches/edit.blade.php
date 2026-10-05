@extends('adminlte::page')

@section('title', 'Editar Sucursal')
{{-- El buscador de los selects de departamento y municipio. --}}
@section('plugins.Select2', true)
@section('content_header')
    <div class="card p-3"><h2>EDITAR SUCURSAL</h2></div>
@endsection
@section('content')
    <div class="card">
        <div class="card-body">
            <form action="{{ route('branches.update', $branch->id) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <div class="row">
                    <div class="form-group col-md-6">
                        <label for="company_id">Empresa <span class="text-danger">*</span></label>
                        <select name="company_id" id="company_id"
                                class="form-control @error('company_id') is-invalid @enderror" required>
                            @foreach($empresas as $emp)
                                <option value="{{ $emp->id }}"
                                        @selected(old('company_id', $branch->company_id) == $emp->id)>
                                    {{ $emp->nombreVisible() }} — NIT {{ $emp->identificacion() }}
                                </option>
                            @endforeach
                        </select>
                        @error('company_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Mover una sucursal de empresa cambia el NIT con el que factura.
                        </small>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="name">Nombre</label>
                        <input type="text" name="name" class="form-control" value="{{ $branch->name }}" required>
                    </div>
                    {{-- Prefijo de la numeración de contratos de esta
                         sucursal. Cambiarlo NO renumera los contratos
                         existentes: solo afecta a los siguientes. --}}
                    <div class="form-group col-md-6">
                        <label for="contract_prefix">Prefijo del número de contrato</label>
                        <input type="text" name="contract_prefix" id="contract_prefix"
                               class="form-control text-uppercase @error('contract_prefix') is-invalid @enderror"
                               value="{{ old('contract_prefix', $branch->contract_prefix) }}"
                               maxlength="10" placeholder="Ej: ENG">
                        <small class="form-text text-muted">
                            Letras que anteceden al consecutivo. Con <strong>ENG</strong> los contratos
                            quedan como <strong>ENG000001</strong>.
                            @if($branch->contract_next_number)
                                Último número entregado: <strong>{{ $branch->contract_next_number }}</strong>.
                            @endif
                            Cambiarlo no modifica los contratos ya creados.
                        </small>
                        @error('contract_prefix')
                            <span class="invalid-feedback d-block">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="country">País</label>
                        <input type="text" name="country" class="form-control" value="{{ $branch->country }}" required>
                    </div>
                    @include('gestisp.partials.departamento-municipio', [
                        'departamento' => $branch->department,
                        'municipio' => $branch->municipality,
                    ])
                    <div class="form-group col-12">
                        @include('gestisp.partials.direccion', ['valor' => $branch->address, 'requerido' => true])
                    </div>
                    <div class="form-group col-md-6">
                        <label for="number_phone">Teléfono</label>
                        <input type="text" name="number_phone" class="form-control" value="{{ $branch->number_phone }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="additional_number">Teléfono Adicional</label>
                        <input type="text" name="additional_number" class="form-control" value="{{ $branch->additional_number }}">
                    </div>
                    {{-- El logo se administra en la EMPRESA, no aqui.
                         Ver companies/_form.blade.php --}}
                    <div class="form-group col-md-6">
                        <label>Logo</label>
                        <div>
                            @if($branch->company?->logo)
                                <img src="{{ asset('storage/' . $branch->company->logo) }}"
                                     style="max-height: 70px" class="mb-1">
                            @endif
                            <small class="form-text text-muted">
                                El logo es de la empresa
                                <a href="{{ route('companies.edit', $branch->company_id) }}">{{ $branch->company?->nombreVisible() }}</a>
                                y lo comparten todas sus sucursales.
                            </small>
                        </div>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="reconnection_price">Precio de Reconexión</label>
                        <input type="number" step="0.01" name="reconnection_price" class="form-control" value="{{ $branch->reconnection_price }}">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="message_custom_invoice">Mensaje Personalizado</label>
                        <textarea name="message_custom_invoice" class="form-control">{{ $branch->message_custom_invoice }}</textarea>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="observation">Observaciones</label>
                        <textarea name="observation" class="form-control">{{ $branch->observation }}</textarea>
                    </div>

                    {{-- ============================================================
                         Configuración de facturación de la sucursal

                         Reglas que consumen los servicios de facturación
                         (app/Billing/Services) — modificables sin tocar código.
                         ============================================================ --}}
                    <div class="col-12">
                        <div class="card border-primary mt-2">
                            <div class="card-header py-2">
                                <strong><i class="fas fa-file-invoice-dollar"></i> Configuración de facturación</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="form-group col-md-6">
                                        <label for="proration_mode">Facturación del primer mes</label>
                                        <select name="proration_mode" id="proration_mode" class="form-control" required>
                                            @foreach($prorationModes as $mode)
                                                <option value="{{ $mode->value }}"
                                                    {{ old('proration_mode', $billingSettings->proration_mode->value) === $mode->value ? 'selected' : '' }}>
                                                    {{ $mode->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            Prorratear: un contrato activado el día 20 paga solo los días
                                            restantes del mes. Mes completo: paga el mes entero.
                                        </small>
                                    </div>
                                    {{-- ============================================================
                                         HASTA QUÉ DÍA SE PRORRATEA

                                         «Si entra antes del 16 le cobro los días;
                                         después, se los regalo.» Cobrar cinco días
                                         cuesta más —la visita, el recibo— de lo que
                                         se recauda.

                                         31 = siempre se prorratea, que es lo de
                                         antes. Regalar días lo decide alguien.
                                         ============================================================ --}}
                                    <div class="form-group col-md-3" id="grupo_proration_day">
                                        <label for="proration_day">Prorratear hasta el día</label>
                                        <input type="number" name="proration_day" id="proration_day"
                                               class="form-control" min="1" max="31"
                                               value="{{ old('proration_day', $billingSettings->proration_day ?? 31) }}">
                                        <small class="form-text text-muted">
                                            Quien entre después empieza a facturarse en la corrida
                                            siguiente, con el mes completo. Con 31 siempre se prorratea.
                                        </small>
                                    </div>
                                    {{-- ============================================================
                                         QUÉ MES COBRA LA CORRIDA

                                         Es una decisión comercial y hasta ahora
                                         estaba escrita en el código: se cobraba
                                         siempre el mes en el que se corría. Un ISP
                                         que cobra por adelantado —lo normal— no
                                         tenía cómo decirlo.

                                         Cambiarlo NO reescribe nada de lo ya
                                         facturado: manda sobre la siguiente corrida.
                                         ============================================================ --}}
                                    <div class="form-group col-md-6">
                                        <label for="billing_cycle">Qué mes se cobra</label>
                                        <select name="billing_cycle" id="billing_cycle" class="form-control" required>
                                            @foreach($billingCycles as $ciclo)
                                                <option value="{{ $ciclo->value }}"
                                                    {{ old('billing_cycle', $billingSettings->billing_cycle?->value ?? 'current') === $ciclo->value ? 'selected' : '' }}>
                                                    {{ $ciclo->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            Corriendo en septiembre: anticipado cobra octubre,
                                            en curso cobra septiembre, vencido cobra agosto.
                                        </small>
                                    </div>
                                    {{-- Manual o automática. En manual la corrida solo
                                         sale del botón; en automática la lanza sola la
                                         tarea diaria el día indicado. --}}
                                    <div class="form-group col-md-4">
                                        <label for="billing_mode">Cómo se factura</label>
                                        <select name="billing_mode" id="billing_mode" class="form-control" required>
                                            @foreach($billingModes as $modo)
                                                <option value="{{ $modo->value }}"
                                                    {{ old('billing_mode', $billingSettings->billing_mode?->value ?? 'manual') === $modo->value ? 'selected' : '' }}>
                                                    {{ $modo->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            En manual no cambia nada: se sigue generando con el botón.
                                        </small>
                                    </div>
                                    <div class="form-group col-md-2" id="grupo_billing_day">
                                        <label for="billing_day">Día del mes</label>
                                        <input type="number" name="billing_day" id="billing_day" class="form-control"
                                               min="1" max="31"
                                               value="{{ old('billing_day', $billingSettings->billing_day) }}">
                                        <small class="form-text text-muted">
                                            Si pone 31, en los meses cortos corre el último día.
                                        </small>
                                    </div>

                                    <div class="form-group col-md-2">
                                        <label for="due_days">Días de plazo</label>
                                        <input type="number" name="due_days" id="due_days" class="form-control"
                                               min="1" max="90"
                                               value="{{ old('due_days', $billingSettings->due_days) }}" required>
                                        <small class="form-text text-muted">Vencimiento desde la emisión</small>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label for="suspension_threshold">Umbral de corte</label>
                                        <input type="number" name="suspension_threshold" id="suspension_threshold" class="form-control"
                                               min="1" max="12"
                                               value="{{ old('suspension_threshold', $billingSettings->suspension_threshold) }}" required>
                                        <small class="form-text text-muted">Facturas vencidas para suspender</small>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label for="suspension_days">Días hasta el corte</label>
                                        <input type="number" name="suspension_days" id="suspension_days" class="form-control"
                                               min="1" max="90"
                                               value="{{ old('suspension_days', $billingSettings->suspension_days) }}" required>
                                        <small class="form-text text-muted">Con facturas vencidas</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 text-center mt-3">
                        <button  type="submit" class="btn btn-primary col-md-3">Actualizar</button>
                    </div>

                    {{-- El día solo tiene sentido en automático: enseñarlo en
                         manual haría creer que la sucursal está programada. --}}
                    @push('js')
                        <script>
                            (function () {
                                const modo = document.getElementById('billing_mode');
                                const grupo = document.getElementById('grupo_billing_day');

                                const pintar = () => {
                                    grupo.style.display = modo.value === 'automatic' ? '' : 'none';
                                };

                                modo.addEventListener('change', pintar);
                                pintar();
                            })();
                        </script>
                    @endpush

                </div>

            </form>
        </div>
    </div>

    {{-- ============================================================
         EL CORREO QUE VE EL CLIENTE

         Fuera del formulario de arriba a propósito: es HTML inválido
         anidar formularios, y además el guardado de la sucursal
         arrastra reglas de facturación que no tienen por qué fallar
         por un campo de correo.

         Lo que se configura aquí sale en las facturas, los avisos de
         vencimiento y las órdenes técnicas de ESTA sede. El
         restablecimiento de contraseña de un usuario del panel NO:
         ese es correo interno y sale del de Gestión del sistema.
         ============================================================ --}}
    @php
        $correoSede = \App\Models\MailSetting::deLaSucursal($branch->id);
        $preajustes = \App\Models\MailSetting::PREAJUSTES;
    @endphp

    <div class="card shadow-sm">
        <div class="card-header py-2 bg-primary text-white d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0">
                <i class="fas fa-envelope mr-1"></i> Correo de esta sucursal
            </h3>
            @if($correoSede && !$correoSede->enabled)
                <span class="badge badge-danger">Envío APAGADO</span>
            @elseif($correoSede?->tieneServidorPropio())
                <span class="badge badge-light">Servidor propio</span>
            @else
                <span class="badge badge-secondary">Usa el del sistema</span>
            @endif
        </div>

        <form method="POST" action="{{ route('branches.mail.update', $branch) }}" class="card-body">
            @csrf
            @method('PUT')

            <p class="text-muted">
                Desde aquí salen las <strong>facturas, los avisos de vencimiento y las órdenes
                técnicas de los clientes de esta sede</strong>, con su remitente. Si se deja en
                blanco, se envían por el servidor configurado en
                <em>Gestión del sistema → Envío de correos</em>.
            </p>

            {{-- APAGADO SIGNIFICA QUE NO SALE, no que salga por el del
                 sistema. Es el interruptor general acotado a una sede:
                 sirve para cortar el correo de una sucursal concreta
                 sin dejar a las demás sin facturas. --}}
            <div class="custom-control custom-switch mb-3">
                <input type="checkbox" class="custom-control-input" id="enabled_sede" name="enabled"
                       value="1" @checked(old('enabled', $correoSede?->enabled ?? true))>
                <label class="custom-control-label" for="enabled_sede">
                    <strong>Enviar correos a los clientes de esta sucursal</strong>
                    <small class="d-block text-muted">
                        Al apagarlo no sale ninguno — tampoco por el servidor del sistema.
                        Los intentos quedan anotados en la bitácora.
                    </small>
                </label>
            </div>

            @if($correoSede && !$correoSede->enabled)
                <div class="alert alert-danger py-2">
                    <i class="fas fa-ban"></i>
                    <strong>Esta sucursal no está enviando correos.</strong>
                    Ni facturas, ni avisos de vencimiento, ni órdenes técnicas.
                </div>
            @endif

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="preset_sede">Proveedor</label>
                    <select name="preset" id="preset_sede" class="form-control">
                        <option value="">— Elija para rellenar servidor y puerto —</option>
                        @foreach($preajustes as $clave => $datos)
                            <option value="{{ $clave }}"
                                    data-host="{{ $datos['host'] }}"
                                    data-port="{{ $datos['port'] }}"
                                    data-encryption="{{ $datos['encryption'] }}"
                                    @selected(old('preset', $correoSede?->preset) === $clave)>
                                {{ $datos['etiqueta'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-5">
                    <label for="host_sede">Servidor (host)</label>
                    <input type="text" name="host" id="host_sede" class="form-control"
                           value="{{ old('host', $correoSede?->host) }}"
                           placeholder="Vacío = usa el del sistema">
                </div>
                <div class="form-group col-md-2">
                    <label for="port_sede">Puerto</label>
                    <input type="number" name="port" id="port_sede" class="form-control"
                           value="{{ old('port', $correoSede?->port) }}" placeholder="587">
                </div>
                <div class="form-group col-md-1">
                    <label for="encryption_sede">Cifrado</label>
                    <select name="encryption" id="encryption_sede" class="form-control">
                        <option value="tls" @selected(old('encryption', $correoSede?->encryption) === 'tls')>TLS</option>
                        <option value="ssl" @selected(old('encryption', $correoSede?->encryption) === 'ssl')>SSL</option>
                        <option value="" @selected(old('encryption', $correoSede?->encryption) === '')>—</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="username_sede">Usuario</label>
                    <input type="text" name="username" id="username_sede" class="form-control"
                           value="{{ old('username', $correoSede?->username) }}" autocomplete="off">
                </div>
                <div class="form-group col-md-6">
                    <label for="password_sede">Contraseña</label>
                    <input type="password" name="password" id="password_sede" class="form-control"
                           autocomplete="new-password"
                           placeholder="{{ $correoSede?->password ? '•••••••• (guardada)' : '' }}">
                    <small class="form-text text-muted">
                        {{ $correoSede?->password ? 'Déjela vacía para no cambiarla.' : '' }}
                        Se guarda sin cifrar: quien acceda a la base de datos puede leerla.
                    </small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="from_address_sede">Remitente</label>
                    <input type="email" name="from_address" id="from_address_sede" class="form-control"
                           value="{{ old('from_address', $correoSede?->from_address) }}"
                           placeholder="facturacion@sudominio.com">
                    <small class="form-text text-muted">
                        La dirección desde la que el cliente ve llegar la factura, y a la que
                        responderá. Debe ser de un dominio verificado en el proveedor.
                    </small>
                </div>
                <div class="form-group col-md-6">
                    <label for="from_name_sede">Nombre del remitente</label>
                    <input type="text" name="from_name" id="from_name_sede" class="form-control"
                           value="{{ old('from_name', $correoSede?->from_name) }}"
                           placeholder="{{ $branch->name }}">
                </div>
            </div>

            <button class="btn btn-primary">
                <i class="fas fa-save"></i> Guardar el correo de la sucursal
            </button>
        </form>

        @if($correoSede?->tieneServidorPropio())
            <div class="card-footer py-2">
                <form method="POST" action="{{ route('branches.mail.test', $branch) }}" class="form-inline">
                    @csrf
                    <small class="text-muted mr-2">Probar este servidor enviando a</small>
                    <input type="email" name="destino" class="form-control form-control-sm mr-2"
                           value="{{ auth()->user()->email }}" required style="min-width: 240px;">
                    <button class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-paper-plane"></i> Enviar prueba
                    </button>
                </form>
            </div>
        @endif
    </div>
@endsection

@section('js')
    <script>
        /* Elegir proveedor rellena servidor, puerto y cifrado. */
        document.getElementById('preset_sede')?.addEventListener('change', function () {
            const op = this.options[this.selectedIndex];

            if (!this.value) { return; }

            if (op.dataset.host) { document.getElementById('host_sede').value = op.dataset.host; }
            if (op.dataset.port) { document.getElementById('port_sede').value = op.dataset.port; }
            if (op.dataset.encryption !== undefined) {
                document.getElementById('encryption_sede').value = op.dataset.encryption;
            }
        });
    </script>
@endsection
