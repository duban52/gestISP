/**
 * Registro de movimientos de material.
 *
 * EL FALLO QUE ARREGLA ESTE ARCHIVO
 * ---------------------------------
 * El buscador de material no respondía a las teclas. La causa es
 * conocida y no era del buscador: Bootstrap ATRAPA EL FOCO dentro del
 * modal, así que el campo de búsqueda de Select2 —que por defecto se
 * dibuja colgando de <body>, es decir FUERA del modal— nunca lo
 * recibía. Se veía la lista, se veía la cajita, y no entraba nada.
 * La solución es dropdownParent: se dibuja el desplegable dentro del
 * propio modal y el foco deja de perderse.
 *
 * CÓMO FUNCIONA LA PANTALLA
 * -------------------------
 * El tipo de movimiento manda sobre todo lo demás:
 *
 *   Entrada        solo almacén DESTINO   · los seriales se escriben
 *   Salida         solo almacén ORIGEN    · los seriales se eligen
 *   Transferencia  ambos                  · los seriales se eligen
 *
 * El modal no guarda nada: agrega una fila a la tabla con inputs
 * ocultos (materials[i][campo]) que viajan con el formulario. El
 * servidor vuelve a validar todo — stock, seriales, cantidades—,
 * así que lo de aquí es comodidad, no seguridad.
 */
$(document).ready(function () {
    'use strict';

    let materialIndex = 0;

    // ---------- Elementos ----------
    const typeSelect = $('#type');
    const warehouseOriginGroup = $('#warehouse-origin-group');
    const warehouseDestinationGroup = $('#warehouse-destination-group');
    const warehouseOriginId = $('#warehouse_origin_id');
    const warehouseDestinationId = $('#warehouse_destination_id');
    const materialsTable = $('#materials-table tbody');
    const reasonSelect = $('#reason');

    const modal = $('#materialModal');
    const modalMaterialSelect = $('#modal-material-select');
    const modalQuantity = $('#modal-quantity');
    const serialNumberSelect = $('#serial-number-select');
    const serialNumberList = $('#serial-number-list');

    /* ============================================================
       Select2

       dropdownParent es lo que hace que la búsqueda funcione dentro
       del modal (ver la cabecera del archivo). No quitarlo.
       ============================================================ */
    modalMaterialSelect.select2({
        placeholder: 'Escriba para buscar un material...',
        allowClear: true,
        width: '100%',
        dropdownParent: modal,
        language: {
            noResults: () => 'No hay materiales con ese nombre',
            searching: () => 'Buscando...',
        },
        templateResult: formatMaterial,
    });

    serialNumberSelect.select2({
        placeholder: 'Elija los seriales que salen',
        width: '100%',
        dropdownParent: modal,
        language: {
            noResults: () => 'Sin seriales disponibles',
        },
    });

    /** Cada opción muestra su categoría y si es equipo o consumible. */
    function formatMaterial(material) {
        if (!material.id) {
            return material.text;
        }

        const esEquipo = material.element.getAttribute('data-is-equipment') === '1';
        const categoria = material.element.getAttribute('data-category') || '';

        return $(
            '<div>' +
            '  <div>' + material.text + '</div>' +
            '  <small class="text-muted">' +
            '    <span class="badge badge-' + (esEquipo ? 'warning' : 'secondary') + '">' +
            (esEquipo ? 'Equipo (con serial)' : 'Consumible') +
            '    </span> ' + categoria +
            '  </div>' +
            '</div>'
        );
    }

    /* ============================================================
       Tipo de movimiento
       ============================================================ */
    typeSelect.on('change', function () {
        const tipo = $(this).val();

        warehouseOriginGroup.hide();
        warehouseDestinationGroup.hide();

        reasonSelect.find('option').hide().prop('disabled', true);
        reasonSelect.find('option[value=""]').show().prop('disabled', false);
        reasonSelect.val('');

        if (tipo === 'Entrada') {
            warehouseDestinationGroup.show();
            reasonSelect.find('.option-Entrada').show().prop('disabled', false);
        } else if (tipo === 'Salida') {
            warehouseOriginGroup.show();
            reasonSelect.find('.option-Salida').show().prop('disabled', false);
        } else if (tipo === 'Transferencia') {
            warehouseOriginGroup.show();
            warehouseDestinationGroup.show();
            reasonSelect.find('.option-Transferencia').show().prop('disabled', false);
        }

        // Cambiar de tipo cambia de dónde sale el material: lo que ya
        // estaba agregado dejaría de tener sentido.
        if (materialsTable.find('tr').length > 0) {
            materialsTable.empty();
            materialIndex = 0;
        }
    });

    /* ============================================================
       Abrir el modal

       Se exige tipo y almacén ANTES de abrir: sin ellos no se puede
       consultar disponibilidad ni seriales, y el operador llenaría
       el formulario para encontrarse un error al final.
       ============================================================ */
    $('#open-modal-btn').on('click', function () {
        const tipo = typeSelect.val();

        if (!tipo) {
            avisar('Primero elija el tipo de movimiento.');
            typeSelect.focus();
            return;
        }

        if (esSalida() && !warehouseOriginId.val()) {
            avisar('Elija el almacén de origen antes de agregar materiales.');
            warehouseOriginId.focus();
            return;
        }

        if ((tipo === 'Entrada' || tipo === 'Transferencia') && !warehouseDestinationId.val()) {
            avisar('Elija el almacén de destino antes de agregar materiales.');
            warehouseDestinationId.focus();
            return;
        }

        limpiarModal();
        pintarContexto();
        modal.modal('show');
    });

    // Con el modal ya visible el buscador puede tomar el foco
    modal.on('shown.bs.modal', function () {
        modalMaterialSelect.select2('open');
    });

    /** ¿El movimiento saca material de un almacén? */
    function esSalida() {
        return typeSelect.val() === 'Salida' || typeSelect.val() === 'Transferencia';
    }

    /** Recuerda al operador contra qué almacén está trabajando. */
    function pintarContexto() {
        const tipo = typeSelect.val();
        const origen = warehouseOriginId.find('option:selected').text();
        const destino = warehouseDestinationId.find('option:selected').text();

        let texto = '';

        if (tipo === 'Entrada') {
            texto = '<i class="fas fa-sign-in-alt text-success"></i> <strong>Entrada</strong> a ' + destino;
        } else if (tipo === 'Salida') {
            texto = '<i class="fas fa-sign-out-alt text-danger"></i> <strong>Salida</strong> de ' + origen;
        } else {
            texto = '<i class="fas fa-exchange-alt text-primary"></i> <strong>Transferencia</strong> de ' +
                origen + ' a ' + destino;
        }

        $('#modal-contexto').html(texto);
    }

    /* ============================================================
       Material elegido
       ============================================================ */
    modalMaterialSelect.on('change', function () {
        ocultarError();

        const opcion = $(this).find('option:selected');
        const esEquipo = opcion.attr('data-is-equipment') === '1';

        $('#modal-serial-numbers-container').toggleClass('d-none', !esEquipo);
        $('#serial-picker').addClass('d-none');
        $('#serial-inputs').addClass('d-none');

        if (!$(this).val()) {
            $('#available-quantity-text').addClass('d-none');
            return;
        }

        // Un equipo se cuenta por unidades; proponerlo ahorra un clic
        if (esEquipo && !$('#modal-unit-of-measurement').val()) {
            $('#modal-unit-of-measurement').val('Unidades');
        }

        if (esEquipo) {
            if (esSalida()) {
                $('#serial-picker').removeClass('d-none');
                cargarSeriales();
            } else {
                $('#serial-inputs').removeClass('d-none');
                generarCasillasDeSerial();
            }
        }

        cargarDisponibilidad();
    });

    // La cantidad manda sobre cuántos seriales se piden
    modalQuantity.on('input', function () {
        ocultarError();

        if (esEquipo() && !esSalida()) {
            generarCasillasDeSerial();
        }

        actualizarContadorDeSeriales();
    });

    function esEquipo() {
        return modalMaterialSelect.find('option:selected').attr('data-is-equipment') === '1';
    }

    /* ============================================================
       Consultas al servidor
       ============================================================ */

    /** Cuánto hay del material en el almacén de origen. */
    function cargarDisponibilidad() {
        const materialId = modalMaterialSelect.val();
        const caja = $('#available-quantity-text');

        // En una entrada no hay nada que comprobar: el material
        // todavía no está en el almacén.
        if (!esSalida() || !materialId || !warehouseOriginId.val()) {
            caja.addClass('d-none');
            return;
        }

        $.getJSON(`/inventories/${warehouseOriginId.val()}/materials/${materialId}/quantity`)
            .done(function (respuesta) {
                const disponible = Number(respuesta.quantity || 0);

                $('#available-quantity').text(disponible);
                $('#available-hint').text(
                    disponible === 0 ? 'No hay existencias en este almacén' : ''
                );
                caja.removeClass('d-none');

                // El tope del campo evita pedir más de lo que hay
                modalQuantity.attr('max', disponible);
            })
            .fail(function () {
                caja.addClass('d-none');
                mostrarError('No se pudo consultar la disponibilidad. Intente de nuevo.');
            });
    }

    /** Seriales que están en el almacén de origen. */
    function cargarSeriales() {
        const materialId = modalMaterialSelect.val();

        if (!materialId || !warehouseOriginId.val()) {
            return;
        }

        $.getJSON(`/inventories/${warehouseOriginId.val()}/materials/${materialId}/serial-numbers`)
            .done(function (seriales) {
                serialNumberSelect.empty();

                seriales.forEach(function (serial) {
                    serialNumberSelect.append(new Option(serial, serial));
                });

                serialNumberSelect.val(null).trigger('change');
                $('#serial-vacio').toggleClass('d-none', seriales.length > 0);
                actualizarContadorDeSeriales();
            })
            .fail(function () {
                mostrarError('No se pudieron cargar los números de serie.');
            });
    }

    /* ============================================================
       Seriales
       ============================================================ */

    /** Una casilla por unidad que entra. */
    function generarCasillasDeSerial() {
        const cantidad = parseInt(modalQuantity.val(), 10) || 0;
        const anteriores = serialNumberList.find('.serial-number-input')
            .map((i, el) => el.value).get();

        serialNumberList.empty();

        for (let i = 0; i < cantidad; i++) {
            serialNumberList.append(
                '<li class="mb-1">' +
                '  <div class="input-group input-group-sm">' +
                '    <div class="input-group-prepend">' +
                '      <span class="input-group-text">#' + (i + 1) + '</span>' +
                '    </div>' +
                // Se conserva lo ya escrito al cambiar la cantidad:
                // volver a teclear veinte seriales sería inaceptable.
                '    <input type="text" class="form-control serial-number-input" ' +
                '           value="' + (anteriores[i] || '') + '" ' +
                '           placeholder="Número de serie">' +
                '  </div>' +
                '</li>'
            );
        }

        actualizarContadorDeSeriales();
    }

    serialNumberSelect.on('change', actualizarContadorDeSeriales);
    serialNumberList.on('input', '.serial-number-input', actualizarContadorDeSeriales);

    /** "3 de 5": cuántos seriales van y cuántos faltan. */
    function actualizarContadorDeSeriales() {
        const cantidad = parseInt(modalQuantity.val(), 10) || 0;
        const puestos = esSalida()
            ? (serialNumberSelect.val() || []).length
            : serialNumberList.find('.serial-number-input').filter((i, el) => el.value.trim() !== '').length;

        const contador = $('#serial-counter');

        contador.text(puestos + ' de ' + cantidad);
        contador
            .removeClass('badge-secondary badge-success badge-warning')
            .addClass(cantidad > 0 && puestos === cantidad ? 'badge-success' : 'badge-warning');
    }

    /* ============================================================
       Agregar al movimiento
       ============================================================ */
    $('#add-material-modal-btn').on('click', function () {
        ocultarError();

        const materialId = modalMaterialSelect.val();
        const cantidad = parseInt(modalQuantity.val(), 10);
        const unidad = $('#modal-unit-of-measurement').val();

        if (!materialId) {
            return mostrarError('Elija un material.');
        }

        if (!cantidad || cantidad < 1) {
            return mostrarError('Indique una cantidad mayor que cero.');
        }

        if (!unidad) {
            return mostrarError('Elija la unidad de medida.');
        }

        // El mismo material dos veces en un movimiento descuadraría
        // el stock: la segunda fila se validaría contra un inventario
        // que la primera ya consumió.
        if (materialsTable.find(`tr[data-material-id="${materialId}"]`).length > 0) {
            return mostrarError('Ese material ya está en el movimiento. Quítelo primero si quiere cambiar la cantidad.');
        }

        if (esSalida()) {
            const disponible = Number($('#available-quantity').text() || 0);

            if (cantidad > disponible) {
                return mostrarError(
                    `Solo hay ${disponible} en el almacén de origen y está pidiendo ${cantidad}.`
                );
            }
        }

        // ---- Seriales ----
        let seriales = [];

        if (esEquipo()) {
            seriales = esSalida()
                ? (serialNumberSelect.val() || [])
                : serialNumberList.find('.serial-number-input')
                    .map((i, el) => el.value.trim()).get().filter(Boolean);

            if (seriales.length !== cantidad) {
                return mostrarError(
                    `Faltan seriales: indicó ${cantidad} unidad(es) y hay ${seriales.length} serial(es).`
                );
            }

            const repetidos = seriales.filter((s, i) => seriales.indexOf(s) !== i);

            if (repetidos.length > 0) {
                return mostrarError('Hay seriales repetidos: ' + [...new Set(repetidos)].join(', '));
            }
        }

        agregarFila(materialId, cantidad, unidad, seriales);

        modal.modal('hide');
    });

    /** Pinta la fila y sus inputs ocultos, que son los que se envían. */
    function agregarFila(materialId, cantidad, unidad, seriales) {
        const opcion = modalMaterialSelect.find('option:selected');
        const nombre = opcion.attr('data-name');
        const esEquipoMaterial = opcion.attr('data-is-equipment') === '1';
        const i = materialIndex;

        const ocultosSeriales = seriales
            .map((sn, j) => `<input type="hidden" name="materials[${i}][serial_numbers][${j}]" value="${escaparAtributo(sn)}">`)
            .join('');

        materialsTable.append(
            `<tr data-index="${i}" data-material-id="${materialId}">
                <td>
                    <input type="hidden" name="materials[${i}][material_id]" value="${materialId}">
                    <strong>${escaparHtml(nombre)}</strong>
                    <small class="d-block text-muted">${esEquipoMaterial ? 'Equipo' : 'Consumible'}</small>
                </td>
                <td>
                    <input type="hidden" name="materials[${i}][quantity]" value="${cantidad}">
                    ${cantidad}
                </td>
                <td>
                    <input type="hidden" name="materials[${i}][unit_of_measurement]" value="${escaparAtributo(unidad)}">
                    ${escaparHtml(unidad)}
                </td>
                <td>
                    ${seriales.length ? '<small>' + seriales.map(escaparHtml).join('<br>') + '</small>' : '<span class="text-muted">—</span>'}
                    ${ocultosSeriales}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-material-btn" title="Quitar">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`
        );

        materialIndex++;
    }

    materialsTable.on('click', '.remove-material-btn', function () {
        $(this).closest('tr').remove();
    });

    /* ============================================================
       Envío del formulario
       ============================================================ */
    $('#movementForm').on('submit', function (e) {
        if (materialsTable.find('tr').length === 0) {
            e.preventDefault();
            avisar('Agregue al menos un material al movimiento.');
            return;
        }

        // Registrar toca inventario en varias tablas: dos envíos
        // seguidos duplicarían el movimiento.
        $(this).find('button[type="submit"]')
            .prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm"></span> Registrando...');
    });

    // Al cambiar de almacén cambia el stock disponible
    warehouseOriginId.on('change', function () {
        if (modalMaterialSelect.val()) {
            cargarDisponibilidad();

            if (esEquipo() && esSalida()) {
                cargarSeriales();
            }
        }
    });

    /* ============================================================
       Utilidades
       ============================================================ */

    function limpiarModal() {
        modalMaterialSelect.val(null).trigger('change.select2');
        modalQuantity.val('').removeAttr('max');
        $('#modal-unit-of-measurement').val('');
        serialNumberSelect.empty().val(null).trigger('change.select2');
        serialNumberList.empty();
        $('#modal-serial-numbers-container').addClass('d-none');
        $('#available-quantity-text').addClass('d-none');
        $('#serial-vacio').addClass('d-none');
        ocultarError();
    }

    function mostrarError(mensaje) {
        $('#modal-error').removeClass('d-none').text(mensaje);
    }

    function ocultarError() {
        $('#modal-error').addClass('d-none').text('');
    }

    /** Aviso fuera del modal (el modal aún no está abierto). */
    function avisar(mensaje) {
        Swal.fire({ icon: 'info', title: 'Falta un dato', text: mensaje });
    }

    function escaparHtml(valor) {
        return $('<div>').text(valor == null ? '' : valor).html();
    }

    function escaparAtributo(valor) {
        return String(valor == null ? '' : valor).replace(/"/g, '&quot;');
    }
});
