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
