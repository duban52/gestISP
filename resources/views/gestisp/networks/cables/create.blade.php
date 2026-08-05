@extends('adminlte::page')

@section('title', 'Nuevo cable')

@section('content_header')
    <h1><i class="fas fa-grip-lines mr-2"></i>Nuevo cable de fibra</h1>
@endsection

@section('content')
    @include('gestisp.networks.partials.alertas')

    <form method="POST" action="{{ route('cables.store') }}">
        <div class="card shadow-sm">
            @include('gestisp.networks.cables.partials.form')
        </div>
    </form>
@endsection
