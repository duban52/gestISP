@extends('adminlte::page')

@section('title', 'Crear almacén')

@section('content_header')
    <div class="card p-3">
        <h2>CREAR ALMACEN</h2>
    </div>

@endsection

@section('content')
    <div class="card p-3">
        <div>
            <form class="" action="{{route('warehouses.store')}}" method="post">
                @csrf
                {{-- En panel consolidado no hay una sucursal activa: hay que
                     decir en cual se guarda. Con una sola alcanzable no se
                     pinta nada y el controlador la asume. --}}
                <x-selector-sucursal titulo="Sucursal del almacen"
                                     ayuda="El almacen y sus existencias quedan en esta sucursal." />

                <div>
                    <label for="" class="form-label">Nombre del almacén</label>
                    <input type="text" class="form-control" id="description" name="description" required>
                </div>
                <div>
                    <label for="" class="form-label">Vincular usuario a almacén</label>
                    <select class="form-control form-select" aria-label="Default select example" name="user_id" id="user_id">
                        <option selected value="">Seleccione un usuario</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} {{$user->last_name}}</option>
                        @endforeach
                    </select>
                </div>
                <div class="text-center mt-3">
                    <input type="submit" class="btn btn-primary col-md-3" value="Crear almacén">
                </div>
            </form>
        </div>
    </div>
@endsection
