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
                <div class="col-md-6">
                    <label for="order_type">Tipo de orden</label>
                    <select class="form-control" name="order_type" id="order_type">
                        <option value="">Seleccione ...</option>
                        <option value="Servicio">Orden de servicio</option>
                        <option value="Incidencia">Incidencia</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="order_detail">Detalle orden</label>
                    <select class="form-control" name="order_detail" id="order_detail">
                        <option value="">Seleccione ...</option>
                        <!-- Opciones de servicio -->
                        <option value="Instalacion de servicio" data-type="Servicio">Instalación de servicio</option>
                        <option value="Retiro de servicio" data-type="Servicio">Retiro de servicio</option>
                        <option value="Corte de servicio" data-type="Servicio">Corte de servicio</option>
                        <option value="Traslado de servicio" data-type="Servicio">Traslado de servicio</option>
                        <option value="Adicion de servicio" data-type="Servicio">Adición de servicio</option>
                        <option value="Suspensión temporal" data-type="Servicio">Suspensión temporal</option>
                        <option value="Reconexión" data-type="Servicio">Reconexión</option>
                        <!-- Opciones de incidencia -->
                        <option value="Sin servicio de TV" data-type="Incidencia">Sin servicio de TV</option>
                        <option value="Sin servicio de internet" data-type="Incidencia">Sin servicio de internet</option>
                        <option value="Sin servicio" data-type="Incidencia">Sin servicio</option>
                        <option value="Configuraciones" data-type="Incidencia">Configuraciones</option>
                        <option value="Otros" data-type="Incidencia">Otros</option>
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
