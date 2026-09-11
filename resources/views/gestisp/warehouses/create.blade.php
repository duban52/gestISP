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
                    <label for="user_id" class="form-label">Dueño del almacén</label>
                    <select class="form-control form-select" name="user_id" id="user_id">
                        <option value="">— Almacén general (sin dueño) —</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('user_id') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} {{ $user->last_name }}
                            </option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">
                        {{-- El dueño NO es quien lo crea: puede crearlo la oficina
                             y pertenecer a un tecnico. --}}
                        Quien lo crea y quien lo posee son cosas distintas: puede crearlo usted
                        y que pertenezca a otra persona. <strong>Déjelo en general</strong> si es
                        una bodega, el almacén de cabecera o cualquier otro que no sea de nadie
                        en particular.
                        <br>
                        De los almacenes con dueño sale el material de sus órdenes técnicas. Un
                        técnico puede tener varios; al procesar la orden se le pregunta de cuál
                        se descuenta.
                    </small>
                </div>
                <div class="text-center mt-3">
                    <input type="submit" class="btn btn-primary col-md-3" value="Crear almacén">
                </div>
            </form>
        </div>
    </div>
@endsection
