@extends('adminlte::page')

@section('title', 'Nueva caja NAP')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-box mr-2"></i>Nueva caja NAP / CTO</h1>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    @if($redes->isEmpty())
        <div class="callout callout-warning">
            <h5>Primero hay que crear una red</h5>
            <p class="mb-2">
                Una caja cuelga de un puerto PON, y los puertos pertenecen a una red.
            </p>
            <a href="{{ route('networks.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Crear una red
            </a>
        </div>
    @else
        <form method="POST" action="{{ route('naps.store') }}">
            @csrf
            @include('gestisp.networks.naps.partials.form')
        </form>
    @endif
@endsection
