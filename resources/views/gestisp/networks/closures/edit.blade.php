@extends('adminlte::page')

@section('title', 'Mufla ' . $mufla->code)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-box-open mr-2"></i>Mufla {{ $mufla->code }}</h1>
        <a href="{{ route('closures.show', $mufla) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a la ficha
        </a>
    </div>
@endsection

@section('content')
    @include('gestisp.networks.partials.alertas')

    <form method="POST" action="{{ route('closures.update', $mufla) }}">
        @method('PUT')
        <div class="card shadow-sm">
            @include('gestisp.networks.closures.partials.form')
        </div>
    </form>
@endsection
