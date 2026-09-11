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
                    <label for="user_id" class="form-label">Dueño del almacén</label>
                    <select class="form-control form-select" name="user_id" id="user_id">
                        {{-- Dejarlo en general SÍ quita el dueño. Antes se
                             conservaba el que hubiera, así que un almacén
                             asignado por error no se podía devolver a general
                             sin ir a la base de datos. --}}
                        <option value="" @selected(!$warehouse->user_id)>— Almacén general (sin dueño) —</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" @selected($warehouse->user_id == $user->id)>
                                {{ $user->name }} {{$user->last_name}}
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

                @if($warehouse->creator)
                    <p class="text-muted mt-2 mb-0">
                        <small>
                            <i class="fas fa-info-circle mr-1"></i>
                            Creado por {{ $warehouse->creator->name }} {{ $warehouse->creator->last_name }}
                            el {{ $warehouse->created_at->format('d/m/Y') }}.
                        </small>
                    </p>
                @endif
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
