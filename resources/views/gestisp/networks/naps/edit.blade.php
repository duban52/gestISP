@extends('adminlte::page')

@section('title', 'Editar ' . $nap->etiqueta)

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-box mr-2"></i>{{ $nap->etiqueta }}</h1>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    <form method="POST" action="{{ route('naps.update', $nap) }}">
        @csrf
        @method('PUT')
        @include('gestisp.networks.naps.partials.form')
    </form>

    @can('naps.destroy')
        <div class="card card-outline card-danger shadow-sm">
            <div class="card-header"><h3 class="card-title">Eliminar la caja</h3></div>
            <div class="card-body">
                <p class="text-muted mb-2">
                    Solo es posible si no tiene clientes conectados.
                </p>
                <form method="POST" action="{{ route('naps.destroy', $nap) }}"
                      onsubmit="return confirm('¿Eliminar la caja {{ $nap->etiqueta }}?');">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">
                        <i class="fas fa-trash"></i> Eliminar caja
                    </button>
                </form>
            </div>
        </div>
    @endcan
@endsection
