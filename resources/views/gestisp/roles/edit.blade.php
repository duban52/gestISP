@extends('adminlte::page')

@section('title', 'Roles')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR ROLES</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Formulario de edición de rol

         Muestra los permisos agrupados por módulo (parcial
         compartido) con los del rol ya marcados. La eliminación
         del rol se hace desde el índice, con su modal de
         confirmación.
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
            <form method="POST" action="{{ route('roles.update', $role) }}">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="name">Nombre</label>
                    <input type="text" class="form-control" id="name" name="name"
                           placeholder="Nombre del rol" value="{{ old('name', $role->name) }}" required>
                </div>

                {{-- Permisos agrupados por módulo; old() conserva la
                     selección si la validación falla, si no se marcan
                     los permisos actuales del rol --}}
                @include('gestisp.roles.partials.permissions_checklist', [
                    'permissionGroups' => $permissionGroups,
                    'checkedPermissions' => old('permissions', $rolePermissionIds),
                ])

                <button type="submit" class="btn btn-success mt-2">
                    <i class="fas fa-save"></i> Guardar cambios
                </button>
            </form>
        </div>
    </div>
@endsection
