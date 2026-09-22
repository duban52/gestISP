@extends('adminlte::page')

@section('title', 'Crear Sucursal')
{{-- El buscador de los selects de departamento y municipio. --}}
@section('plugins.Select2', true)
@section('content_header')
    <div class="card p-3"><h2>CREAR SUCURSAL</h2></div>
@endsection
@section('content')
    <div class="card">

        <div class="card-body">
            <form action="{{ route('branches.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="row">
                    {{-- La sucursal pertenece a una empresa, y de ahi
                         saca su identidad fiscal. El NIT ya no se
                         escribe aqui: se heredaba mal cuando dos sedes
                         del mismo contribuyente lo tenian distinto. --}}
                    <div class="form-group col-md-6">
                        <label for="company_id">Empresa <span class="text-danger">*</span></label>
                        <select name="company_id" id="company_id"
                                class="form-control @error('company_id') is-invalid @enderror" required>
                            <option value="">Seleccione la empresa</option>
                            @foreach($empresas as $emp)
                                <option value="{{ $emp->id }}"
                                        @selected(old('company_id', $empresaElegida) == $emp->id)>
                                    {{ $emp->nombreVisible() }} — NIT {{ $emp->identificacion() }}
                                </option>
                            @endforeach
                        </select>
                        @error('company_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            El NIT sale de la empresa.
                            <a href="{{ route('companies.create') }}">Crear una empresa nueva</a>.
                        </small>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="name">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" maxlength="40" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Las letras del numero de contrato. Se puede dejar en
                         blanco: si no se pone, el sistema lo deduce del nombre
                         de la sucursal. --}}
                    <div class="form-group col-md-6">
                        <label for="contract_prefix">Prefijo del número de contrato</label>
                        <input type="text" name="contract_prefix" id="contract_prefix"
                               class="form-control text-uppercase @error('contract_prefix') is-invalid @enderror"
                               value="{{ old('contract_prefix') }}" maxlength="10"
                               placeholder="Ej. ENG">
                        <small class="form-text text-muted">
                            Solo letras y números. Con «ENG», los contratos salen ENG000001.
                            En blanco, se deduce del nombre.
                        </small>
                        @error('contract_prefix')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="country">País <span class="text-danger">*</span></label>
                        <input type="text" name="country" class="form-control @error('country') is-invalid @enderror"
                               value="{{ old('country', 'Colombia') }}" required>
                        @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @include('gestisp.partials.departamento-municipio')

                    <div class="form-group col-12">
                        @include('gestisp.partials.direccion', ['requerido' => true])
                    </div>
                    <div class="form-group col-md-6">
                        <label for="number_phone">Teléfono <span class="text-danger">*</span></label>
                        <input type="text" name="number_phone" class="form-control @error('number_phone') is-invalid @enderror"
                               value="{{ old('number_phone') }}" required>
                        @error('number_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="additional_number">Teléfono Adicional</label>
                        <input type="text" name="additional_number" class="form-control"
                               value="{{ old('additional_number') }}">
                    </div>
                    {{-- El logo ya no se sube aqui: es de la EMPRESA.
                         Es identidad del contribuyente —las sedes imprimen
                         el mismo— y en panel consolidado no hay una
                         sucursal de la que sacarlo. --}}
                    <div class="form-group col-md-6">
                        <label for="moving_price">Precio de Traslado</label>
                        <input type="number" step="0.01" name="moving_price" class="form-control"
                               value="{{ old('moving_price') }}">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="reconnection_price">Precio de Reconexión</label>
                        <input type="number" step="0.01" name="reconnection_price" class="form-control"
                               value="{{ old('reconnection_price') }}">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="message_custom_invoice">Mensaje Personalizado</label>
                        <textarea name="message_custom_invoice" class="form-control">{{ old('message_custom_invoice') }}</textarea>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="observation">Observaciones</label>
                        <textarea name="observation" class="form-control">{{ old('observation') }}</textarea>
                    </div>

                    {{-- ============================================================
                         Configuración de facturación de la sucursal.

                         Se deja elegir desde el principio: son las reglas con
                         las que va a facturar, y si no aparecen aquí hay que
                         acordarse de entrar a editarla justo después de
                         crearla. Los valores que trae son los de siempre.
                         ============================================================ --}}
                    <div class="col-12">
                        <div class="card border-primary mt-2">
                            <div class="card-header py-2">
                                <strong><i class="fas fa-file-invoice-dollar"></i> Configuración de facturación</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="form-group col-md-4">
                                        <label for="proration_mode">Facturación del primer mes</label>
                                        <select name="proration_mode" id="proration_mode" class="form-control" required>
                                            @foreach($prorationModes as $modo)
                                                <option value="{{ $modo->value }}"
                                                    {{ old('proration_mode', $facturacion['proration_mode']) === $modo->value ? 'selected' : '' }}>
                                                    {{ $modo->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            Prorratear: un contrato activado el día 20 paga solo los días
                                            restantes del mes.
                                        </small>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="billing_mode">Cómo se factura</label>
                                        <select name="billing_mode" id="billing_mode" class="form-control" required>
                                            @foreach($billingModes as $modo)
                                                <option value="{{ $modo->value }}"
                                                    {{ old('billing_mode', $facturacion['billing_mode']) === $modo->value ? 'selected' : '' }}>
                                                    {{ $modo->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            En manual, las facturas salen con el botón «Generar facturas».
                                        </small>
                                    </div>
                                    <div class="form-group col-md-4" id="grupo_billing_day">
                                        <label for="billing_day">Día del mes</label>
                                        <input type="number" name="billing_day" id="billing_day" class="form-control"
                                               min="1" max="31" value="{{ old('billing_day') }}">
                                        <small class="form-text text-muted">
                                            Si pone 31, en los meses cortos corre el último día.
                                        </small>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="due_days">Días de plazo</label>
                                        <input type="number" name="due_days" id="due_days" class="form-control"
                                               min="1" max="90"
                                               value="{{ old('due_days', $facturacion['due_days']) }}" required>
                                        <small class="form-text text-muted">Vencimiento desde la emisión</small>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="suspension_threshold">Umbral de corte</label>
                                        <input type="number" name="suspension_threshold" id="suspension_threshold" class="form-control"
                                               min="1" max="12"
                                               value="{{ old('suspension_threshold', $facturacion['suspension_threshold']) }}" required>
                                        <small class="form-text text-muted">Facturas vencidas para suspender</small>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="suspension_days">Días hasta el corte</label>
                                        <input type="number" name="suspension_days" id="suspension_days" class="form-control"
                                               min="1" max="90"
                                               value="{{ old('suspension_days', $facturacion['suspension_days']) }}" required>
                                        <small class="form-text text-muted">Con facturas vencidas</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 text-center">
                {{-- ============================================================
                     Acceso a la sucursal recién creada

                     El acceso se concede POR SUCURSAL (user_branch), no por
                     empresa. Una sucursal sin usuarios es invisible: no sale
                     en los listados, nadie puede elegirla como contexto y no
                     se puede ni editar.

                     Quien la crea casi siempre la necesita, así que llega
                     marcada; pero es una casilla y no un automatismo, porque
                     conceder acceso es conceder acceso.
                     ============================================================ --}}
                <div class="form-group">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="darme_acceso"
                               name="darme_acceso" value="1" checked>
                        <label class="custom-control-label" for="darme_acceso">
                            Darme acceso a esta sucursal
                            <small class="d-block text-muted">
                                Sin al menos un usuario asignado, nadie podrá entrar a ella.
                            </small>
                        </label>
                    </div>
                </div>

                        <button type="submit" class="btn btn-success col-md-3">Guardar</button>
                    </div>

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
@endsection
