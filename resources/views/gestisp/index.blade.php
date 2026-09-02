@php use Illuminate\Support\Facades\Storage; @endphp
@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content')

    <div class="card mt-3">
        <div class="card-head p-3 text-center">
            <p>Hola, <strong>{{ Auth::user()->name }} {{ Auth::user()->last_name }}</strong> Bienvenido a
                <img src="{{ asset('img/Logo-gestisp-solo-texto.png') }}" alt="GestISP" width="80px">. 
                @if($empresa)
                    Estás en <strong>{{ $empresa->nombreVisible() }}</strong>@if($alcance), {{ $alcance }}@endif.
                @elseif($branch)
                    Estás en la sucursal <strong>{{ $branch->name }}</strong>.
                @endif
                @if($rol) Tu rol es <strong>{{ $rol->name }}</strong>.@endif
            </p>
        </div>
        <div class="card-body">
            <div class="text-center p-4">
                {{-- El logo es de la EMPRESA: en panel consolidado no hay una
                     sucursal activa de la que sacarlo. --}}
                @if($empresa?->logo)
                    <img src="{{ Storage::url($empresa->logo) }}" width="200"
                         alt="Logo de {{ $empresa->nombreVisible() }}">
                @endif
            </div>
        </div>
    </div>

@stop

@section('css')
    {{-- Add here extra stylesheets --}}
    {{-- <link rel="stylesheet" href="/css/admin_custom.css"> --}}
@stop

@section('js')
    <script> console.log("Bienvenido a gestISP, una nueva revolución en gestión integral del ISP!"); </script>
@stop
