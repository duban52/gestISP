{{-- ============================================================
     Alta de cliente

     LO QUE ESTE FORMULARIO HACÍA MAL
     --------------------------------
     · Casi ningún campo usaba `old()`: un error de validación
       devolvía el formulario VACÍO y había que escribirlo todo de
       nuevo.
     · Solo el tipo de documento pintaba su error. Un documento
       repetido o un correo mal escrito recargaban la página sin una
       sola palabra: parecía que el botón no hacía nada.
     · La primera opción de «Tipo de cliente» no tenía `value`, así
       que sin elegir nada se guardaba literalmente el texto
       «Seleccione una opción» como tipo de cliente.

     Los campos son exactamente los que ya existían: no se añade ni
     se quita ninguna columna. Lo que cambia es el orden —por
     secciones—, que cada campo conserva su valor y enseña su error,
     y que los topes del navegador (`maxlength`) son los de las
     columnas reales, para que nadie escriba lo que no va a caber.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Crear cliente')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h1 class="mb-0"><i class="fas fa-user-plus mr-2"></i>Nuevo cliente</h1>
            <small class="text-muted">
                Los campos con <span class="text-danger">*</span> son obligatorios.
            </small>
        </div>
        <a href="{{ route('clients.index') }}" class="btn btn-secondary" data-cancelar>
            <i class="fas fa-arrow-left"></i> Volver al listado
        </a>
    </div>
@endsection

@section('content')

    @if(session('success-create'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success-create') }}
        </div>
    @endif

    {{-- El motivo de un rechazo y el resumen de lo que hay que
         corregir. Cada campo enseña además su propio mensaje. --}}
    @include('gestisp.partials.alerta-error')

    <form method="POST" action="{{ route('clients.store') }}" id="formCliente">
        @csrf

        {{-- ============================================================
             1. Quién es
             ============================================================ --}}
        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-id-card mr-1"></i> Identificación</h3>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-4">
                        <label for="type_client">Tipo de cliente <span class="text-danger">*</span></label>
                        {{-- La opción vacía LLEVA `value=""`. Sin él, el
                             navegador manda el texto de la opción, y se
                             guardaba «Seleccione una opción» como tipo. --}}
                        <select class="form-control @error('type_client') is-invalid @enderror"
                                id="type_client" name="type_client" required>
                            <option value="">Seleccione una opción</option>
                            @foreach(['Persona Natural', 'Persona Jurídica'] as $tipo)
                                <option value="{{ $tipo }}" @selected(old('type_client') === $tipo)>{{ $tipo }}</option>
                            @endforeach
                        </select>
                        @error('type_client')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Sale del catálogo de la DIAN (TipoIdFiscal), no de
                         una lista escrita a mano: los códigos cambian por
                         resolución, y la lista vieja tenía una opción que
                         decía «Pasaporte» y guardaba «Persona Jurídica». --}}
                    <div class="form-group col-md-4">
                        <label for="document_type_code">Tipo de documento <span class="text-danger">*</span></label>
                        <select class="form-control @error('document_type_code') is-invalid @enderror"
                                id="document_type_code" name="document_type_code" required>
                            <option value="">Seleccione una opción</option>
                            @foreach(\App\Models\FiscalCatalog::opciones(\App\Models\FiscalCatalog::TIPO_DOCUMENTO) as $codigo => $nombre)
                                <option value="{{ $codigo }}" @selected(old('document_type_code') === (string) $codigo)>
                                    {{ $nombre }}
                                </option>
                            @endforeach
                        </select>
                        @error('document_type_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group col-md-4">
                        <label for="identity_number">Número de documento <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('identity_number') is-invalid @enderror"
                               id="identity_number" name="identity_number"
                               value="{{ old('identity_number') }}"
                               placeholder="Ej.: 1042772330" maxlength="20" inputmode="numeric"
                               autocomplete="off" required>
                        @error('identity_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Sin puntos ni espacios. No podrá cambiarse después: identifica al cliente.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             2. Cómo se llama
             ============================================================ --}}
        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-user mr-1"></i> Datos personales</h3>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-4">
                        <label for="name">Nombres <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name') }}"
                               placeholder="Primer y segundo nombre" maxlength="40" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group col-md-4">
                        <label for="last_name">Apellidos <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                               id="last_name" name="last_name" value="{{ old('last_name') }}"
                               placeholder="Primer y segundo apellido" maxlength="40" required>
                        @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Una empresa no tiene fecha de nacimiento: el JS la
                         esconde cuando el tipo es Persona Jurídica. --}}
                    <div class="form-group col-md-4" id="bloqueNacimiento">
                        <label for="birthday">Fecha de nacimiento</label>
                        <input type="date" class="form-control @error('birthday') is-invalid @enderror"
                               id="birthday" name="birthday" value="{{ old('birthday') }}"
                               max="{{ now()->toDateString() }}">
                        @error('birthday')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             3. Cómo se le contacta
             ============================================================ --}}
        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-phone-alt mr-1"></i> Contacto</h3>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-4">
                        <label for="number_phone">Teléfono principal <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control @error('number_phone') is-invalid @enderror"
                               id="number_phone" name="number_phone" value="{{ old('number_phone') }}"
                               placeholder="Ej.: 3001234567" maxlength="20" inputmode="tel" required>
                        @error('number_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            A este número se envían los avisos por WhatsApp.
                        </small>
                    </div>

                    <div class="form-group col-md-4">
                        <label for="aditional_phone">Teléfono adicional</label>
                        <input type="tel" class="form-control @error('aditional_phone') is-invalid @enderror"
                               id="aditional_phone" name="aditional_phone" value="{{ old('aditional_phone') }}"
                               placeholder="Opcional" maxlength="20" inputmode="tel">
                        @error('aditional_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group col-md-4">
                        <label for="email">Correo electrónico</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email') }}"
                               placeholder="Opcional: nombre@dominio.com" maxlength="255">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            A este correo llegan las facturas. Puede dejarse en blanco.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             4. Datos fiscales (DIAN) — opcionales, en su propia tarjeta
             ============================================================ --}}
        <x-campos-fiscales-cliente />

        <div class="d-flex justify-content-end flex-wrap mb-4" style="gap: 8px;">
            <a href="{{ route('clients.index') }}" class="btn btn-outline-secondary" data-cancelar>
                Cancelar
            </a>
            <button type="submit" class="btn btn-primary" id="btnGuardarCliente">
                <i class="fas fa-save mr-1"></i> Guardar cliente
            </button>
        </div>
    </form>
@endsection

@section('js')
    @include('gestisp.clients._formulario-js', ['formulario' => 'formCliente', 'boton' => 'btnGuardarCliente'])
@endsection
