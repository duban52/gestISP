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

    // Seriales cargados desde un archivo. Mientras los hay mandan sobre
    // la lista y el selector: con 500 equipos nadie los teclea uno a uno.
    let serialesDeArchivo = null;

    // Los que se van agregando a mano con Enter o con «+».
    let serialesManuales = [];

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

        // De quién se compró solo tiene sentido en una entrada.
        $('#datos-compra').toggleClass('d-none', tipo !== 'Entrada');

        if (tipo !== 'Entrada') {
            $('#supplier, #invoice_number, #invoice_date').val('');
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
        mostrarValorDeCompra();
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

    /**
     * ¿Se pintan las columnas de costo?
     *
     * Lo decide el servidor con el permiso `materials.costs` y llega en
     * `data-ver-costos`. Se lee de ahí y no se deduce aquí: la cabecera
     * de la tabla la pinta Blade con el mismo @can, y si los dos no
     * coinciden las filas quedan descuadradas.
     */
    function verCostos() {
        return materialsTable.closest('table').attr('data-ver-costos') === '1';
    }

    /**
     * El costo solo se pide en las ENTRADAS.
     *
     * En un traslado el costo viaja con la existencia y en una salida no
     * hay nada que costear; el servidor lo ignora en ambos casos.
     */
    function mostrarValorDeCompra() {
        const grupo = $('#modal-valor-compra-group');

        if (grupo.length === 0) {
            return; // sin permiso: la casilla ni existe
        }

        grupo.toggleClass('d-none', typeSelect.val() !== 'Entrada');
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
        quitarArchivoDeSeriales();
        serialesManuales = [];
        serialNumberList.empty();
        $('#serial-quick').val('');
        // Un consumible se cuenta a mano; un equipo, no (prepararSeriales).
        modalQuantity.prop('readonly', false);

        const opcion = $(this).find('option:selected');
        const esEquipo = opcion.attr('data-is-equipment') === '1';

        $('#modal-serial-numbers-container').toggleClass('d-none', !esEquipo);
        $('#serial-picker').addClass('d-none');
        $('#serial-inputs').addClass('d-none');

        if (!$(this).val()) {
            $('#available-quantity-text').addClass('d-none');
            return;
        }

        pintarUnidad();

        // Se PROPONE el valor del catálogo, no se impone: quien registra
        // la entrada sabe lo que pagó en esta compra, que puede no ser
        // el de referencia. Y si lo borra, el material entra sin
        // valorar, que es distinto de entrar a cero.
        const casillaValor = $('#modal-purchase-unit-value');

        if (casillaValor.length > 0 && !casillaValor.val()) {
            const referencia = opcion.attr('data-purchase-value');

            if (referencia) {
                casillaValor.val(referencia);
            }
        }

        if (esEquipo) {
            prepararSeriales();
        }

        cargarDisponibilidad();
    });

    /** Selector (salidas) o caja de escritura (entradas) para los seriales. */
    function prepararSeriales() {
        if (esSalida()) {
            $('#serial-picker').removeClass('d-none');
            cargarSeriales();
        } else {
            $('#serial-inputs').removeClass('d-none');
            pintarSerialesManuales();
        }

        // Un equipo vale una unidad: la cantidad son los seriales que
        // haya, y escribirla aparte solo servía para descuadrarla.
        modalQuantity.prop('readonly', true);
    }

    // En consumibles la cantidad se escribe; en equipos la dan los
    // seriales y el campo va bloqueado (ver sincronizarCantidad).
    modalQuantity.on('input', ocultarError);

    function esEquipo() {
        return modalMaterialSelect.find('option:selected').attr('data-is-equipment') === '1';
    }

    /** La unidad en la que se mide el material elegido. */
    function unidadDelMaterial() {
        return modalMaterialSelect.find('option:selected').attr('data-unit') || '';
    }

    /**
     * Enseña la unidad al lado de la cantidad.
     *
     * No es un campo: es para que quien registra sepa contra qué está
     * contando. Escribir «200» sin ver si son metros o unidades es
     * justo lo que hacía que las existencias no significaran nada.
     */
    function pintarUnidad() {
        $('#modal-unit-label').text(unidadDelMaterial() || '—');
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
                sincronizarCantidad();
            })
            .fail(function () {
                mostrarError('No se pudieron cargar los números de serie.');
            });
    }

    /* ============================================================
       Seriales
       ============================================================ */

    /**
     * Agrega uno o varios seriales a la lista.
     *
     * Acepta lo pegado de un Excel o de un WhatsApp: separa por saltos
     * de línea, comas, punto y coma o tabulaciones. Avisa de los que ya
     * estaban en vez de meterlos dos veces.
     */
    function agregarSerialesManuales(texto) {
        const partes = String(texto || '')
            .split(/[\r\n,;\t]+/)
            .map((s) => s.trim())
            .filter(Boolean);

        if (partes.length === 0) {
            return;
        }

        const repetidos = [];

        partes.forEach(function (serial) {
            const yaEsta = serialesManuales
                .some((s) => s.toLowerCase() === serial.toLowerCase());

            if (yaEsta) {
                repetidos.push(serial);
                return;
            }

            serialesManuales.push(serial);
        });

        pintarSerialesManuales();

        if (repetidos.length > 0) {
            mostrarError('Ya estaba(n) en la lista: ' + [...new Set(repetidos)].join(', '));
        } else {
            ocultarError();
        }
    }

    /** La lista, del último agregado al primero: el recién puesto se ve. */
    function pintarSerialesManuales() {
        serialNumberList.empty();

        serialesManuales.slice().reverse().forEach(function (serial, posicion) {
            const numero = serialesManuales.length - posicion;

            serialNumberList.append(
                '<li class="d-flex align-items-center border-bottom py-1">' +
                '  <span class="badge badge-light mr-2">' + numero + '</span>' +
                '  <span class="text-monospace flex-grow-1">' + escaparHtml(serial) + '</span>' +
                '  <button type="button" class="btn btn-link btn-sm text-danger p-0 quitar-serial" ' +
                '          data-serial="' + escaparAtributo(serial) + '" title="Quitar">' +
                '    <i class="fas fa-times"></i></button>' +
                '</li>'
            );
        });

        sincronizarCantidad();
    }

    serialNumberList.on('click', '.quitar-serial', function () {
        const serial = $(this).data('serial');

        serialesManuales = serialesManuales.filter((s) => s !== String(serial));
        pintarSerialesManuales();
    });

    $('#serial-add-btn').on('click', function () {
        agregarSerialesManuales($('#serial-quick').val());
        $('#serial-quick').val('').focus();
    });

    $('#serial-quick').on('keydown', function (e) {
        if (e.key !== 'Enter') {
            return;
        }

        // Enter aquí agrega el serial; sin esto enviaría el formulario
        // entero con el movimiento a medio armar.
        e.preventDefault();
        agregarSerialesManuales(this.value);
        this.value = '';
    });

    serialNumberSelect.on('change', sincronizarCantidad);

    /** Cuántos seriales hay puestos, vengan de donde vengan. */
    function cuantosSeriales() {
        if (serialesDeArchivo) {
            return serialesDeArchivo.length;
        }

        return esSalida() ? (serialNumberSelect.val() || []).length : serialesManuales.length;
    }

    /** En equipos, la cantidad SON los seriales. */
    function sincronizarCantidad() {
        const puestos = cuantosSeriales();

        if (esEquipo()) {
            modalQuantity.val(puestos || '');
        }

        $('#serial-counter')
            .text(puestos + ' serial(es)')
            .removeClass('badge-secondary badge-success badge-warning')
            .addClass(puestos > 0 ? 'badge-success' : 'badge-secondary');
    }

    /* ============================================================
       Agregar al movimiento
       ============================================================ */
    $('#add-material-modal-btn').on('click', function () {
        ocultarError();

        const materialId = modalMaterialSelect.val();
        const cantidad = parseInt(modalQuantity.val(), 10);
        // DEL MATERIAL, no de un select: la unidad se declara al crear
        // el material. Ya no hay nada que validar aquí.
        const unidad = unidadDelMaterial();

        if (!materialId) {
            return mostrarError('Elija un material.');
        }

        if (!cantidad || cantidad < 1) {
            return mostrarError('Indique una cantidad mayor que cero.');
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
            seriales = serialesDeArchivo
                ? serialesDeArchivo.slice()
                : esSalida()
                    ? (serialNumberSelect.val() || [])
                    : serialesManuales.slice();

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

        // Vacío es «no se sabe», y así viaja: null. El servidor lo
        // guarda como NULL y no como cero, porque un cero se suma en
        // los totales como si el material fuera gratis.
        const valorCrudo = $('#modal-purchase-unit-value').val();
        const valorUnitario = (typeSelect.val() === 'Entrada' && valorCrudo !== '' && valorCrudo != null)
            ? Number(valorCrudo)
            : null;

        if (valorUnitario !== null && (isNaN(valorUnitario) || valorUnitario < 0)) {
            return mostrarError('El valor unitario de compra no puede ser negativo.');
        }

        agregarFila(materialId, cantidad, unidad, seriales, valorUnitario);

        modal.modal('hide');
    });

    /** Pinta la fila y sus inputs ocultos, que son los que se envían. */
    function agregarFila(materialId, cantidad, unidad, seriales, valorUnitario) {
        const opcion = modalMaterialSelect.find('option:selected');
        const nombre = opcion.attr('data-name');
        const esEquipoMaterial = opcion.attr('data-is-equipment') === '1';
        const i = materialIndex;

        // UN campo con un serial por línea, no un input por serial: con
        // 500 equipos PHP cortaría la petición al pasar de max_input_vars
        // y se perderían seriales sin avisar. Un textarea y no un input
        // oculto, porque un input se come los saltos de línea.
        const ocultosSeriales = seriales.length
            ? `<textarea class="d-none" name="materials[${i}][serials_text]">${escaparHtml(seriales.join('\n'))}</textarea>`
            : '';

        // En la tabla, los primeros: quinientos seriales la harían ilegible.
        const muestraSeriales = seriales.length > 5
            ? seriales.slice(0, 5).map(escaparHtml).join('<br>') + `<br><em>… y ${seriales.length - 5} más</em>`
            : seriales.map(escaparHtml).join('<br>');

        // La celda solo se pinta si la cabecera también la lleva. El
        // input oculto solo viaja si hay valor: mandarlo vacío haría que
        // el servidor recibiera '' donde debe recibir «nada».
        const celdaValor = verCostos()
            ? `<td class="text-right">
                    ${valorUnitario !== null
                        ? `<input type="hidden" name="materials[${i}][purchase_unit_value]" value="${valorUnitario}">
                           $${valorUnitario.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                        : '<span class="text-muted" title="Sin valor de compra: no se sumará al inventario valorado">—</span>'}
               </td>`
            : '';

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
                ${celdaValor}
                <td>
                    ${seriales.length ? '<small>' + muestraSeriales + '</small>' : '<span class="text-muted">—</span>'}
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

    /* ============================================================
       Seriales desde un archivo

       El servidor lee el archivo y dice cuáles valen y por qué no los
       demás; aquí solo se enseña. Al guardar el movimiento se vuelven
       a revisar todos.
       ============================================================ */
    $('#serial-file').on('change', function () {
        const input = this;
        const archivo = input.files && input.files[0];

        if (!archivo) {
            return;
        }

        ocultarError();

        if (!modalMaterialSelect.val()) {
            input.value = '';
            return mostrarError('Elija primero el material.');
        }

        const datos = new FormData();
        datos.append('archivo', archivo);
        datos.append('type', typeSelect.val());
        datos.append('material_id', modalMaterialSelect.val());
        datos.append('_token', $('meta[name="csrf-token"]').attr('content'));

        if (esSalida()) {
            datos.append('warehouse_origin_id', warehouseOriginId.val());
        }

        $('#serial-file-result').removeClass('d-none')
            .html('<span class="spinner-border spinner-border-sm"></span> Leyendo el archivo…');

        $.ajax({
            url: modal.attr('data-url-seriales'),
            method: 'POST',
            data: datos,
            processData: false,
            contentType: false,
            dataType: 'json',
        })
            .done(usarArchivoDeSeriales)
            .fail(function (xhr) {
                const r = xhr.responseJSON || {};
                const detalle = r.errors ? Object.values(r.errors).flat().join(' ') : null;

                $('#serial-file-result').addClass('d-none').empty();
                mostrarError(r.error || detalle || 'No se pudo leer el archivo.');
            })
            .always(function () {
                input.value = '';
            });
    });

    /**
     * El resumen de lo leído, ANTES de agregar nada.
     *
     * Quien sube un archivo de 500 seriales tiene que ver qué va a
     * entrar y qué se quedó fuera antes de confirmar: después el
     * movimiento ya está hecho y no hay botón de deshacer.
     */
    function usarArchivoDeSeriales(r) {
        serialesDeArchivo = r.validos;

        modalQuantity.val(r.validos.length).prop('readonly', true);
        $('#serial-picker, #serial-inputs').addClass('d-none');

        const material = modalMaterialSelect.find('option:selected').attr('data-name') || '';
        const destino = typeSelect.val() === 'Salida'
            ? 'sale de ' + warehouseOriginId.find('option:selected').text()
            : (typeSelect.val() === 'Entrada'
                ? 'entra a ' + warehouseDestinationId.find('option:selected').text()
                : 'pasa de ' + warehouseOriginId.find('option:selected').text() +
                  ' a ' + warehouseDestinationId.find('option:selected').text());

        let html =
            `<div class="card mb-0">
                <div class="card-header py-1 px-2 ${r.problemas.length ? 'bg-warning' : 'bg-success'}">
                    <strong>Resumen del archivo</strong> · ${escaparHtml(r.archivo)}
                </div>
                <div class="card-body p-2">
                    <div>Leídos: <strong>${r.leidos}</strong> ·
                         Válidos: <strong>${r.validos.length}</strong> ·
                         Descartados: <strong>${r.problemas.length}</strong></div>
                    <div class="text-muted">${escaparHtml(material)}: ${escaparHtml(destino)}</div>`;

        if (r.validos.length) {
            html += `<details class="mt-1"><summary>Ver los ${r.validos.length} seriales que entran</summary>
                        <div class="text-monospace small mt-1" style="max-height: 160px; overflow-y: auto;">
                            ${r.validos.slice(0, 500).map(escaparHtml).join('<br>')}
                            ${r.validos.length > 500 ? `<br><em>… y ${r.validos.length - 500} más</em>` : ''}
                        </div>
                     </details>`;
        }

        if (r.problemas.length) {
            html += `<details class="mt-1" open><summary class="text-danger">
                        Ver los ${r.problemas.length} que NO se toman</summary>
                        <ul class="mb-1 pl-3" style="max-height: 160px; overflow-y: auto;">` +
                r.problemas.slice(0, 100)
                    .map((p) => `<li><code>${escaparHtml(p.serial)}</code> — ${escaparHtml(p.motivo)}</li>`)
                    .join('') +
                '</ul>' +
                (r.problemas.length > 100 ? `<div>… y ${r.problemas.length - 100} más.</div>` : '') +
                '</details>';
        }

        html += `<div class="mt-2">
                    ${r.validos.length
                        ? 'Revise y pulse <strong>«Agregar al movimiento»</strong> para confirmar.'
                        : '<span class="text-danger">No hay ningún serial que agregar.</span>'}
                    <button type="button" class="btn btn-link btn-sm p-0 ml-2" id="serial-file-remove">
                        Quitar el archivo y ponerlos a mano</button>
                 </div>
            </div></div>`;

        $('#serial-file-result').removeClass('d-none').html(html);
        sincronizarCantidad();
    }

    $(document).on('click', '#serial-file-remove', function () {
        quitarArchivoDeSeriales();
        modalQuantity.val('');
        prepararSeriales();
        sincronizarCantidad();
    });

    function quitarArchivoDeSeriales() {
        serialesDeArchivo = null;
        modalQuantity.prop('readonly', false);
        $('#serial-file-result').addClass('d-none').empty();
        $('#serial-file').val('');
    }

    // Al cambiar de almacén cambia el stock disponible
    warehouseOriginId.on('change', function () {
        // Lo del archivo se revisó contra el almacén anterior.
        if (serialesDeArchivo) {
            quitarArchivoDeSeriales();
            modalQuantity.val('');
        }

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
        $('#modal-unit-label').text('—');
        $('#modal-purchase-unit-value').val('');
        serialNumberSelect.empty().val(null).trigger('change.select2');
        serialNumberList.empty();
        serialesManuales = [];
        $('#serial-quick').val('');
        modalQuantity.prop('readonly', false);
        quitarArchivoDeSeriales();
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
