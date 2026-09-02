@extends('adminlte::page')
@section('title', 'Editar empresa')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-building mr-2"></i>{{ $empresa->nombreVisible() }}</h1>
@endsection

@section('content')
    <div class="card">
        <form method="POST" enctype="multipart/form-data" action="{{ route('companies.update', $empresa) }}">
            @csrf @method('PUT')
            <div class="card-body toque">
                @include('gestisp.companies._form')
            </div>
            <div class="card-footer acciones-movil">
                <button class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                <a href="{{ route('companies.show', $empresa) }}" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
