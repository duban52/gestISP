@extends('adminlte::page')

@section('title', 'Cable ' . $cable->code)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-grip-lines mr-2"></i>Cable {{ $cable->code }}</h1>
        <a href="{{ route('cables.show', $cable) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a la ficha
        </a>
    </div>
@endsection

@section('content')
    @include('gestisp.networks.partials.alertas')

    <form method="POST" action="{{ route('cables.update', $cable) }}">
        @method('PUT')
        <div class="card shadow-sm">
            @include('gestisp.networks.cables.partials.form')
        </div>
    </form>
@endsection
