@extends('adminlte::page')
@section('title', 'Crear plan')
@section('content_header')
    <div class="card p-3">
        <h2>CREAR PLAN</h2>
    </div>
@endsection
@section('content')
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('plans.store') }}" enctype="multipart/form-data">
                @csrf
                {{-- En panel consolidado no hay una sucursal activa: hay que
                     decir en cual se guarda. Con una sola alcanzable no se
                     pinta nada y el controlador la asume. --}}
                <x-selector-sucursal titulo="Sucursal del plan"
                                     ayuda="El plan se ofrece en esta sucursal: solo aparecera al crear contratos suyos." />

                <div class="form-group">
                    <label for="name">Nombre del plan</label>
                    <input type="text" class="form-control" id="name" name='name'
                           placeholder="Ingrese el nombre del plan" minlength="5" maxlength="255"
                           value="{{ old('name') }}">
                    @error('name')
                    <span class="text-danger">
                    <span>* {{ $message }}</span>
                </span>
                    @enderror
                </div>

                <h3>Lista de servicios</h3>
                @foreach($services as $service)
                    <div>
                        <label>
                            <input type="checkbox" name="services[]" id="" value="{{$service->id}}" class="mr-1">{{ $service->name }}

                        </label>
                    </div>
                @endforeach
                <div class="col-12 text-center">
                    <input type="submit" value="Agregar Plan" class="btn btn-primary col-md-3">
                </div>

            </form>
        </div>
    </div>
@endsection


