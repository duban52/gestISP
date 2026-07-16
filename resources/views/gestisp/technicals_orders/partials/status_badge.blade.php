{{-- Badge de color según el estado del flujo de la orden.
     Recibe: $status --}}
@switch($status)
    @case('Pendiente')
        <span class="badge badge-warning">Pendiente</span>
        @break
    @case('Asignada')
        <span class="badge badge-info">Asignada</span>
        @break
    @case('Prefinalizada')
        <span class="badge badge-primary">Prefinalizada</span>
        @break
    @case('Cerrada')
        <span class="badge badge-success">Cerrada</span>
        @break
    @case('Rechazada')
        <span class="badge badge-danger">Rechazada</span>
        @break
    @default
        <span class="badge badge-secondary">{{ $status }}</span>
@endswitch
