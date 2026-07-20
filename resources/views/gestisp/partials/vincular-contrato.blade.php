{{--
    Buscador para vincular un equipo (ONT o cuenta PPPoE) con un
    contrato.

    Se usa en la vista de la ONT, en la de la cuenta PPPoE y en el
    listado de cuentas. Reutiliza el mismo endpoint de búsqueda que
    el alta de cuentas PPPoE.

    Parámetros:
      $id        identificador único del bloque en la página
      $accion    ruta POST de vinculación
      $tipo      'ont' o 'pppoe' (decide qué avisos se muestran)
--}}
@php
    $tipo = $tipo ?? 'ont';
@endphp

<form method="POST" action="{{ $accion }}" id="{{ $id }}Form">
    @csrf

    <div class="form-group position-relative mb-2">
        <label class="small text-muted mb-1">Buscar contrato</label>
        <input type="text" class="form-control" id="{{ $id }}Buscar" autocomplete="off"
               placeholder="Documento, nombre del cliente o número de contrato...">
        <div id="{{ $id }}Resultados" class="list-group shadow-sm"
             style="display:none; position:absolute; z-index:1080; width:100%; max-height:260px; overflow-y:auto;"></div>
    </div>

    <div class="form-group mb-2">
        <input type="text" class="form-control bg-light" id="{{ $id }}Seleccionado" disabled
               placeholder="Ningún contrato seleccionado">
        <input type="hidden" name="contract_id" id="{{ $id }}ContractId">
    </div>

    <button type="submit" class="btn btn-success btn-block" id="{{ $id }}Boton" disabled>
        <i class="fas fa-link mr-1"></i> Vincular contrato
    </button>
</form>

@push('js')
    <script>
        (() => {
            const buscar = document.getElementById('{{ $id }}Buscar');
            const resultados = document.getElementById('{{ $id }}Resultados');
            const seleccionado = document.getElementById('{{ $id }}Seleccionado');
            const contractId = document.getElementById('{{ $id }}ContractId');
            const boton = document.getElementById('{{ $id }}Boton');

            if (!buscar) return;

            const escapar = (v) => {
                const d = document.createElement('div');
                d.textContent = v ?? '';
                return d.innerHTML;
            };

            let temporizador = null;

            // Se espera a que el usuario deje de escribir: sin esto
            // cada tecla lanzaría una consulta a la base
            buscar.addEventListener('input', () => {
                clearTimeout(temporizador);
                const q = buscar.value.trim();

                if (q.length < 3) {
                    resultados.style.display = 'none';
                    return;
                }

                temporizador = setTimeout(() => consultar(q), 300);
            });

            const consultar = async (q) => {
                resultados.innerHTML =
                    '<span class="list-group-item text-muted small"><i class="fas fa-spinner fa-spin mr-1"></i>Buscando...</span>';
                resultados.style.display = 'block';

                try {
                    const respuesta = await fetch(
                        `{{ route('contratos.buscar') }}?q=${encodeURIComponent(q)}`
                    );

                    if (!respuesta.ok) throw new Error(respuesta.status);

                    const contratos = await respuesta.json();

                    if (!contratos.length) {
                        resultados.innerHTML =
                            '<span class="list-group-item text-muted small">Sin resultados.</span>';
                        return;
                    }

                    resultados.innerHTML = contratos.map(c => {
                        // Se avisa de lo que el contrato ya tiene para
                        // que la elección sea consciente
                        @if ($tipo === 'ont')
                        const ocupado = c.tiene_ont;
                        const aviso = ocupado
                            ? '<span class="badge badge-danger ml-1">Ya tiene ONT</span>'
                            : '';
                        @else
                        const ocupado = false;
                        const aviso = c.cuentas_pppoe > 0
                            ? `<span class="badge badge-warning ml-1">${c.cuentas_pppoe} cuenta(s)</span>`
                            : '';
                        @endif

                        return `
                            <button type="button"
                                    class="list-group-item list-group-item-action py-2 ${ocupado ? 'disabled text-muted' : ''}"
                                    data-id="${c.id}"
                                    data-label="${escapar(c.label)}"
                                    ${ocupado ? 'disabled' : ''}>
                                <div class="small">${escapar(c.label)}${aviso}</div>
                                <div class="small text-muted">Estado: ${escapar(c.estado ?? '—')}</div>
                            </button>`;
                    }).join('');

                } catch (e) {
                    console.error('Error al buscar contratos:', e);
                    resultados.innerHTML =
                        '<span class="list-group-item text-danger small">No se pudo realizar la búsqueda.</span>';
                }
            };

            resultados.addEventListener('click', (evento) => {
                const opcion = evento.target.closest('[data-id]');
                if (!opcion || opcion.disabled) return;

                contractId.value = opcion.dataset.id;
                seleccionado.value = opcion.dataset.label;
                boton.disabled = false;

                resultados.style.display = 'none';
                buscar.value = '';
            });

            // Cerrar al hacer clic fuera
            document.addEventListener('click', (evento) => {
                if (!resultados.contains(evento.target) && evento.target !== buscar) {
                    resultados.style.display = 'none';
                }
            });
        })();
    </script>
@endpush
