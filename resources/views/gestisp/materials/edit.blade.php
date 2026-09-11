@extends('adminlte::page')

@section('title', 'Editar Material')

@section('content_header')
    <div class="card p-3">
        <h2>EDITAR MATERIAL</h2>
    </div>

@endsection

@section('content')
    <div class="card p-3">
        <div>
            <form class="" action="{{route('materials.update', $material)}}" method="post">
                @csrf
                @method('PUT')
                <div>
                    <label for="" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="name" name="name" value="{{ $material->name }}">
                </div>
                <div>
                    <label for="" class="form-label">Categoría</label>
                    <select class="form-control form-select" aria-label="Default select example" name="category_id" id="category_id" required>
                        <option>Seleccione una categoría</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ $material->category_id == $category->id ? 'selected' : ''}}>
                                {{ $category->name }}

                            </option>
                        @endforeach
                    </select>
                </div>
                {{-- Cambiarla NO reescribe el historico: los movimientos
                     ya registrados conservan la unidad con la que se
                     hicieron. --}}
                <div>
                    <label for="unit_of_measurement" class="form-label">
                        Unidad de medida <span class="text-danger">*</span>
                    </label>
                    <select class="form-control @error('unit_of_measurement') is-invalid @enderror"
                            name="unit_of_measurement" id="unit_of_measurement" required>
                        <option value="">Seleccione...</option>
                        @foreach($unidades as $unidad)
                            <option value="{{ $unidad }}"
                                {{ old('unit_of_measurement', $material->unit_of_measurement) === $unidad ? 'selected' : '' }}>
                                {{ $unidad }}
                            </option>
                        @endforeach
                    </select>
                    @error('unit_of_measurement')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">
                        Cambiarla afecta a los movimientos futuros. Los ya registrados conservan
                        la unidad con la que se hicieron.
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
                                   value="{{ old('purchase_unit_value', $material->purchase_unit_value) }}"
                                   placeholder="Déjelo vacío si no se conoce">
                            @error('purchase_unit_value')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <small class="form-text text-muted">
                            Valor de referencia del catálogo. Cambiarlo NO revalúa el inventario ya
                            registrado: cada existencia conserva lo que costó de verdad.
                        </small>
                    </div>
                @endcan

                <label>¿Es equipo?</label>
                <div class="form-group">
                    <div class="form-check form-check-inline">
                        <label class="form-check-label">NO</label>
                        <input class="form-check-input ml-2" type="radio" name='is_equipment' id="is_equipment" value="0" {{ ($material->is_equipment== 0) ? 'checked' : '' }}>
                    </div>

                    <div class="form-check form-check-inline">
                        <label class="form-check-label">SI</label>
                        <input class="form-check-input ml-2" type="radio" name='is_equipment' id="is_equipment" value="1" {{ ($material->is_equipment== 1) ? 'checked' : '' }}>
                    </div>

                    <span class="text-danger">
                    <span>*</span>
                </span>
                </div>
                <div class="text-center mt-3">
                    <input type="submit" class="btn btn-primary col-md-3" value="Guardar Cambios">
                </div>
            </form>

            <div class="text-center mt-3">
                <form action="{{ route('materials.destroy', $material) }}" method="post">
                    @method('DELETE')
                    @csrf
                    <input type="submit" class="btn btn-danger col-md-3" value="Eliminar Material" onclick="return confirmDelete();">
                </form>
            </div>
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
