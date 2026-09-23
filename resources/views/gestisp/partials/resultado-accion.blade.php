{{--
    Resultado de una accion contra el equipo, en un modal.

    Las acciones que van por consola a la OLT (CATV, autorizar una ONT)
    tardan hasta un minuto y vuelven con un mensaje flash. Como aviso en
    la cabecera pasaba desapercibido: el operador seguia mirando el
    boton que pulso, abajo en la pagina, y no sabia si habia funcionado.

    Uso: @include('gestisp.partials.resultado-accion') donde iban los
    avisos de session('success'), session('success-update'),
    session('success-delete') y session('error').

    Trae tambien el «procesando»: un <form data-procesando="Texto...">
    lo muestra al enviarse, y la pagina queda bloqueada hasta que la
    OLT responde y llega el resultado.
--}}
<div class="modal fade modal-movil" id="modalProcesando" tabindex="-1" role="dialog"
     data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary" style="width:3.5rem;height:3.5rem;"></div>
                <h5 class="mt-4 mb-2" id="procesandoTexto"></h5>
                <p class="text-muted mb-0">
                    Puede tardar hasta un minuto.<br>
                    <strong>No cierre esta ventana ni recargue la página.</strong>
                </p>
            </div>
        </div>
    </div>
</div>

@push('js')
    <script>
        $(document).on('submit', 'form[data-procesando]', function () {
            $('#procesandoTexto').text(this.dataset.procesando);

            // SI EL FORMULARIO VIVE DENTRO DE UN MODAL, ese modal se
            // cierra primero. Con los dos abiertos, el aviso de
            // progreso quedaba por debajo del formulario que se acaba
            // de enviar y parecia que no habia pasado nada.
            const $suyo = $(this).closest('.modal');

            if ($suyo.length) {
                $suyo.one('hidden.bs.modal', function () {
                    $('#modalProcesando').modal('show');
                }).modal('hide');

                return;
            }

            $('#modalProcesando').modal('show');
        });
    </script>
@endpush

@php
    $resultadoError = session('error');
    $resultadoExito = session('success') ?? session('success-update') ?? session('success-delete');
@endphp

@if($resultadoError || $resultadoExito)
    <div class="modal fade modal-movil" id="modalResultadoAccion" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <i class="fas {{ $resultadoError ? 'fa-times-circle text-danger' : 'fa-check-circle text-success' }}"
                       style="font-size: 3.5rem;"></i>
                    <h5 class="mt-3 mb-2">{{ $resultadoError ? 'No se pudo completar' : 'Listo' }}</h5>
                    <p class="mb-0">{{ $resultadoError ?: $resultadoExito }}</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-{{ $resultadoError ? 'danger' : 'success' }}" data-dismiss="modal">
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('js')
        <script>
            $(function () { $('#modalResultadoAccion').modal('show'); });
        </script>
    @endpush
@endif
