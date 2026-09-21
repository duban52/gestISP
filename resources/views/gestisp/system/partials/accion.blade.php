{{-- Qué le hace el detalle a un equipo al cerrar la orden. --}}
@switch($accion)
    @case('deshabilitar')
        <span class="badge badge-danger">Deshabilitar</span>
        @break
    @case('habilitar')
        <span class="badge badge-success">Habilitar</span>
        @break
    @default
        <span class="text-muted" title="Habilita si el estado tiene servicio, deshabilita si no; sin cambio de estado, no toca nada">
            según el estado
        </span>
@endswitch
