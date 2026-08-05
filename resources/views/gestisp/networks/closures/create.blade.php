@extends('adminlte::page')

@section('title', 'Nueva mufla')

@section('content_header')
    <h1><i class="fas fa-box-open mr-2"></i>Nueva mufla</h1>
@endsection

@section('content')
    @include('gestisp.networks.partials.alertas')

    <form method="POST" action="{{ route('closures.store') }}">
        <div class="card shadow-sm">
            @include('gestisp.networks.closures.partials.form')
        </div>
    </form>
@endsection
