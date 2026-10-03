@extends('adminlte::page')

@section('title', 'Editar servicio')
{{-- Activa el Select2 de AdminLTE: lo usa la unidad de medida del
     componente de datos fiscales, que tiene 1.093 códigos. --}}
@section('plugins.Select2', true)

@section('content_header')
    <div class="card p-3">
        <h2>EDITAR SERVICIO</h2>
    </div>
@endsection

@section('content')
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('services.update', $service) }}" enctype="multipart/form-data">
                @method('PUT')
                @csrf
                <x-ambito-catalogo que="servicio" :actual="$service" />

                <div class="form-group">
                    <label for="name">Nombre del servicio</label>
                    <input type="text" class="form-control" id="name" name='name'
                           placeholder="Ingrese el nombre del servicio" minlength="5" maxlength="255"
                           value="{{ $service->name }}">
                    @error('name')
                    <span class="text-danger">
                    <span>* {{ $message }}</span>
                </span>
                    @enderror
                </div>


                <div class="form-group">
                    <label>Precio base</label>
                    <input type="text" class="form-control" id="base_price" name='base_price'
                           placeholder="27.731,09"
                           value="{{ $service->base_price }}">
                    <small class="form-text text-muted">
                        Se puede escribir con separador de miles y decimales:
                        <code>27.731,09</code>, <code>27731,09</code> o <code>27731.09</code>.
                    </small>

                    @error('base_price')
                    <span class="text-danger">
                    <span>* {{ $message }}</span>
                </span>
                    @enderror

                </div>

                <div class="form-group">
                    <label>Porcentaje IVA</label>
                    <input type="text" class="form-control" id="tax_percentage" name='tax_percentage'
                           placeholder="19"
                           value="{{ $service->tax_percentage }}">
                    <small class="form-text text-muted">
                        El número del porcentaje: <code>19</code> para el 19%, <code>0</code> si no lleva IVA.
                    </small>

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
                            <option value="{{ $valor }}" @selected(old('tax_classification', $service->clasificacion()->value) === $valor)>
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
                    <x-campos-fiscales-servicio :servicio="$service" />
                </div>

                <div class="col-12 text-center">
                    <input type="submit" value="Actualizar Servicio" class="btn btn-primary col-md-3">
                </div>

            </form>

            <form action="{{ route('services.destroy', $service) }}" method="POST">
                @csrf
                @method('DELETE')
                <div class="col-12 text-center mt-2">
                    <input type="submit" value="Eliminar" class="btn btn-danger col-md-3" onclick="return confirmDelete();">
                </div>
            </form>
        </div>
    </div>
@endsection

@section('js')
    <script>
        function confirmDelete() {
            return confirm('Esta es una acción drástica, después de eliminar no habrá vuelta atrás, ¿está seguro?');
        }
    </script>
@endsection


