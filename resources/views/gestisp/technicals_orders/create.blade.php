@extends('adminlte::page')

@section('title', 'Crear orden')

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
                            <option value="{{ $tipo->name }}">{{ $tipo->name }}</option>
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
                                    @if($detalle->tocaEquipos())
                                        title="Al cerrarla{{ $detalle->target_contract_status ? ', el contrato pasa a ' . $detalle->target_contract_status . ' y' : '' }} se {{ $detalle->pppoe_action === 'habilitar' ? 'habilitan' : 'deshabilitan' }} los equipos del cliente"
                                    @elseif($detalle->target_contract_status)
                                        title="Al cerrarla, el contrato pasa a {{ $detalle->target_contract_status }}"
                                    @endif>
                                    {{ $detalle->name }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
                <div class="col-12 mt-2">
                    <label for="initial_comment">Comentario</label>
                    <textarea class="form-control" name="initial_comment" id="initial_comment" cols="30" rows="5"></textarea>
                </div>
                <div class="col-12 text-center mt-3">
                    <input type="submit" value="Crear orden" title="Crear orden técnica" class="btn btn-success col-md-3">
                </div>
            </form>

        </div>
    </div>
@endsection
@section('js')
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
            });
        });
    </script>
@endsection
