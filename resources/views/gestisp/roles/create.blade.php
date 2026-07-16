@extends('adminlte::page')

@section('title', 'Roles')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR ROLES</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Formulario de creación de rol

         El nombre identifica al rol y la lista de permisos (parcial
         compartido) define qué puede hacer. Si la validación falla,
         old() restaura el nombre y los permisos marcados.
         ============================================================ --}}

    {{-- Errores de validación --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('roles.store') }}">
                @csrf

                <div class="form-group">
                    <label for="name">Nombre</label>
                    <input type="text" class="form-control" id="name" name="name"
                           placeholder="Nombre del rol" value="{{ old('name') }}" required>
                </div>

                {{-- Permisos agrupados por módulo; old() conserva la
                     selección si la validación falla --}}
                @include('gestisp.roles.partials.permissions_checklist', [
                    'permissionGroups' => $permissionGroups,
                    'checkedPermissions' => old('permissions', []),
                ])

                <button type="submit" class="btn btn-success mt-2">
                    <i class="fas fa-save"></i> Crear rol
                </button>
            </form>
        </div>
    </div>
@endsection
