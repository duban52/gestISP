@extends('adminlte::page')
@section('title', 'Nuevo grupo de afinidad')

@section('content_header')
    <h1><i class="fas fa-layer-group mr-2"></i>Nuevo grupo de afinidad</h1>
@endsection

@section('content')
    <form method="POST" action="{{ route('affinity_groups.store') }}">
        @csrf

        {{-- El grupo es de la EMPRESA, no de la sucursal: no se
             pregunta sucursal ni siquiera en panel consolidado. --}}
        @include('gestisp.affinity_groups._form', ['grupo' => null])

        <div class="acciones-movil mb-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Crear grupo
            </button>
            <a href="{{ route('affinity_groups.index') }}" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
