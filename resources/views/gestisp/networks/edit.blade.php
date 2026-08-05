@extends('adminlte::page')

@section('title', 'Editar red')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-sitemap mr-2"></i>{{ $network->name }}</h1>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <form method="POST" action="{{ route('networks.update', $network) }}">
                @csrf
                @method('PUT')
                @include('gestisp.networks.partials.form')
            </form>

            @can('networks.destroy')
                <div class="card card-outline card-danger shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title">Eliminar la red</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-2">
                            Solo es posible si no tiene cajas NAP registradas. Sus OLTs quedarán
                            sin red asignada, pero no se eliminan.
                        </p>
                        <form method="POST" action="{{ route('networks.destroy', $network) }}"
                              onsubmit="return confirm('¿Eliminar la red {{ $network->name }}?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-trash"></i> Eliminar red
                            </button>
                        </form>
                    </div>
                </div>
            @endcan
        </div>
    </div>
@endsection
