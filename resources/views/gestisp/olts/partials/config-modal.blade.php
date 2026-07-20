{{--
    Modal para registrar o editar una VLAN o un perfil de la OLT.

    Los tres formularios piden exactamente lo mismo (identificador,
    nombre y descripción) y solo cambian la etiqueta y el destino,
    así que comparten esta plantilla. El mismo modal sirve para
    crear y para editar: JavaScript cambia el destino, el método y
    los valores.

    Parámetros:
      id, titulo, accion, urlActualizar, campoId, etiquetaId,
      tipoId, ayudaId, maxNombre, bag, olt
--}}
@php
    $maxNombre = $maxNombre ?? 50;

    // Cada formulario tiene su propio contenedor de errores, así
    // los tres modales no se contagian entre sí: comparten los
    // campos "name" y "description".
    $bolsa = $errors->getBag($bag);

    // Si la validación falló, se vuelve a abrir el modal que la
    // provocó: si no, el usuario ve el error sin saber de dónde salió
    $conError = $bolsa->any();

    // Y se reabre en el modo correcto: si venía de editar, el id
    // del registro sigue en los valores anteriores
    $editando = $conError && old('registro_id');
@endphp

<div class="modal fade" id="{{ $id }}" tabindex="-1" role="dialog" aria-labelledby="{{ $id }}Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title" id="{{ $id }}Label">
                    <i class="fas fa-plus-circle mr-1"></i>
                    <span data-titulo>{{ $titulo }}</span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form action="{{ $accion }}" method="POST"
                  data-form
                  data-crear="{{ $accion }}"
                  data-actualizar="{{ $urlActualizar }}"
                  data-titulo-crear="{{ $titulo }}"
                  data-titulo-editar="{{ str_replace('Agregar', 'Editar', $titulo) }}">
                @csrf
                {{-- Vacío al crear; "PUT" al editar --}}
                <input type="hidden" name="_method" value="{{ $editando ? 'PUT' : '' }}" data-metodo>
                <input type="hidden" name="registro_id" value="{{ old('registro_id') }}" data-registro>

                <div class="modal-body">
                    <input type="hidden" name="olt_id" value="{{ $olt->id }}">

                    {{-- Los valores anteriores solo se recuperan en el
                         formulario que falló: old() es global y si no
                         se filtra, rellenaría también los otros dos. --}}
                    <div class="form-group">
                        <label for="{{ $id }}_{{ $campoId }}">{{ $etiquetaId }}</label>
                        <input type="{{ $tipoId }}"
                               class="form-control {{ $bolsa->has($campoId) ? 'is-invalid' : '' }}"
                               id="{{ $id }}_{{ $campoId }}"
                               name="{{ $campoId }}"
                               value="{{ $conError ? old($campoId) : '' }}"
                               @if($tipoId === 'number') min="1" max="4094" @else maxlength="50" @endif
                               required>
                        <small class="form-text text-muted">{{ $ayudaId }}</small>
                        @foreach ($bolsa->get($campoId) as $mensaje)
                            <span class="invalid-feedback d-block">{{ $mensaje }}</span>
                        @endforeach
                    </div>

                    <div class="form-group">
                        <label for="{{ $id }}_name">Nombre</label>
                        <input type="text"
                               class="form-control {{ $bolsa->has('name') ? 'is-invalid' : '' }}"
                               id="{{ $id }}_name"
                               name="name"
                               value="{{ $conError ? old('name') : '' }}"
                               maxlength="{{ $maxNombre }}"
                               required>
                        @foreach ($bolsa->get('name') as $mensaje)
                            <span class="invalid-feedback d-block">{{ $mensaje }}</span>
                        @endforeach
                    </div>

                    <div class="form-group mb-0">
                        <label for="{{ $id }}_description">Descripción <span class="text-muted">(opcional)</span></label>
                        <textarea class="form-control {{ $bolsa->has('description') ? 'is-invalid' : '' }}"
                                  id="{{ $id }}_description"
                                  name="description"
                                  rows="3"
                                  maxlength="255">{{ $conError ? old('description') : '' }}</textarea>
                        @foreach ($bolsa->get('description') as $mensaje)
                            <span class="invalid-feedback d-block">{{ $mensaje }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@if ($conError)
    @push('js')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                @if ($editando)
                    // Se reabre en modo edición apuntando al mismo registro
                    const form = document.querySelector('#{{ $id }} [data-form]');
                    form.action = form.dataset.actualizar.replace('__ID__', @json(old('registro_id')));
                    document.querySelector('#{{ $id }} [data-titulo]').textContent = form.dataset.tituloEditar;
                @endif
                $('#{{ $id }}').modal('show');
            });
        </script>
    @endpush
@endif
