$(document).ready(function() {
    let materialIndex = 0;

    // Inicializar Select2
    function initSelect2() {
        $('.material-select').select2({
            placeholder: "Buscar material",
            allowClear: true,
            templateResult: formatMaterial
        });

        $('#serial-number-select').select2({
            placeholder: "Buscar número de serie",
            allowClear: true
        });
    }
    initSelect2();

    // Elementos del DOM
    const typeSelect = $('#type');
    const warehouseOriginGroup = $('#warehouse-origin-group');
    const warehouseDestinationGroup = $('#warehouse-destination-group');
    const materialsTable = $('#materials-table tbody');
    const openModalBtn = $('#open-modal-btn');
    const addMaterialModalBtn = $('#add-material-modal-btn');
    const reasonSelect = $('#reason');
    const modalMaterialSelect = $('#modal-material-select');
    const serialNumberSelect = $('#serial-number-select');
    const serialNumberList = $('#serial-number-list');
    const warehouseOriginId = $('#warehouse_origin_id');

    // Función para cargar la cantidad disponible
    function loadAvailableQuantity() {
        const warehouseId = typeSelect.val() === 'Entrada' ?
            $('#warehouse_destination_id').val() :
            $('#warehouse_origin_id').val();
        const materialId = modalMaterialSelect.val();
        const availableQuantityText = $('#available-quantity-text');
        const availableQuantitySpan = $('#available-quantity');

        if (warehouseId && materialId && typeSelect.val() !== 'Entrada') {
            $.ajax({
                url: `/inventories/${warehouseId}/materials/${materialId}/quantity`,
                type: 'GET',
                success: function(response) {
                    availableQuantityText.show();
                    availableQuantitySpan.text(response.quantity);
                },
                error: function(xhr) {
                    const errorMessage = xhr.responseJSON?.message || 'Error al cargar la cantidad disponible';
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: errorMessage
                    });
                }
            });
        } else {
            availableQuantityText.hide();
        }
    }

    // Manejar visibilidad de almacenes y opciones de motivo
    typeSelect.on('change', function() {
        const selectedType = $(this).val();
        warehouseOriginGroup.hide();
        warehouseDestinationGroup.hide();
        reasonSelect.find('option').hide().prop('disabled', true);
        reasonSelect.find('option[value=""]').show().prop('disabled', false);

        switch(selectedType) {
            case 'Entrada':
                warehouseDestinationGroup.show();
                reasonSelect.find('.option-Entrada').show().prop('disabled', false);
                break;
            case 'Salida':
                warehouseOriginGroup.show();
                reasonSelect.find('.option-Salida').show().prop('disabled', false);
                break;
            case 'Transferencia':
                warehouseOriginGroup.show();
                warehouseDestinationGroup.show();
                reasonSelect.find('.option-Transferencia').show().prop('disabled', false);
                break;
        }
    });

    // Abrir modal de agregar material
    openModalBtn.on('click', function() {
        $('#materialModal').modal('show');
    });

    // Manejar cambio de material en el modal
    modalMaterialSelect.on('change', function() {
        const isEquipment = $(this).find('option:selected').data('is-equipment') === 1;
        const serialNumbersContainer = $('#modal-serial-numbers-container');

        if (isEquipment) {
            serialNumbersContainer.show();
            if (typeSelect.val() === 'Salida' || typeSelect.val() === 'Transferencia') {
                loadSerialNumbers();
            } else {
                serialNumberSelect.empty();
                serialNumberList.empty();
                for (let i = 0; i < $('#modal-quantity').val(); i++) {
                    const listItem = `<li><input type="text" class="form-control serial-number-input" placeholder="Ingrese SN #${i + 1}" required></li>`;
                    serialNumberList.append(listItem);
                }
            }
        } else {
            serialNumbersContainer.hide();
        }

        // Cargar cantidad disponible
        loadAvailableQuantity();
    });

    // Manejar cambio de cantidad en el modal para entradas
    $('#modal-quantity').on('change', function() {
        if (typeSelect.val() === 'Entrada' && modalMaterialSelect.find('option:selected').data('is-equipment') === 1) {
            serialNumberSelect.empty();
            serialNumberList.empty();
            for (let i = 0; i < $(this).val(); i++) {
                const listItem = `<li><input type="text" class="form-control serial-number-input" placeholder="Ingrese SN #${i + 1}" required></li>`;
                serialNumberList.append(listItem);
            }
        }
    });

    // Manejar cambio de almacén de origen en el modal
    warehouseOriginId.on('change', function() {
        if ((typeSelect.val() === 'Salida' || typeSelect.val() === 'Transferencia') && modalMaterialSelect.val() && modalMaterialSelect.find('option:selected').data('is-equipment') === 1) {
            loadSerialNumbers();
        }
        if (modalMaterialSelect.val()) {
            loadAvailableQuantity();
        }
    });

    // Manejar cambio de almacén de destino
    $('#warehouse_destination_id').on('change', function() {
        if (modalMaterialSelect.val()) {
            loadAvailableQuantity();
        }
    });

    // Cargar números de serie disponibles
    function loadSerialNumbers() {
        const warehouseId = warehouseOriginId.val();
        const materialId = modalMaterialSelect.val();

        if (warehouseId && materialId) {
            $.ajax({
                url: `/inventories/${warehouseId}/materials/${materialId}/serial-numbers`,
                type: 'GET',
                success: function(serialNumbers) {
                    serialNumberSelect.empty();
                    serialNumberList.empty();
                    serialNumbers.forEach(function(serialNumber) {
                        const option = `<option value="${serialNumber}">${serialNumber}</option>`;
                        const listItem = `<li><input type="checkbox" class="serial-number-checkbox" value="${serialNumber}"> ${serialNumber}</li>`;
                        serialNumberSelect.append(option);
                        serialNumberList.append(listItem);
                    });
                    serialNumberSelect.trigger('change'); // Actualizar Select2
                },
                error: function(xhr) {
                    const errorMessage = xhr.responseJSON?.message || 'Error al cargar los números de serie';
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: errorMessage
                    });
                }
            });
        }
    }

    // Sincronizar select y lista de números de serie
    serialNumberSelect.on('change', function() {
        const selectedSerialNumbers = $(this).val() || [];
        $('.serial-number-checkbox').each(function() {
            if (selectedSerialNumbers.includes($(this).val())) {
                $(this).prop('checked', true);
            } else {
                $(this).prop('checked', false);
            }
        });
    });

    serialNumberList.on('change', '.serial-number-checkbox', function() {
        const selectedSerialNumbers = $('.serial-number-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        serialNumberSelect.val(selectedSerialNumbers).trigger('change');
    });

    // Agregar material desde el modal
    addMaterialModalBtn.on('click', function() {
        const materialId = modalMaterialSelect.val();
        const quantity = parseInt($('#modal-quantity').val());
        const availableQuantity = parseInt($('#available-quantity').text());

        // Validar cantidad disponible para salidas y transferencias
        if (typeSelect.val() !== 'Entrada' && quantity > availableQuantity) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'La cantidad solicitada excede la cantidad disponible'
            });
            return;
        }

        const materialName = modalMaterialSelect.find('option:selected').data('name');
        const unitOfMeasurement = $('#modal-unit-of-measurement').val();
        const isEquipment = modalMaterialSelect.find('option:selected').data('is-equipment') === 1;
        const serialNumbers = [];

        // Validar campos requeridos
        if (!materialId || !quantity || !unitOfMeasurement) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Por favor complete todos los campos requeridos'
            });
            return;
        }

        if (isEquipment) {
            if (typeSelect.val() === 'Entrada') {
                $('.serial-number-input').each(function() {
                    const sn = $(this).val();
                    if (sn) {
                        serialNumbers.push(sn);
                    }
                });
            } else {
                serialNumberSelect.find('option:selected').each(function() {
                    serialNumbers.push($(this).val());
                });
            }

            // Validar números de serie para equipos
            if (serialNumbers.length !== quantity) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'La cantidad de números de serie debe coincidir con la cantidad especificada'
                });
                return;
            }
        }

        const newRow = `
            <tr data-index="${materialIndex}">
                <td>
                    <input type="hidden" name="materials[${materialIndex}][material_id]" value="${materialId}">
                    ${materialName}
                </td>
                <td>
                    <input type="hidden" name="materials[${materialIndex}][quantity]" value="${quantity}">
                    ${quantity}
                </td>
                <td>
                    <input type="hidden" name="materials[${materialIndex}][unit_of_measurement]" value="${unitOfMeasurement}">
                    ${unitOfMeasurement}
                </td>
                <td>
                    ${isEquipment ? serialNumbers.join(', ') : 'N/A'}
                    ${isEquipment ? serialNumbers.map((sn, i) => `<input type="hidden" name="materials[${materialIndex}][serial_numbers][${i}]" value="${sn}">`).join('') : ''}
                </td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm remove-material-btn">Eliminar</button>
                </td>
            </tr>
        `;

        materialsTable.append(newRow);
        materialIndex++;
        $('#materialModal').modal('hide');
        $('#materialModal').find('input, select').val('');
        $('#modal-serial-numbers-container').hide();
        $('#available-quantity-text').hide();
    });

    // Eliminar material de la tabla
    materialsTable.on('click', '.remove-material-btn', function() {
        $(this).closest('tr').remove();
    });

    // Formato de material en Select2
    function formatMaterial(material) {
        if (!material.id) return material.text;
        return $(
            `<span>
                ${material.text}
                ${material.element.getAttribute('data-is-equipment') === '1' ?
                '<small class="text-muted">(Equipo)</small>' :
                '<small class="text-muted">(Material)</small>'}
            </span>`
        );
    }

    // Manejo de errores general para las peticiones AJAX
    $(document).ajaxError(function(event, xhr, settings) {
        if (!settings.url.includes('available')) { // Para evitar duplicar mensajes
            const errorMessage = xhr.responseJSON?.message || 'Ha ocurrido un error en la operación';
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: errorMessage
            });
        }
    });
});
