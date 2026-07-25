@extends('adminlte::page')

@section('title', 'Mi perfil')

@section('content_header')
    <h1 class="mb-0">Mi perfil</h1>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <strong><i class="fas fa-exclamation-triangle mr-1"></i> Revise lo siguiente:</strong>
            <ul class="mb-0 mt-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        {{-- ============================================================
             Columna izquierda: identidad y foto
             ============================================================ --}}
        <div class="col-lg-4">
            <div class="card card-primary card-outline shadow-sm">
                <div class="card-body box-profile text-center">
                    <img class="profile-user-img img-fluid img-circle elevation-2"
                         src="{{ $user->adminlte_image() }}"
                         alt="Foto de {{ $user->full_name }}"
                         style="width: 118px; height: 118px; object-fit: cover;">

                    <h3 class="profile-username mt-2 mb-1">{{ $user->full_name }}</h3>
                    <p class="text-muted mb-2">{{ $user->email }}</p>

                    <p class="mb-3">
                        <span class="badge badge-{{ $user->is_active ? 'success' : 'danger' }}">
                            <i class="fas fa-{{ $user->is_active ? 'check' : 'ban' }} mr-1"></i>
                            {{ $user->is_active ? 'Cuenta activa' : 'Cuenta inhabilitada' }}
                        </span>
                    </p>

                    {{-- Subir / quitar foto --}}
                    <form action="{{ route('profile.photo') }}" method="POST" enctype="multipart/form-data"
                          id="form-foto">
                        @csrf
                        <div class="custom-file text-left mb-2">
                            <input type="file" name="avatar" id="avatar"
                                   class="custom-file-input @error('avatar') is-invalid @enderror"
                                   accept="image/jpeg,image/png,image/webp">
                            <label class="custom-file-label" for="avatar" id="avatar-label">
                                Elegir foto...
                            </label>
                        </div>
                        <small class="text-muted d-block mb-2">JPG, PNG o WEBP · máximo 2 MB</small>
                        <button type="submit" class="btn btn-primary btn-sm btn-block">
                            <i class="fas fa-upload mr-1"></i> Guardar foto
                        </button>
                    </form>

                    @if($user->avatar)
                        <form action="{{ route('profile.photo.destroy') }}" method="POST" class="mt-2"
                              onsubmit="return confirm('¿Quitar su foto de perfil?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm btn-block">
                                <i class="fas fa-trash mr-1"></i> Quitar foto
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Resumen de la cuenta --}}
            <div class="card shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-id-badge mr-1"></i> Mi cuenta</h3>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Rol activo</span>
                        <strong>{{ optional(\Spatie\Permission\Models\Role::find(session('current_role_id')))->name ? ucfirst(\Spatie\Permission\Models\Role::find(session('current_role_id'))->name) : '—' }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Sucursal actual</span>
                        <strong>{{ optional(\App\Models\Branch::find(session('branch_id')))->name ?? '—' }}</strong>
                    </li>
                    <li class="list-group-item">
                        <span class="text-muted d-block mb-1">Sucursales con acceso</span>
                        @forelse($user->branches as $sucursal)
                            <span class="badge badge-light border mr-1">{{ $sucursal->name }}</span>
                        @empty
                            <span class="text-muted">—</span>
                        @endforelse
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted">Miembro desde</span>
                        <strong>{{ $user->created_at?->format('d/m/Y') ?? '—' }}</strong>
                    </li>
                </ul>
            </div>
        </div>

        {{-- ============================================================
             Columna derecha: datos, seguridad y accesos
             ============================================================ --}}
        <div class="col-lg-8">
            <div class="card card-primary card-outline card-outline-tabs shadow-sm">
                <div class="card-header p-0 border-bottom-0">
                    <ul class="nav nav-tabs" id="profile-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="tab-datos" data-toggle="pill" href="#datos" role="tab">
                                <i class="fas fa-user mr-1"></i> Datos personales
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="tab-seguridad" data-toggle="pill" href="#seguridad" role="tab">
                                <i class="fas fa-lock mr-1"></i> Seguridad
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="tab-accesos" data-toggle="pill" href="#accesos" role="tab">
                                <i class="fas fa-history mr-1"></i> Accesos recientes
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="card-body">
                    <div class="tab-content" id="profile-tabs-content">

                        {{-- ---------- Datos personales ---------- --}}
                        <div class="tab-pane fade show active" id="datos" role="tabpanel">
                            <form action="{{ route('profile.update') }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="row">
                                    <div class="form-group col-md-6">
                                        <label for="name">Nombre <span class="text-danger">*</span></label>
                                        <input type="text" name="name" id="name" maxlength="40"
                                               class="form-control @error('name') is-invalid @enderror"
                                               value="{{ old('name', $user->name) }}" required>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="last_name">Apellido <span class="text-danger">*</span></label>
                                        <input type="text" name="last_name" id="last_name" maxlength="40"
                                               class="form-control @error('last_name') is-invalid @enderror"
                                               value="{{ old('last_name', $user->last_name) }}" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="form-group col-md-6">
                                        <label for="identity_number">Identificación <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                            </div>
                                            <input type="text" name="identity_number" id="identity_number" maxlength="20"
                                                   class="form-control @error('identity_number') is-invalid @enderror"
                                                   value="{{ old('identity_number', $user->identity_number) }}" required>
                                        </div>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="number_phone">Celular <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                            </div>
                                            <input type="text" name="number_phone" id="number_phone" maxlength="20"
                                                   class="form-control @error('number_phone') is-invalid @enderror"
                                                   value="{{ old('number_phone', $user->number_phone) }}" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="email">Correo electrónico <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        </div>
                                        <input type="email" name="email" id="email"
                                               class="form-control @error('email') is-invalid @enderror"
                                               value="{{ old('email', $user->email) }}" required>
                                    </div>
                                    <small class="form-text text-muted">
                                        Con este correo inicia sesión: si lo cambia, use el nuevo la próxima vez.
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label for="address">Dirección <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                        </div>
                                        <input type="text" name="address" id="address" maxlength="255"
                                               class="form-control @error('address') is-invalid @enderror"
                                               value="{{ old('address', $user->address) }}" required>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save mr-1"></i> Guardar cambios
                                </button>
                            </form>
                        </div>

                        {{-- ---------- Seguridad ---------- --}}
                        <div class="tab-pane fade" id="seguridad" role="tabpanel">
                            <div class="callout callout-info">
                                <p class="mb-0">
                                    Al cambiar su contraseña se cerrarán las sesiones abiertas en otros
                                    dispositivos. La sesión de este equipo se mantiene.
                                </p>
                            </div>

                            <form action="{{ route('profile.password') }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="form-group">
                                    <label for="current_password">Contraseña actual <span class="text-danger">*</span></label>
                                    <input type="password" name="current_password" id="current_password"
                                           class="form-control @error('current_password') is-invalid @enderror"
                                           autocomplete="current-password" required>
                                </div>

                                <div class="row">
                                    <div class="form-group col-md-6">
                                        <label for="password">Contraseña nueva <span class="text-danger">*</span></label>
                                        <input type="password" name="password" id="password"
                                               class="form-control @error('password') is-invalid @enderror"
                                               autocomplete="new-password" required minlength="8">
                                        <small class="form-text text-muted">Mínimo 8 caracteres.</small>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="password_confirmation">Confirmar contraseña <span class="text-danger">*</span></label>
                                        <input type="password" name="password_confirmation" id="password_confirmation"
                                               class="form-control" autocomplete="new-password" required minlength="8">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-key mr-1"></i> Cambiar contraseña
                                </button>
                            </form>
                        </div>

                        {{-- ---------- Accesos recientes ---------- --}}
                        <div class="tab-pane fade" id="accesos" role="tabpanel">
                            <p class="text-muted">
                                Últimos ingresos registrados en su cuenta. Si no reconoce alguno,
                                cambie su contraseña y avise a un administrador.
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="thead-light">
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Dirección IP</th>
                                        <th>Estado</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($sesiones as $sesion)
                                        <tr>
                                            <td>{{ $sesion->login_at?->format('d/m/Y h:i a') ?? '—' }}</td>
                                            <td>{{ $sesion->ip_address ?? '—' }}</td>
                                            <td>
                                                @if($sesion->logout_at)
                                                    <span class="badge badge-secondary">Cerrada</span>
                                                @else
                                                    <span class="badge badge-success">Activa</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-3">
                                                Aún no hay accesos registrados.
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    <script>
        // Mostrar el nombre del archivo elegido en el selector de foto
        document.getElementById('avatar').addEventListener('change', function () {
            var nombre = this.files.length ? this.files[0].name : 'Elegir foto...';
            document.getElementById('avatar-label').textContent = nombre;
        });

        // Si la validación falló en un formulario concreto, abrir su pestaña
        @if($errors->has('current_password') || $errors->has('password'))
            $(function () { $('#tab-seguridad').tab('show'); });
        @endif
    </script>
@stop
