@extends('adminlte::page')

@section('title', 'Crear servicio')
{{-- Activa el Select2 de AdminLTE: lo usa la unidad de medida del
     componente de datos fiscales, que tiene 1.093 códigos. --}}
@section('plugins.Select2', true)

@section('content_header')
    <div class="card p-3">
        <h2>CREAR SERVICIO</h2>
    </div>

@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('services.store') }}" enctype="multipart/form-data">
                @csrf
                {{-- En panel consolidado no hay una sucursal activa: hay que
                     decir en cual se guarda. Con una sola alcanzable no se
                     pinta nada y el controlador la asume. --}}
                <x-selector-sucursal titulo="Sucursal del servicio"
                                     ayuda="El servicio queda en esta sucursal y solo se podra anadir a sus planes." />

                <div class="form-group">
                    <label for="name">Nombre del servicio</label>
                    <input type="text" class="form-control" id="name" name='name'
                           placeholder="Ingrese el nombre del servicio" minlength="5" maxlength="255"
                           value="{{ old('name') }}">
                    @error('name')
                    <span class="text-danger">
                    <span>* {{ $message }}</span>
                </span>
                    @enderror
                </div>


                <div class="form-group">
                    <label>Precio base</label>
                    <input type="text" class="form-control" id="base_price" name='base_price'
                           placeholder="Ingrese el precio base del servicio"
                           value="{{ old('base_price') }}">

                    @error('base_price')
                    <span class="text-danger">
                    <span>* {{ $message }}</span>
                </span>
                    @enderror

                </div>

                <div class="form-group">
                    <label>Porcentaje IVA (si es el 19%, ingrese 0.19)</label>
                    <input type="text" class="form-control" id="tax_percentage" name='tax_percentage'
                           placeholder="Ingrese el porcentaje de IVA"
                           value="{{ old('tax_percentage') }}">

                    @error('tax_percentage')
                    <span class="text-danger">
                    <span>* {{ $message }}</span>
                </span>
                    @enderror

                </div>


                <div class="form-group">
                    <label for="tax_classification">Tratamiento del IVA</label>
                    <select class="form-control" id="tax_classification" name="tax_classification">
                        @foreach(\App\Billing\Enums\TaxClassification::opciones() as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(old('tax_classification', null) === $valor)>
                                {{ $etiqueta }}
                            </option>
                        @endforeach
                    </select>
                    @error('tax_classification')
                    <span class="text-danger"><span>* {{ $message }}</span></span>
                    @enderror
                    <small class="form-text text-muted">
                        <strong>Excluido</strong>: la ley no lo sujeta a IVA — es el caso del internet
                        residencial de estratos 1, 2 y 3. En el XML no lleva bloque de impuestos.<br>
                        <strong>Exento</strong>: sujeto pero a tarifa 0%. Sí lleva el bloque, en ceros,
                        y el IVA de las compras sí se puede descontar.<br>
                        Si no es gravado, deje el porcentaje en 0.
                    </small>
                </div>
                <div class="col-12 mb-3">
                    <x-campos-fiscales-servicio />
                </div>

                <div class="col-12 text-center">
                    <input type="submit" value="Agregar Servicio" class="btn btn-primary col-md-3">
                </div>



            </form>
        </div>
    </div>
@endsection


