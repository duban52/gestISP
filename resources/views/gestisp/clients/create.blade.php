@extends('adminlte::page')

@section('title', 'Crear cliente')

@section('content_header')
    <div class="card p-3">
        <h2>CREAR NUEVO CLIENTE</h2>
    </div>

@endsection


@section('content')
    @if(session('success-create'))
        <div class="alert alert-info">
            {{ session('success-create') }}
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('clients.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="row">
                    <div class="mb-3 col-md-6">
                        <label for="type_client" class="form-label">Tipo de cliente</label>
                        <select class="form-select form-control" aria-label="Default select example" id="type_client" name="type_client">
                            <option selected>Seleccione una opción</option>
                            <option value="Persona Natural">Persona Natural</option>
                            <option value="Persona Jurídica">Persona Jurídica</option>
                        </select>
                    </div>

                    {{-- ============================================================
                         TIPO DE DOCUMENTO

                         Sale del catalogo de la DIAN (TipoIdFiscal), no de
                         una lista escrita a mano. Dos motivos:

                         1. Los codigos cambian por resolucion, y una lista
                            en el codigo exige un despliegue para
                            actualizarla.
                         2. La lista que habia tenia un fallo silencioso:
                            una opcion decia «Pasaporte» y guardaba
                            «Persona Juridica».
                         ============================================================ --}}
                    <div class="mb-3  col-md-6">
                        <label for="document_type_code" class="form-label">
                            Tipo de documento <span class="text-danger">*</span>
                        </label>
                        <select class="form-select form-control @error('document_type_code') is-invalid @enderror"
                                id="document_type_code" name="document_type_code" required>
                            <option value="">Seleccione una opción</option>
                            @foreach(\App\Models\FiscalCatalog::opciones(\App\Models\FiscalCatalog::TIPO_DOCUMENTO) as $codigo => $nombre)
                                <option value="{{ $codigo }}" @selected(old('document_type_code') === $codigo)>
                                    {{ $nombre }}
                                </option>
                            @endforeach
                        </select>
                        @error('document_type_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>


                    <div class="mb-3  col-md-6">
                        <label for="identity_number" class="form-label">Número de identidad</label>
                        <input type="text" class="form-control" id="identity_number" placeholder="1234567890" name="identity_number">
                    </div>
                    <div class="mb-3  col-md-6">
                        <label for="name" class="form-label">Nombres</label>
                        <input type="text" class="form-control" id="name" placeholder="Introduce primer y segundo nombre" name="name">
                    </div>
                    <div class="mb-3  col-md-6">
                        <label for="last_name" class="form-label">Apellidos</label>
                        <input type="text" class="form-control" id="last_name" placeholder="Introduce primer y segundo apellido" name="last_name">
                    </div>
                    <div class="mb-3  col-md-6">
                        <label for="number_phone" class="form-label">Número de teléfono</label>
                        <input type="text" class="form-control" id="number_phone" placeholder="Introduce el número de teléfono" name="number_phone">
                    </div>
                    <div class="mb-3  col-md-6">
                        <label for="aditional_phone" class="form-label">Número de teléfono adicional</label>
                        <input type="text" class="form-control" id="aditional_phone" placeholder="Introduce el número de teléfono adicional" name="aditional_phone">
                    </div>
                    <div class="mb-3  col-md-6">
                        <label for="email" class="form-label">Correo Electrónico</label>
                        <input type="email" class="form-control" id="email" placeholder="Introduce el correo electrónico" name="email">
                    </div>
                    <div class="mb-3  col-md-6">
                        <label for="birthday" class="form-label">Fecha de nacimiento</label>
                        <input type="date" class="form-control" id="birthday" name="birthday">
                    </div>
                    <div class="col-12 text-center">
                        <x-campos-fiscales-cliente />

                        <input type="submit" value="Agregar cliente" class="btn btn-primary">
                    </div>

                </div>



            </form>
        </div>
    </div>
@endsection


