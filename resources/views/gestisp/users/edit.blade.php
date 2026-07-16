@extends('adminlte::page')

@section('title', 'Usuarios')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR USUARIOS</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Formulario de edición de usuario

         El número de identidad no es editable. La contraseña solo
         se cambia si se escribe un valor nuevo. Las asignaciones
         de sucursal/rol reemplazan por completo a las actuales
         (parcial compartido con la vista de creación).
         ============================================================ --}}

    {{-- Errores de validación y errores inesperados --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <form action="{{ route('users.update', $user) }}" method="POST">
                @csrf
                @method('put')
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="identity_number" class="form-label">Número de Identidad</label>
                            <input type="text" class="form-control" id="identity_number" name="identity_number"
                                   value="{{ $user->identity_number }}" disabled>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nombre</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="{{ old('name', $user->name) }}" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="last_name" class="form-label">Apellido</label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                   value="{{ old('last_name', $user->last_name) }}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="number_phone" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="number_phone" name="number_phone"
                                   value="{{ old('number_phone', $user->number_phone) }}">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="address" class="form-label">Dirección</label>
                            <input type="text" class="form-control" id="address" name="address"
                                   value="{{ old('address', $user->address) }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="email" class="form-label">Correo Electrónico</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="{{ old('email', $user->email) }}" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña</label>
                            <input type="password" class="form-control" id="password" name="password" minlength="6">
                            <small class="form-text text-muted">
                                Déjela vacía para conservar la contraseña actual.
                            </small>
                        </div>
                    </div>
                </div>

                {{-- Asignaciones de sucursal/rol. Si la validación
                     falla se restauran las filas enviadas; si no,
                     se muestran las asignaciones actuales. --}}
                @php
                    $currentPairs = $userBranches
                        ->map(fn($branch) => ['branch_id' => $branch->id, 'role_id' => $branch->pivot->role_id])
                        ->values()
                        ->all();

                    if (empty($currentPairs)) {
                        $currentPairs = [['branch_id' => null, 'role_id' => null]];
                    }
                @endphp
                @include('gestisp.users.partials.branch_role_pairs', [
                    'branchPairs' => old('branches', $currentPairs),
                    'branches' => $branches,
                    'roles' => $roles,
                ])

                <hr>
                <button type="submit" class="btn btn-success mt-2">
                    <i class="fas fa-save"></i> Guardar Usuario
                </button>
            </form>
        </div>
    </div>
@endsection
