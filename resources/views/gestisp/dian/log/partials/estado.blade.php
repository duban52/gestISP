{{--
    La etiqueta de estado de un documento electrónico.

    Los colores no son decoración: rojo es una factura SIN VALOR FISCAL
    que hay que corregir y volver a emitir, y amarillo es algo que se
    quedó esperando y nadie va a avisar.
--}}
@php
    $color = match ($documento->status) {
        \App\Models\ElectronicDocument::ACEPTADO => 'success',
        \App\Models\ElectronicDocument::RECHAZADO => 'danger',
        \App\Models\ElectronicDocument::FIRMADO, \App\Models\ElectronicDocument::ENVIADO => 'warning',
        default => 'secondary',
    };
@endphp

<span class="badge badge-{{ $color }}">{{ $estados[$documento->status] ?? $documento->status }}</span>
