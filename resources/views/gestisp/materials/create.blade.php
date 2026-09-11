@extends('adminlte::page')

@section('title', 'Crear Material')

@section('content_header')
    <div class="card p-3">
        <h2>CREAR MATERIAL</h2>
    </div>

@endsection

@section('content')
    <div class="card p-3">
        <div>
            <form class="" action="{{route('materials.store')}}" method="post">
                @csrf
                {{-- En panel consolidado no hay una sucursal activa: hay que
                     decir en cual se guarda. Con una sola alcanzable no se
                     pinta nada y el controlador la asume. --}}
                <x-selector-sucursal titulo="Sucursal del material"
                                     ayuda="El material se da de alta en el catalogo de esta sucursal." />

                <div>
                    <label for="" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="name" name="name" required>
                </div>
                <div>
                    <label for="" class="form-label">Categoría</label>
                    <select class="form-control form-select" aria-label="Default select example" name="category_id" id="category_id" required>
                        <option selected value="">Seleccione una categoría</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- LA UNIDAD ES DEL MATERIAL.
                     Antes se pedia en CADA movimiento y en cada orden
                     tecnica. Eso es preguntar por algo que no cambia, y
                     permitia ingresar 200 «Unidades» de fibra y sacar 50
                     «Metros» de la misma fibra: las existencias quedaban
                     en una unidad que no significaba nada. --}}
                <div>
                    <label for="unit_of_measurement" class="form-label">
                        Unidad de medida <span class="text-danger">*</span>
                    </label>
                    <select class="form-control @error('unit_of_measurement') is-invalid @enderror"
                            name="unit_of_measurement" id="unit_of_measurement" required>
                        <option value="">Seleccione...</option>
                        @foreach($unidades as $unidad)
                            <option value="{{ $unidad }}" {{ old('unit_of_measurement') === $unidad ? 'selected' : '' }}>
                                {{ $unidad }}
                            </option>
                        @endforeach
                    </select>
                    @error('unit_of_measurement')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">
                            Se declara una sola vez, aquí. En movimientos y órdenes técnicas ya no
                            se pregunta: el sistema la asume para este material.
                        </small>
                </div>

                @can('materials.costs')
                    {{-- EL COSTO NO ES PARA TODO EL MUNDO.
                     Saber cuantas ONT hay en la bodega y saber cuanto se
                     pago por ellas son dos cosas distintas: la segunda es
                     informacion del negocio. Quien no tenga el permiso ni
                     siquiera ve el campo, y `MaterialController::update()`
                     conserva el valor guardado cuando el formulario llega
                     sin el — si no, editar el nombre de un material
                     borraria un precio que esa persona no podia ver. --}}
                    <div>
                        <label for="purchase_unit_value" class="form-label">
                            Valor unitario de compra <small class="text-muted">(opcional)</small>
                        </label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">$</span>
                            </div>
                            <input type="number" step="0.01" min="0"
                                   class="form-control @error('purchase_unit_value') is-invalid @enderror"
                                   id="purchase_unit_value" name="purchase_unit_value"
                                   value="{{ old('purchase_unit_value') }}"
                                   placeholder="Déjelo vacío si no se conoce">
                            @error('purchase_unit_value')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <small class="form-text text-muted">
                            Valor de referencia del catálogo. Se propone al registrar una entrada,
                            pero lo que se totaliza en los inventarios es lo que se pagó en cada ingreso.
                        </small>
                    </div>
                @endcan

                <label for="">¿Es Equipo?</label>
                <div class="form-group">
                    <div class="form-check form-check-inline">
                        <label class="form-check-label" for="">NO</label>
                        <input class="form-check-input ml-2" type="radio" name='is_equipment'
                               id="is_equipment" value="0" checked>
                    </div>

                    <div class="form-check form-check-inline">
                        <label class="form-check-label" for="">SI</label>
                        <input class="form-check-input ml-2" type="radio" name='is_equipment'
                               id="is_equipment" value="1">
                    </div>

                </div>
                <div class="text-center mt-3">
                    <input type="submit" class="btn btn-primary col-md-3" value="Crear Material">
                </div>
            </form>
        </div>
    </div>
@endsection
