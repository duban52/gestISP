/**
 * Procesamiento de órdenes técnicas: modal de materiales.
 *
 * Toda la información (disponibilidad y seriales) viene incrustada en
 * las <option> del select (data-available / data-serials), así que el
 * modal NO hace ninguna llamada AJAX: carga al instante y no depende
 * de rutas ni de permisos. Antes se consultaba /public/inventories/...
 * —una ruta inexistente— y al fallar la disponibilidad quedaba en 0,
 * lo que disparaba el falso error "excede el stock disponible".
 */
$(document).ready(function () {
    // Diálogos con la estética de Bootstrap del panel
    const swalBootstrap = Swal.mixin({
        buttonsStyling: false,
        customClass: {
            confirmButton: 'btn btn-primary mx-1',
            cancelButton: 'btn btn-outline-secondary mx-1',
        },
    });

    // Select2 para el material y (más abajo) para los seriales
    $('#modal-material-select').select2({
        theme: 'bootstrap4',
        placeholder: 'Seleccione un material',
        allowClear: true,
        dropdownParent: $('#materialModal'),
    });

    $('#serial-number-select').select2({
        theme: 'bootstrap4',
        placeholder: 'Seleccione uno o varios seriales',
        allowClear: true,
        dropdownParent: $('#materialModal'),
    });

    // ---- Pad de firma del cliente ----
    // La librería SignaturePad se carga por CDN (global window).
    const signatureCanvas = document.getElementById('signature-pad');
    let signaturePad = null;

    if (signatureCanvas && window.SignaturePad) {
        signaturePad = new window.SignaturePad(signatureCanvas, {
            penColor: '#1a1a1a',
            backgroundColor: '#ffffff',
        });

        // El lienzo debe ajustar su resolución interna al tamaño real
        // en pantalla (y a la densidad del dispositivo) para que el
        // trazo no se vea borroso ni descentrado en el celular.
        const resizeSignature = function () {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const width = signatureCanvas.clientWidth;
            const height = signatureCanvas.clientHeight || 180;

            // En el primer render el layout puede no estar listo y el
            // ancho llega en 0. Si fijáramos el buffer en 0px el pad
            // quedaría "muerto" (no responde a ningún trazo, que es el
            // bug que teníamos). Se reintenta en el siguiente frame
            // hasta que el navegador reporte un ancho real.
            if (!width) {
                window.requestAnimationFrame(resizeSignature);
                return;
            }

            // Conservar lo ya dibujado al redimensionar
            const data = signaturePad.toData();
            signatureCanvas.width = width * ratio;
            signatureCanvas.height = height * ratio;
            signatureCanvas.getContext('2d').scale(ratio, ratio);
            signaturePad.clear();

            if (data && data.length) {
                signaturePad.fromData(data);
            }
        };

        window.addEventListener('resize', resizeSignature);
        window.addEventListener('load', resizeSignature);
        resizeSignature();

        $('#clear-signature-btn').on('click', function () {
            signaturePad.clear();
        });
    }

    // Materiales agregados a la orden
    let selectedMaterials = [];

    // ---- Abrir / preparar el modal ----
    $('#open-modal-btn').on('click', function () {
        resetModal();
        $('#materialModal').modal('show');
    });

    // ---- Cambio de material seleccionado ----
    $('#modal-material-select').on('change', function () {
        const option = $(this).find('option:selected');
        const isEquipment = option.data('is-equipment') == 1;
        const available = parseInt(option.data('available'), 10) || 0;

        $('#available-quantity').text(available);
        $('#available-quantity-text').toggle(Boolean($(this).val()));

        const serialSelect = $('#serial-number-select');
        serialSelect.empty();

        if (isEquipment) {
            // Equipos: la cantidad la marcan los seriales elegidos
            const serials = option.data('serials') || [];
            serials.forEach(function (sn) {
                serialSelect.append(new Option(sn, sn, false, false));
            });
            serialSelect.trigger('change');

            $('#modal-serial-numbers-container').show();
            $('#modal-quantity-group').hide();
            $('#modal-unit-of-measurement').val('Unidades');
        } else {
            // Consumibles: cantidad manual
            $('#modal-serial-numbers-container').hide();
            $('#modal-quantity-group').show();
            $('#modal-quantity').val(1);
        }
    });

    // ---- Agregar material a la orden ----
    $('#add-material-modal-btn').on('click', function () {
        const option = $('#modal-material-select').find('option:selected');
        const materialId = $('#modal-material-select').val();
        const materialName = option.data('name');
        const isEquipment = option.data('is-equipment') == 1;
        const available = parseInt(option.data('available'), 10) || 0;
        const unitOfMeasurement = $('#modal-unit-of-measurement').val();

        if (!materialId) {
            swalBootstrap.fire('Falta el material', 'Seleccione un material de la lista.', 'warning');
            return;
        }
        if (!unitOfMeasurement) {
            swalBootstrap.fire('Falta la unidad', 'Seleccione la unidad de medida.', 'warning');
            return;
        }

        let quantity;
        let serialNumbers = [];

        if (isEquipment) {
            serialNumbers = $('#serial-number-select').val() || [];

            if (serialNumbers.length === 0) {
                swalBootstrap.fire('Faltan los seriales', 'Seleccione al menos un número de serie del equipo instalado.', 'warning');
                return;
            }
            quantity = serialNumbers.length;
        } else {
            quantity = parseInt($('#modal-quantity').val(), 10);

            if (!quantity || quantity < 1) {
                swalBootstrap.fire('Cantidad inválida', 'Indique una cantidad mayor que cero.', 'warning');
                return;
            }
        }

        // Validar contra el stock real (ya considerando lo ya agregado
        // en esta misma orden para el mismo material)
        const yaAgregado = selectedMaterials
            .filter((m) => m.materialId === materialId)
            .reduce((sum, m) => sum + m.quantity, 0);

        if (quantity + yaAgregado > available) {
            const restante = available - yaAgregado;
            swalBootstrap.fire(
                'Stock insuficiente',
                `Solo hay ${available} disponible(s) de "${materialName}"` +
                (yaAgregado ? ` y ya agregaste ${yaAgregado} (quedan ${restante}).` : '.'),
                'error'
            );
            return;
        }

        selectedMaterials.push({
            materialId: materialId,
            materialName: materialName,
            quantity: quantity,
            unitOfMeasurement: unitOfMeasurement,
            serialNumbers: serialNumbers,
        });

        updateMaterialsTable();
        $('#materialModal').modal('hide');
    });

    // ---- Tabla de materiales agregados ----
    function updateMaterialsTable() {
        const tableBody = $('#materials-table tbody');
        tableBody.empty();

        if (selectedMaterials.length === 0) {
            tableBody.append(`
                <tr id="no-materials-row">
                    <td colspan="5" class="text-center text-muted py-3">
                        <i class="fas fa-box-open mr-1"></i> Aún no se ha agregado material
                    </td>
                </tr>
            `);
            return;
        }

        selectedMaterials.forEach(function (material, index) {
            const serialsHtml = (material.serialNumbers && material.serialNumbers.length)
                ? material.serialNumbers.map((sn) => `<span class="serial-badge">${sn}</span>`).join(' ')
                : '<span class="text-muted">N/A</span>';

            tableBody.append(`
                <tr>
                    <td>${material.materialName}</td>
                    <td class="text-center">${material.quantity}</td>
                    <td>${material.unitOfMeasurement}</td>
                    <td>${serialsHtml}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-material-btn" data-index="${index}" title="Quitar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    // ---- Quitar un material ----
    $('#materials-table').on('click', '.remove-material-btn', function () {
        const index = $(this).data('index');
        selectedMaterials.splice(index, 1);
        updateMaterialsTable();
    });

    // ---- Envío del formulario ----
    $('#process-order-form').on('submit', function (e) {
        e.preventDefault();
        const form = this;

        // Las instalaciones exigen material (también se valida en el
        // servidor, pero avisamos antes para no perder el formulario)
        const requiresMaterial = $(form).data('requires-material') == 1;

        if (requiresMaterial && selectedMaterials.length === 0) {
            swalBootstrap.fire(
                'Falta el material',
                'Esta orden de instalación requiere registrar el material y los equipos instalados.',
                'warning'
            );
            return;
        }

        // La firma del cliente es obligatoria para cerrar la orden
        if (signaturePad && signaturePad.isEmpty()) {
            swalBootstrap.fire(
                'Falta la firma',
                'Pida al cliente que firme en pantalla antes de procesar la orden.',
                'warning'
            );
            return;
        }

        swalBootstrap.fire({
            title: '¿Procesar la orden?',
            text: 'Se descontará el material de tu almacén y la orden pasará a verificación.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, procesar',
            cancelButtonText: 'Cancelar',
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }

            // Volcar la firma (Data URL PNG) al campo oculto
            if (signaturePad && !signaturePad.isEmpty()) {
                $('#client-signature-input').val(signaturePad.toDataURL('image/png'));
            }

            // Volcar los materiales al formulario como campos ocultos.
            // El backend espera UN serial por fila con cantidad 1, así
            // que los equipos con varios seriales se expanden en una
            // entrada por serial (cada una cantidad 1).
            $(form).find('.material-hidden-input').remove();

            let row = 0;

            selectedMaterials.forEach(function (material) {
                if (material.serialNumbers && material.serialNumbers.length) {
                    material.serialNumbers.forEach(function (sn) {
                        addHidden(form, `material_id[${row}]`, material.materialId);
                        addHidden(form, `quantity[${row}]`, 1);
                        addHidden(form, `serial_number[${row}]`, sn);
                        row++;
                    });
                } else {
                    addHidden(form, `material_id[${row}]`, material.materialId);
                    addHidden(form, `quantity[${row}]`, material.quantity);
                    row++;
                }
            });

            form.submit();
        });
    });

    function addHidden(form, name, value) {
        $('<input>').attr({
            type: 'hidden',
            name: name,
            value: value,
            class: 'material-hidden-input',
        }).appendTo(form);
    }

    function resetModal() {
        $('#modal-material-select').val('').trigger('change');
        $('#modal-quantity').val(1);
        $('#modal-unit-of-measurement').val('');
        $('#serial-number-select').empty().trigger('change');
        $('#modal-serial-numbers-container').hide();
        $('#modal-quantity-group').show();
        $('#available-quantity-text').hide();
    }
});
