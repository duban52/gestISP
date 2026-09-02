@extends('adminlte::page')
@section('title', 'Editar grupo de afinidad')

@section('content_header')
    <h1>
        <i class="fas fa-layer-group mr-2"></i>{{ $grupo->name }}
        <small class="text-muted">{{ $grupo->code }}</small>
    </h1>
@endsection

@section('content')
    @if($grupo->contracts()->count() > 0)
        {{-- Cambiar como factura un grupo con contratos dentro afecta a
             todos ellos a la vez. Decirlo antes evita el descubrimiento
             el dia de emitir. --}}
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            Este grupo tiene <strong>{{ $grupo->contracts()->count() }} contrato(s)</strong>.
            Cambiar aqui la modalidad de facturacion afecta a todos ellos.
        </div>
    @endif

    <form method="POST" action="{{ route('affinity_groups.update', $grupo) }}">
        @csrf
        @method('PUT')

        @include('gestisp.affinity_groups._form', ['grupo' => $grupo])

        <div class="acciones-movil mb-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Guardar cambios
            </button>
            <a href="{{ route('affinity_groups.index') }}" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
