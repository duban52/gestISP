@extends('adminlte::page')
@section('title', 'Trazabilidad de usuario')

@section('content_header')
    <div class="card p-3 mb-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h2 class="mb-0">
                    {{ $user->name }} {{ $user->last_name }}
                    @if($user->is_active)
                        <span class="badge badge-success align-middle">Activo</span>
                    @else
                        <span class="badge badge-danger align-middle">Inhabilitado</span>
                    @endif
                </h2>
                <span class="text-muted small">
                    <i class="fas fa-envelope mr-1"></i>{{ $user->email }}
                    · <i class="fas fa-id-card mr-1"></i>{{ $user->identity_number }}
                    @foreach($user->getRoleNames() as $rol)
                        <span class="badge badge-secondary ml-1">{{ $rol }}</span>
                    @endforeach
                </span>
            </div>

            <div class="d-flex align-items-center" style="gap: .4rem;">
                {{-- Cerrar todas las sesiones activas del usuario --}}
                @can('users.sessions.close')
                    @if($activas->isNotEmpty())
                        <form method="POST" action="{{ route('users.sessions.close-all', $user) }}"
                              onsubmit="return confirm('¿Cerrar todas las sesiones activas de {{ $user->name }}? Se le expulsará en su siguiente movimiento.{{ $user->id === auth()->id() ? ' (Su sesión actual se conservará.)' : '' }}');">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-power-off mr-1"></i> Cerrar sesiones
                            </button>
                        </form>
                    @endif
                @endcan

                {{-- Habilitar / inhabilitar el acceso --}}
                @can('users.disable')
                    @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('users.toggle-active', $user) }}"
                              onsubmit="return confirm('{{ $user->is_active
                                    ? '¿Inhabilitar a ' . $user->name . '? No podrá iniciar sesión y se cerrarán sus sesiones activas.'
                                    : '¿Habilitar a ' . $user->name . '? Podrá volver a iniciar sesión.' }}');">
                            @csrf
                            <button type="submit" class="btn {{ $user->is_active ? 'btn-danger' : 'btn-success' }}">
                                <i class="fas {{ $user->is_active ? 'fa-user-slash' : 'fa-user-check' }} mr-1"></i>
                                {{ $user->is_active ? 'Inhabilitar' : 'Habilitar' }}
                            </button>
                        </form>
                    @endif
                @endcan

                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Volver
                </a>
            </div>
        </div>

        @unless($user->is_active)
            <div class="alert alert-danger mt-3 mb-0">
                <i class="fas fa-ban mr-1"></i>
                Este usuario está <strong>inhabilitado</strong>: no puede iniciar sesión.
                Su información y su historial se conservan.
            </div>
        @endunless
    </div>
@endsection

@section('content')

    @if(session('success-update'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success-update') }}
        </div>
    @elseif(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('error') }}
        </div>
    @endif

    {{-- ================= ESTADÍSTICAS ================= --}}
    <div class="row">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ number_format($estadisticas['activas']) }}</h3>
                    <p class="mb-0">Sesiones activas</p>
                </div>
                <div class="icon"><i class="fas fa-circle"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ number_format($estadisticas['total_sesiones']) }}</h3>
                    <p class="mb-0">Sesiones totales</p>
                </div>
                <div class="icon"><i class="fas fa-history"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ number_format($estadisticas['ips_distintas']) }}</h3>
                    <p class="mb-0">IPs distintas</p>
                </div>
                <div class="icon"><i class="fas fa-network-wired"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="small-box bg-secondary">
                <div class="inner">
                    <h3 style="font-size: 1.4rem;">{{ $estadisticas['dispositivo_frecuente'] ?? '—' }}</h3>
                    <p class="mb-0">Equipo habitual</p>
                </div>
                <div class="icon"><i class="fas fa-desktop"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="small-box bg-light">
                <div class="inner">
                    <h3 style="font-size: 1rem;">
                        {{ $estadisticas['ultimo_acceso'] ? \Carbon\Carbon::parse($estadisticas['ultimo_acceso'])->format('d/m/Y H:i') : '—' }}
                    </h3>
                    <p class="mb-0">Último acceso</p>
                </div>
                <div class="icon"><i class="fas fa-sign-in-alt"></i></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="small-box {{ $fallidosTotal > 0 ? 'bg-warning' : 'bg-light' }}">
                <div class="inner">
                    <h3>{{ number_format($fallidosTotal) }}</h3>
                    <p class="mb-0">Intentos fallidos</p>
                </div>
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>

    {{-- ================= SESIONES ACTIVAS ================= --}}
    <div class="card card-outline card-success">
        <div class="card-header py-2">
            <h3 class="card-title">
                <i class="fas fa-broadcast-tower mr-1"></i> Sesiones activas
                <span class="badge badge-success ml-1">{{ $activas->count() }}</span>
            </h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>Inicio</th>
                        <th>Última actividad</th>
                        <th>Sucursal</th>
                        <th>IP</th>
                        <th>Equipo</th>
                        <th>Duración</th>
                        <th class="text-right">Acción</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($activas as $s)
                        @php $esActual = $sesionActualId && $s->session_id === $sesionActualId; @endphp
                        <tr class="{{ $esActual ? 'table-info' : '' }}">
                            <td class="text-nowrap">{{ $s->login_at?->format('d/m/Y H:i') }}</td>
                            <td class="text-nowrap">
                                {{ $s->last_activity_at?->diffForHumans() }}
                            </td>
                            <td>{{ $s->branch->name ?? '—' }}</td>
                            <td><code>{{ $s->ip_address ?? '—' }}</code></td>
                            <td>
                                <i class="fas fa-{{ $s->device_type === 'Móvil' ? 'mobile-alt' : ($s->device_type === 'Tablet' ? 'tablet-alt' : 'desktop') }} text-muted mr-1"></i>
                                {{ $s->browser }} · {{ $s->platform }}
                            </td>
                            <td>{{ $s->duracionMinutos() !== null ? $s->duracionMinutos() . ' min' : '—' }}</td>
                            <td class="text-right">
                                @if($esActual)
                                    <span class="badge badge-info">Esta sesión</span>
                                @else
                                    @can('users.sessions.close')
                                        <form method="POST" action="{{ route('users.sessions.close', [$user, $s]) }}"
                                              onsubmit="return confirm('¿Cerrar esta sesión de forma remota? El usuario será expulsado en su siguiente acción.');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Cerrar sesión remota">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-moon mr-1"></i> El usuario no tiene sesiones activas en este momento.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($activas->isNotEmpty())
            <div class="card-footer py-2 small text-muted">
                <i class="fas fa-info-circle mr-1"></i>
                Cerrar una sesión de forma remota no la elimina del equipo del usuario: lo desconecta
                en su siguiente movimiento. Una sesión sin actividad durante
                {{ config('session.lifetime') }} minutos deja de considerarse activa.
            </div>
        @endif
    </div>

    <div class="row">
        {{-- ================= HISTORIAL ================= --}}
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title"><i class="fas fa-history mr-1"></i> Historial de sesiones</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Inicio</th>
                                <th>Fin</th>
                                <th>Sucursal</th>
                                <th>IP</th>
                                <th>Equipo</th>
                                <th>Duración</th>
                                <th>Cómo terminó</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($historial as $s)
                                <tr>
                                    <td class="text-nowrap">{{ $s->login_at?->format('d/m/Y H:i') }}</td>
                                    <td class="text-nowrap">
                                        {{ ($s->logout_at ?? $s->last_activity_at)?->format('d/m/Y H:i') }}
                                    </td>
                                    <td>{{ $s->branch->name ?? '—' }}</td>
                                    <td><code>{{ $s->ip_address ?? '—' }}</code></td>
                                    <td class="small">{{ $s->browser }} · {{ $s->platform }}</td>
                                    <td>{{ $s->duracionMinutos() !== null ? $s->duracionMinutos() . ' min' : '—' }}</td>
                                    <td>
                                        @php $estado = $s->estadoLegible(); @endphp
                                        <span class="badge badge-{{ $estado === 'Cerrada por un administrador' ? 'danger' : ($estado === 'Cerró sesión' ? 'secondary' : 'warning') }}">
                                            {{ $estado }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        Todavía no hay sesiones anteriores registradas.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($historial->hasPages())
                    <div class="card-footer py-2">
                        {{ $historial->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- ================= INTENTOS FALLIDOS ================= --}}
        <div class="col-12 col-lg-4">
            <div class="card card-outline {{ $fallidosTotal > 0 ? 'card-warning' : '' }}">
                <div class="card-header py-2">
                    <h3 class="card-title">
                        <i class="fas fa-user-lock mr-1"></i> Intentos fallidos
                        @if($fallidosTotal > 0)
                            <span class="badge badge-warning ml-1">{{ $fallidosTotal }}</span>
                        @endif
                    </h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>Fecha</th>
                            <th>IP</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($fallidos as $f)
                            <tr>
                                <td class="text-nowrap small">{{ $f->attempted_at?->format('d/m/Y H:i') }}</td>
                                <td><code>{{ $f->ip_address ?? '—' }}</code></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="text-center text-muted py-4">
                                    <i class="fas fa-shield-alt mr-1"></i> Sin intentos fallidos.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($fallidosTotal > $fallidos->count())
                    <div class="card-footer py-2 small text-muted">
                        Se muestran los {{ $fallidos->count() }} más recientes de {{ $fallidosTotal }}.
                    </div>
                @endif
            </div>
        </div>
    </div>
@stop
