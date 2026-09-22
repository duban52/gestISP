@extends('adminlte::page')

@section('title', 'Crear orden')
{{-- Departamento y municipio de la dirección nueva de un traslado. --}}
@section('plugins.Select2', true)

@section('content_header')
    <div class="card p-3">
        <h2>CREAR ORDEN TÉCNICA</h2>
    </div>

@endsection

@section('content')
    <div class="card p-3">
        <div class="row">
            <div class="col-12 d-flex justify-content-center">
                <h3>DATOS DE CONTRATO</h3>
            </div>
            <div class="col-md-6 mt-2" >
                <label for="">Número de identidad</label>
                <input class="form-control" type="text" value="{{ $contract->client?->identity_number ?: '—' }}" disabled>
            </div>
            <div class="col-md-6 mt-2" >
                <label for="">Nombre y apellido</label>
                <input class="form-control" type="text" value="{{ $contract->client?->fullName() ?: '—' }}" disabled>
            </div>
            <div class="col-md-6 mt-2" >
                <label for="">Barrio y dirección</label>
                <input class="form-control" type="text" value="{{ $contract->neighborhood }}, {{ $contract->address }}" disabled>
            </div>
            <div class="col-md-6 mt-2" >
                <label for="">Plan</label>
                <input class="form-control" type="text" value="{{ $contract->plan?->name ?: '—' }}" disabled>
            </div>

            <div class="col-12">
                <hr>
                <h3 class="text-center">DATOS DE ORDEN</h3>
            </div>

            <form action="{{ route('technicals_orders.store') }}" method="post" class="row col-12">
                @csrf
                @if($errors->any())
                    <div class="col-12">
                        <div class="alert alert-danger">
                            <ul class="mb-0 pl-3">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
                <input type="text" value="{{ $contract->id }}" hidden="hidden" name="contract_id">
                {{-- Tipos y detalles salen del catálogo (Gestión del
                     sistema → Estados y órdenes). Lo que se cree allí
                     aparece aquí sin tocar esta plantilla.

                     Las administrativas no se crean desde aquí: cambian
                     el estado sin visita técnica y tienen su propia
                     pantalla en la ficha del contrato. --}}
                <div class="col-md-6">
                    <label for="order_type">Tipo de orden</label>
                    <select class="form-control" name="order_type" id="order_type">
                        <option value="">Seleccione ...</option>
                        @foreach($tipos as $tipo)
                            @continue($tipo->name === \App\Models\TechnicalOrder::ADMINISTRATIVA)
                            <option value="{{ $tipo->name }}" @selected(old('order_type') === $tipo->name)>{{ $tipo->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="order_detail">Detalle orden</label>
                    <select class="form-control" name="order_detail" id="order_detail">
                        <option value="">Seleccione ...</option>
                        @foreach($tipos as $tipo)
                            @continue($tipo->name === \App\Models\TechnicalOrder::ADMINISTRATIVA)
                            @foreach($tipo->details as $detalle)
                                <option value="{{ $detalle->name }}" data-type="{{ $tipo->name }}"
                                    @selected(old('order_detail') === $detalle->name)
                                    @if(\App\Reports\Support\OrderDetailMap::clave($detalle->name) === 'traslado de servicio')
                                        data-traslado="1"
                                    @endif
                                    @if($efecto = $detalle->descripcionDelEfecto())
                                        title="{{ $efecto }}"
                                    @endif>
                                    {{ $detalle->name }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
                {{-- ============================================================
                     Traslado: la dirección nueva

                     El técnico va a donde diga el contrato. Pedir un
                     traslado sin la dirección nueva lo mandaría a la casa
                     de la que el cliente se fue. Al crear la orden, el
                     contrato queda aquí y la dirección anterior queda
                     escrita en el comentario de la orden.

                     Oculto y deshabilitado si no es un traslado: un campo
                     deshabilitado no se envía ni se valida.
                     ============================================================ --}}
                <div class="col-12 collapse mt-3" id="bloqueTraslado">
                    <div class="card card-outline card-warning mb-0">
                        <div class="card-header py-2">
                            <strong><i class="fas fa-truck-moving mr-1"></i> Dirección nueva del servicio</strong>
                            <small class="d-block text-muted">
                                Al crear la orden el contrato queda en esta dirección, que es a donde irá el técnico.
                                La anterior queda anotada en el comentario de la orden.
                            </small>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                @include('gestisp.partials.departamento-municipio', [
                                    'departamento' => $contract->department,
                                    'municipio' => $contract->municipality,
                                ])
                                <div class="form-group col-12">
                                    <label for="neighborhood">Barrio / Vereda <span class="text-danger">*</span></label>
                                    <input type="text" name="neighborhood" id="neighborhood" class="form-control"
                                           maxlength="255" value="{{ old('neighborhood') }}" required>
                                </div>
                                <div class="form-group col-12">
                                    @include('gestisp.partials.direccion', ['requerido' => true, 'mapa' => 'mapaTraslado'])
                                </div>
                            </div>

                            <h6 class="mt-2">
                                <i class="fas fa-map-pin text-danger mr-1"></i> Ubicación nueva
                                <span class="badge badge-light border ml-1">Opcional</span>
                            </h6>
                            @php
                                $parametrosSelector = [
                                    'mapId' => 'mapaTraslado',
                                    'latitude' => old('latitude'),
                                    'longitude' => old('longitude'),
                                    'height' => '320px',
                                    'allowClear' => true,
                                    'help' => 'Si no se marca, el contrato queda sin ubicación hasta que alguien la ponga: la de la casa anterior ya no sirve.',
                                ];
                            @endphp
                            @include('gestisp.partials.location-picker', $parametrosSelector)
                        </div>
                    </div>
                </div>

                <div class="col-12 mt-2">
                    <label for="initial_comment">Comentario</label>
                    <textarea class="form-control" name="initial_comment" id="initial_comment" cols="30" rows="5">{{ old('initial_comment') }}</textarea>
                </div>
                <div class="col-12 text-center mt-3">
                    <input type="submit" value="Crear orden" title="Crear orden técnica" class="btn btn-success col-md-3">
                </div>
            </form>

        </div>
    </div>
@endsection

@section('css')
    @include('gestisp.partials.leaflet-styles')
@endsection

@section('js')
    @include('gestisp.partials.leaflet-script')

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const orderTypeSelect = document.getElementById('order_type');
            const orderDetailSelect = document.getElementById('order_detail');

            orderTypeSelect.addEventListener('change', function () {
                const selectedType = this.value; // Obtiene el valor seleccionado en "order_type"

                // Recorre todas las opciones de "order_detail"
                Array.from(orderDetailSelect.options).forEach(option => {
                    // Muestra u oculta las opciones según el tipo seleccionado
                    if (option.getAttribute('data-type') === selectedType || option.value === "") {
                        option.style.display = 'block'; // Muestra la opción
                    } else {
                        option.style.display = 'none'; // Oculta la opción
                    }
                });

                // Restablece el valor seleccionado en "order_detail"
                orderDetailSelect.value = "";
                alternarTraslado();
            });

            // ---- Traslado: se pide la dirección nueva ----
            const $bloque = $('#bloqueTraslado');

            function alternarTraslado() {
                const opcion = orderDetailSelect.selectedOptions[0];
                const esTraslado = !!(opcion && opcion.dataset.traslado);

                $bloque.find('input, select, textarea').prop('disabled', !esTraslado);
                $bloque.collapse(esTraslado ? 'show' : 'hide');

                if (!esTraslado) {
                    return;
                }

                // Sin departamento no hay municipio que elegir.
                if (!$('#department').val()) {
                    $('#municipality').prop('disabled', true);
                }

                // El parcial de dirección decide qué partes aplican según
                // el tipo de vía; se le avisa con un evento nativo.
                $bloque.find('[data-parte="tipo"]').each(function () {
                    this.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }

            orderDetailSelect.addEventListener('change', alternarTraslado);
            alternarTraslado();
        });
    </script>
@endsection
