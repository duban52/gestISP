@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="text-center mb-4 mt-4">
                <img src="{{ asset('img/Logo-gestisp-full.png') }}" alt="Logo Gestisp" width="250px">
            </div>
            <div class="col-md-6 mt-4">
                <div class="card">
                    <div class="card-header">{{ __('Iniciar Sesión') }}</div>

                    <div class="card-body">
                        <form method="POST" action="{{ route('login') }}">
                            @csrf

                            <div class="row mb-3">
                                <label for="email" class="col-md-4 col-form-label text-md-end">{{ __('Correo Electrónico') }}</label>

                                <div class="col-md-6">
                                    <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>

                                    @error('email')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                    @enderror
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="password" class="col-md-4 col-form-label text-md-end">{{ __('Contraseña') }}</label>

                                <div class="col-md-6">
                                    <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">

                                    @error('password')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                    @enderror
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label class="col-md-4 col-form-label text-md-end" for="branch">Sucursal</label>

                                <div class="col-md-6">

                                    <select name="branch_id" id="branch" required class="form-control">
                                        <option value="" selected disabled>Seleccione una sucursal</option>
                                        <!-- Opciones dinámicas se llenan con AJAX -->
                                    </select>
                                </div>
                            </div>
                            <script>

                                document.getElementById('email').addEventListener('blur', function() {
                                    const email = this.value;
                                    const branchSelect = document.getElementById('branch');

                                    if (email) {
                                        fetch(`/user/branches?email=${encodeURIComponent(email)}`)
                                    .then(response => {
                                            if (!response.ok) {
                                                throw new Error('Error en la respuesta del servidor');
                                            }
                                            return response.json();
                                        })
                                            .then(data => {
                                                branchSelect.innerHTML = '<option value="" selected disabled>Seleccione una sucursal</option>';

                                                if (data.branches && Object.keys(data.branches).length > 0) {
                                                    Object.entries(data.branches).forEach(([id, name]) => {
                                                        const option = document.createElement('option');
                                                        option.value = id;
                                                        option.textContent = name;
                                                        branchSelect.appendChild(option);
                                                    });
                                                } else {
                                                    branchSelect.innerHTML = '<option value="" selected disabled>No hay sucursales disponibles</option>';
                                                }
                                            })
                                            .catch(error => {
                                                console.error('Error al cargar las sucursales:', error);
                                                branchSelect.innerHTML = '<option value="" selected disabled>Error al cargar sucursales</option>';
                                            });
                                    }
                                });

                            </script>


                            <div class="row mb-3">
                                <div class="col-md-6 offset-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>

                                        <label class="form-check-label" for="remember">
                                            {{ __('Recuérdame') }}
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-0">
                                <div class="col-md-8 offset-md-4">
                                    <button type="submit" class="btn btn-primary">
                                        {{ __('Iniciar sesión') }}
                                    </button>

                                    @if (Route::has('password.request'))
                                        <a class="btn btn-link" href="{{ route('password.request') }}">
                                            {{ __('¿Olvidaste tu contraseña?') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')

@endsection

