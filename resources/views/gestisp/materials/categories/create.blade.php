@extends('adminlte::page')

@section('title', 'Editar categoría')

@section('content_header')
    <div class="card p-3">
        <h2>CREAR CATEGORÍA</h2>
    </div>

@endsection

@section('content')
    <div class="card p-3">
        <div>
            <form class="" action="{{route('categories.store')}}" method="post">
                @csrf
                {{-- En panel consolidado no hay una sucursal activa: hay que
                     decir en cual se guarda. Con una sola alcanzable no se
                     pinta nada y el controlador la asume. --}}
                <x-selector-sucursal titulo="Sucursal de la categoria"
                                     ayuda="El nombre solo tiene que ser unico dentro de la sucursal que elija." />

                <div>
                    <label for="" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="name" name="name">
                </div>
                <div>
                    <label for="" class="form-label">Descripción</label>
                    <input type="text" class="form-control" id="description" name="description">
                </div>

                <div class="text-center mt-3">
                    <input type="submit" class="btn btn-primary col-md-3" value="Crear Categoría">
                </div>
            </form>
        </div>
    </div>
@endsection

