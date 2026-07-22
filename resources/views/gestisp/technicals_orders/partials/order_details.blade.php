{{-- ============================================================
     Parcial: cuerpo del detalle de una orden técnica
     Usado por el modal de detalles del index y el de verificación.
     Recibe: $technical_order
     ============================================================ --}}
<div>
    <p class="mt-2 mb-1"><strong>Datos del cliente</strong></p>
    <div>Cliente: {{ $technical_order->contract->client->name }} {{ $technical_order->contract->client->last_name }}</div>
    <div>Barrio y dirección: {{ $technical_order->contract->neighborhood }} {{ $technical_order->contract->address }}</div>
    <div>Plan: {{ $technical_order->contract->plan->name ?? '—' }}</div>

    <p class="mt-3 mb-1"><strong>Datos de orden</strong></p>
    <div>Tipo de orden: {{ $technical_order->type }}</div>
    <div>Detalle: {{ $technical_order->detail }}</div>
    <div>Comentario inicial: {{ $technical_order->initial_comment ?? '—' }}</div>

    <p class="mt-3 mb-1"><strong>Datos de solución</strong></p>
    <div>Observaciones técnicas: {{ $technical_order->observations_technical ?? '—' }}</div>
    <div>Observaciones del cliente: {{ $technical_order->client_observation ?? '—' }}</div>
    <div>Solución: {{ $technical_order->solution ?? '—' }}</div>
    <div>Motivo de rechazo por el técnico: {{ $technical_order->rejection_reason ?? '—' }}</div>
    <div>Fecha de creación: {{ $technical_order->created_at->format('Y-m-d H:i') }}</div>
    <div>Última acción: {{ $technical_order->updated_at->format('Y-m-d H:i') }}</div>

    {{-- Evidencia fotográfica del trabajo --}}
    @php
        $images = is_string($technical_order->images) ? json_decode($technical_order->images) : [];
    @endphp

    @if(!empty($images))
        <p class="mt-3 mb-1"><strong>Fotos</strong></p>
        <div class="card">
            {{-- El id del carousel incluye el id de la orden: con un id
                 fijo repetido, los controles de cualquier modal movían
                 el primer carousel de la página (bug de la versión anterior) --}}
            <div id="carouselOrder{{ $technical_order->id }}" class="carousel slide">
                <div class="carousel-inner">
                    @foreach($images as $index => $image)
                        <div class="carousel-item {{ $index == 0 ? 'active' : '' }}">
                            <img src="{{ asset($image) }}" class="d-block w-100"
                                 alt="Imagen técnica {{ $index + 1 }}">
                        </div>
                    @endforeach
                </div>
                {{-- Controles con sintaxis Bootstrap 4 (AdminLTE 3):
                     href + data-slide, no data-bs-target/data-bs-slide --}}
                <a class="carousel-control-prev" href="#carouselOrder{{ $technical_order->id }}"
                   role="button" data-slide="prev">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    <span class="sr-only">Anterior</span>
                </a>
                <a class="carousel-control-next" href="#carouselOrder{{ $technical_order->id }}"
                   role="button" data-slide="next">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    <span class="sr-only">Siguiente</span>
                </a>
            </div>
        </div>
    @endif

    {{-- Firma del cliente al cierre --}}
    @if($technical_order->client_signature)
        <p class="mt-3 mb-1"><strong>Firma del cliente</strong></p>
        <img src="{{ asset($technical_order->client_signature) }}"
             alt="Firma del cliente"
             style="max-width: 320px; width: 100%; border: 1px solid #dee2e6; border-radius: 6px; background:#fff;">
    @endif

    {{-- Materiales usados en la ejecución --}}
    @if($technical_order->materials->isNotEmpty())
        <p class="mt-3 mb-1"><strong>Materiales usados</strong></p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead>
                <tr>
                    <th>Material</th>
                    <th>Cantidad</th>
                    <th>SN</th>
                </tr>
                </thead>
                <tbody>
                @foreach($technical_order->materials as $material_to_order)
                    <tr>
                        <td>{{ $material_to_order->material->name }}</td>
                        <td>{{ $material_to_order->quantity }}</td>
                        <td>{{ $material_to_order->serial_number ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
