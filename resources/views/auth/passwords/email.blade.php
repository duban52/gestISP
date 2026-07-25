@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="text-center mb-4 mt-4">
            <img src="{{ asset('img/Logo-gestisp-full.png') }}" alt="Logo Gestisp" width="250px">
        </div>
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">{{ __('Recuperar contraseña') }}</div>

                <div class="card-body">
                    @include('auth.partials.alerts')

                    <p class="text-muted">
                        Escriba el correo con el que ingresa a GestISP y le enviaremos
                        un enlace para crear una contraseña nueva.
                    </p>

                    <form method="POST" action="{{ route('password.email') }}">
                        @csrf

                        <div class="row mb-3">
                            <label for="email" class="col-md-4 col-form-label text-md-end">{{ __('Correo Electrónico') }}</label>

                            <div class="col-md-6">
                                <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
                            </div>
                        </div>

                        <div class="row mb-0">
                            <div class="col-md-6 offset-md-4">
                                <button type="submit" class="btn btn-primary">
                                    {{ __('Enviar enlace de recuperación') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
