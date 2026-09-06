@extends('adminlte::page')

@section('title', 'Editar plan')

@section('content_header')
    <div class="card p-3">
        <h1>EDITAR PLAN</h1>
    </div>

@endsection

@section('content')
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('plans.update', $plan->id) }}">
                @csrf
                @method('PUT')
                <x-ambito-catalogo que="plan" :actual="$plan" />

                <div class="form-group">
                    <label>Nombre</label>
                    <input type="text" class="form-control" id="name" name='name'
                           placeholder="Nombre del plan" value="{{ $plan->name  }}">

                    @error('name')
                    <span class="alert-red">
                    <span>*{{ $message }}</span>
                </span>
                    @enderror
                </div>

                {{-- Se ofrece o está retirado. Retirarlo no toca los contratos
                     que ya lo tienen: siguen facturándose igual. Lo único que
                     desaparece es la opción de elegirlo al dar de alta. --}}
                <div class="form-group">
                    <label>
                        <input type="hidden" name="active" value="0">
                        <input type="checkbox" name="active" value="1"
                               {{ old('active', $plan->active) ? 'checked' : '' }}>
                        Se ofrece en contratos nuevos
                    </label>
                    @if($plan->contracts()->exists())
                        <small class="d-block text-muted">
                            Este plan tiene contratos: no se puede eliminar, solo retirar.
                        </small>
                    @endif

                </div>
                <h3>Lista de servicios</h3>
                @foreach($services as $service)
                    <div>
                        <label>
                            <input type="checkbox" name="services[]" id="" value="{{ $service->id }}"
                                   {{ $plan->services->contains($service->id) ? 'checked' : '' }}
                                   class="mr-1"> {{ $service->name}}

                        </label>
                    </div>
                @endforeach

                <div class="col-12 text-center">
                    <input type="submit" value="Modificar plan" class="btn btn-primary col-md-3">
                </div>



            </form>

            <form action="{{ route('plans.destroy', $plan) }}" method="POST">
                @csrf
                @method('DELETE')
                <div class="col-12 text-center">
                    <input type="submit" value="Eliminar" class="btn btn-danger col-md-3 mt-2" onclick="return confirmDelete();">
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
