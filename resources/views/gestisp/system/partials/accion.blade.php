{{-- Qué le hace el detalle a un equipo al cerrar la orden. --}}
@switch($accion)
    @case('deshabilitar')
        <span class="badge badge-danger">Deshabilitar</span>
        @break
    @case('habilitar')
        <span class="badge badge-success">Habilitar</span>
        @break
    @default
        <span class="text-muted">—</span>
@endswitch
