{{-- ============================================================
     Elección del contexto de trabajo

     Solo aparece cuando de verdad hay algo que elegir: varias
     empresas, o varias sucursales dentro de una. Con una sola opción
     se entra directo, porque una pantalla con una única alternativa
     es un clic de más en cada acceso.

     Va DESPUÉS de autenticar, nunca antes: preguntar por la sucursal
     en el formulario de acceso obligaba a consultarla sin saber quién
     preguntaba.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Elegir contexto')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-building mr-2"></i>¿Dónde va a trabajar?</h1>
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <p class="text-muted">
        Tiene acceso a más de un contexto. Elija con cuál entrar; puede cambiarlo
        después sin cerrar la sesión.
    </p>

    @foreach($disponibles as $contexto)
        @php
            $empresa = $contexto['empresa'];
            $esActual = (int) ($actual['company_id'] ?? 0) === $empresa->id;
        @endphp

        <div class="card shadow-sm @if($esActual) border-primary @endif">
            <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
                <h3 class="card-title mb-0">
                    <i class="fas fa-building mr-1"></i>
                    {{ $empresa->nombreVisible() }}
                    <small class="text-muted ml-2">NIT {{ $empresa->identificacion() }}</small>
                </h3>
                @if($contexto['consolidada'])
                    <span class="badge badge-info">Panel consolidado</span>
                @endif
            </div>

            <div class="card-body">
                {{-- En modo consolidado la empresa se trabaja entera desde
                     un solo panel: la opción principal es esa, y las
                     sucursales quedan como alternativa para quien
                     prefiera centrarse en una. --}}
                @if($contexto['consolidada'])
                    <form method="POST" action="{{ route('context.store') }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $empresa->id }}">
                        <button class="btn btn-primary btn-block">
                            <i class="fas fa-layer-group mr-1"></i>
                            Entrar a todas las sucursales
                            <small class="d-block">
                                {{ $contexto['sucursales']->count() }} sede(s) en un solo panel
                            </small>
                        </button>
                    </form>
                    <p class="text-muted small mb-2">O entrar a una sola sucursal:</p>
                @endif

                <div class="row">
                    @foreach($contexto['sucursales'] as $sucursal)
                        <div class="col-12 col-md-6 col-lg-4 mb-2">
                            <form method="POST" action="{{ route('context.store') }}">
                                @csrf
                                <input type="hidden" name="company_id" value="{{ $empresa->id }}">
                                <input type="hidden" name="branch_id" value="{{ $sucursal->id }}">
                                <button class="btn btn-outline-primary btn-block text-left">
                                    <i class="fas fa-store mr-1"></i>
                                    {{ $sucursal->name }}
                                    @if((string) ($actual['branch_id'] ?? '') === (string) $sucursal->id)
                                        <span class="badge badge-primary float-right">Actual</span>
                                    @endif
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
