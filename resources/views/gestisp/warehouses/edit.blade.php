@extends('adminlte::page')

@section('title', 'Editar almacén')

@section('content_header')
    <div class="card p-3">
        <h2>MODIFICAR ALMACEN</h2>
    </div>

@endsection

@section('content')
    <div class="card p-3">
        <div>
            <form class="" action="{{route('warehouses.update', $warehouse)}}" method="post">
                @csrf
                @method('PUT')
                <div>
                    <label for="" class="form-label">Nombre del almacén</label>
                    <input type="text" class="form-control" id="description" name="description" required value="{{ $warehouse->description }}">
                </div>
                <div>
                    <label for="" class="form-label">Vincular usuario a almacén</label>
                    <select class="form-control form-select" aria-label="Default select example" name="user_id" id="user_id">
                        <option value="">Seleccione un usuario</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected($warehouse->user_id == $user->id)>
                                {{ $user->name }} {{$user->last_name}}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="text-center mt-3">
                    <input type="submit" class="btn btn-primary col-md-3" value="Guardar cambios">
                </div>
            </form>
                <div class="text-center mt-3">
                    <form action="{{ route('warehouses.destroy', $warehouse) }}" method="post" onsubmit="">
                        @csrf
                        @method('DELETE')
                        <input type="submit" class="btn btn-danger col-md-3" value="Eliminar almacén" onclick="return confirmDelete();">
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
