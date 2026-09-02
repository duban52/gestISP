@extends('adminlte::page')

@section('title', 'Crear Sucursal')
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
                        <label for="name">Nombre</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="country">País</label>
                        <input type="text" name="country" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="department">Departamento</label>
                        <input type="text" name="department" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="municipality">Municipio</label>
                        <input type="text" name="municipality" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="address">Dirección</label>
                        <input type="text" name="address" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="number_phone">Teléfono</label>
                        <input type="text" name="number_phone" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="additional_number">Teléfono Adicional</label>
                        <input type="text" name="additional_number" class="form-control">
                    </div>
                    {{-- El logo ya no se sube aqui: es de la EMPRESA.
                         Es identidad del contribuyente —las sedes imprimen
                         el mismo— y en panel consolidado no hay una
                         sucursal de la que sacarlo. --}}
                    <div class="form-group col-md-6">
                        <label for="moving_price">Precio de Traslado</label>
                        <input type="number" step="0.01" name="moving_price" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="reconnection_price">Precio de Reconexión</label>
                        <input type="number" step="0.01" name="reconnection_price" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="message_custom_invoice">Mensaje Personalizado</label>
                        <textarea name="message_custom_invoice" class="form-control"></textarea>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="observation">Observaciones</label>
                        <textarea name="observation" class="form-control"></textarea>
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

                </div>

            </form>
        </div>
    </div>
@endsection
