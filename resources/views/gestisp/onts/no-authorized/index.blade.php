{{-- ============================================================
     ONT pendientes de activación (autofind)

     Se usa en la calle: el técnico deja el equipo puesto, entra desde
     el teléfono y lo activa. Por eso la tabla se convierte en fichas
     por debajo de 768 px (public/css/gestisp-movil.css) y los dos
     modales ocupan la pantalla completa.

     Las filas las pinta DataTables desde la consulta a la OLT, no
     Blade, así que las etiquetas de cada celda (data-label) se ponen
     en createdRow leyendo el encabezado: así no hay dos sitios donde
     mantener los mismos nombres de columna.
     ============================================================ --}}
@extends('adminlte::page')
@section('title', 'ONTs Pendientes')

@section('content_header')
    <h1 class="mb-0">
        <i class="fas fa-search mr-2"></i>ONT pendientes por activación
    </h1>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger">{{ session('success-delete') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card toque">
        <div class="card-body">
            <div class="form-group">
                <label for="olt" class="mb-1">OLT a consultar</label>
                <select class="form-control" name="olt" id="olt">
                    <option value="">Seleccione una OLT</option>
                    @foreach($olts as $olt)
                        <option value="{{ $olt->id }}">{{ $olt->name }}</option>
                    @endforeach
                </select>
            </div>

            <div id="loader" class="text-center my-3" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Cargando...</span>
                </div>
            </div>

            <div class="table-responsive">
                <table id="autofindTable" class="table table-hover table-bordered tabla-movil" style="width:100%">
                    <thead>
                    <tr>
                        <th>SN</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Ubicación (F/S/P)</th>
                        <th>Encontrada el</th>
                        <th>Acción</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modal Activar ONT --}}
    <div class="modal fade modal-movil" id="activarOntModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form id="formActivarOnt" method="POST" action="{{ route('onts.activate') }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Activar ONT</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body toque" id="activarCampos">
                        <input type="hidden" name="ont_sn" id="modalOntSn">

                        <div class="form-group">
                            <label>Ubicación</label>
                            <input type="text" class="form-control" id="modalOntLocationView" name="ont_location" readonly>
                        </div>
                        <div class="form-group">
                            <label>SN</label>
                            <input type="text" class="form-control" id="modalOntSnView" disabled>
                        </div>
                        <div class="form-group">
                            <label>Marca</label>
                            <input type="text" class="form-control" id="modalVendor" disabled>
                        </div>
                        <div class="form-group">
                            <label>Modelo</label>
                            <input type="text" class="form-control" id="modalModel" disabled>
                        </div>

                        {{-- ============================================================
                             ¿A quién pertenece la ONT?

                             Lo normal es que sea de un contrato. Pero hay
                             equipos que no le facturan a nadie —pruebas de
                             laboratorio, repetidores propios, enlaces a una
                             sede de la empresa— y antes había que inventarles
                             un contrato para poder autorizarlos.

                             La casilla llega DESMARCADA a propósito: el caso
                             con contrato es la norma y no debe costar un clic
                             extra. Marcarla es declarar una excepción, y como
                             tal queda anotada en la trazabilidad.
                             ============================================================ --}}
                        <div class="custom-control custom-switch mb-3">
                            <input type="checkbox" class="custom-control-input"
                                   id="ontSinContrato" name="sin_contrato" value="1">
                            <label class="custom-control-label" for="ontSinContrato">
                                Esta ONT <strong>no pertenece a un contrato</strong>
                            </label>
                        </div>

                        <div id="ontBloqueContrato">
                            <div class="form-group">
                                <label>Buscar Contrato</label>
                                <input
                                    type="text"
                                    id="buscarContrato"
                                    class="form-control"
                                    placeholder="Buscar por identificación, nombre o # contrato...">
                                <div id="resultadosContrato"
                                     class="list-group mt-1"
                                     style="display:none; position:absolute; z-index:9999; width:90%;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Cliente seleccionado</label>
                                <input
                                    type="text"
                                    id="clienteSeleccionadoView"
                                    class="form-control"
                                    disabled
                                    placeholder="Ninguno seleccionado">
                            </div>

                            {{-- ============================================================
                                 Dónde queda conectada físicamente

                                 Solo se ofrecen las cajas que cuelgan del MISMO puerto
                                 PON donde se está activando la ONT: son las únicas
                                 donde puede estar conectada de verdad. Y dentro de la
                                 caja, solo los puertos libres.

                                 Es OPCIONAL: hay instalaciones que no pasan por una
                                 caja documentada, y obligar aquí bloquearía la
                                 activación de la ONT por un dato de inventario.

                                 Va dentro del bloque de contrato porque el puerto de
                                 la caja lo ocupa un CONTRATO, no un equipo: sin
                                 contrato no hay a quién asignárselo.
                                 ============================================================ --}}
                            <div class="form-row">
                                <div class="form-group col-md-7">
                                    <label>
                                        Caja NAP
                                        <small class="text-muted">(opcional)</small>
                                    </label>
                                    <select class="form-control" id="ontNapBox">
                                        <option value="">Sin registrar</option>
                                    </select>
                                    <small class="form-text text-muted" id="ontNapAyuda">
                                        Se cargan al elegir la ONT.
                                    </small>
                                </div>
                                <div class="form-group col-md-5">
                                    <label>Puerto de la caja</label>
                                    <select class="form-control" name="nap_port_id" id="ontNapPort" disabled>
                                        <option value="">—</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info py-2 d-none" id="ontAvisoSinContrato">
                            <i class="fas fa-info-circle"></i>
                            La ONT quedará registrada <strong>sin cliente asociado</strong>.
                            Escriba abajo para qué es: será lo único que la identifique.
                        </div>

                        {{-- La descripción es el rótulo con el que la ONT queda
                             escrita en la OLT. Antes era un campo oculto que se
                             llenaba solo y nadie veía qué se iba a mandar al
                             equipo; ahora se muestra siempre: de solo lectura
                             cuando sale del contrato, y editable cuando no hay
                             contrato que la provea. --}}
                        <div class="form-group">
                            <label>
                                Descripción en la OLT <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="description" id="selectedDescription"
                                   class="form-control" maxlength="64" readonly required>
                            <small class="form-text text-muted" id="ontAyudaDescripcion">
                                Se toma del contrato seleccionado.
                            </small>
                        </div>

                        <input type="hidden" name="contract_id" id="selectedContractId">

                        <div class="form-group">
                            <label>VLAN</label>
                            <select name="vlan" id="vlanSelect" class="form-control" required>
                                <option value="">Seleccione una VLAN</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Line Profile</label>
                            <select name="ont_lineprofile" id="lineProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Line Profile</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Srv Profile</label>
                            <select name="ont_srvprofile" id="srvProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Srv Profile</option>
                            </select>
                        </div>

                        <input type="hidden" id="selectedOltId" name="olt_id">
                    </div>

                    {{-- ============================================================
                         Progreso de la autorización

                         Autorizar una ONT implica configurarla en la OLT por
                         consola, lo que toma varios segundos. Sin este aviso
                         el modal parecía congelado y el usuario volvía a
                         pulsar Activar, generando configuraciones duplicadas.
                         ============================================================ --}}
                    <div class="modal-body text-center py-5" id="activarProgreso" style="display:none;">
                        <div class="spinner-border text-primary" role="status" style="width:3.5rem;height:3.5rem;"></div>
                        <h5 class="mt-4 mb-2">Autorizando la ONT en la OLT...</h5>
                        <p class="text-muted mb-0">
                            Se está configurando el equipo. Este proceso puede tardar
                            hasta un minuto.<br>
                            <strong>No cierre esta ventana ni recargue la página.</strong>
                        </p>
                    </div>

                    <div class="modal-footer" id="activarBotones">
                        <button type="submit" class="btn btn-primary" id="btnActivarOnt">
                            <i class="fas fa-check-circle"></i> Activar
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Mover ONT --}}
    <div class="modal fade modal-movil" id="moverOntModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form id="formMoverOnt" method="POST" action="">
                @csrf
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">Mover ONT a nuevo puerto</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body toque">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Esta acción eliminará la ONT del puerto actual y la activará en el nuevo puerto.
                        </div>
                        <div class="form-group">
                            <label>Serial</label>
                            <input type="text" id="moverSn" class="form-control" disabled>
                        </div>
                        <div class="form-group">
                            <label>Puerto actual</label>
                            <input type="text" id="moverPortActual" class="form-control" disabled>
                        </div>
                        <div class="form-group">
                            <label>Nuevo puerto</label>
                            <input type="text" name="ont_location" id="moverPortNuevo" class="form-control" readonly>
                        </div>
                        <div class="form-group">
                            <label>VLAN</label>
                            <select name="vlan" id="moverVlanSelect" class="form-control" required>
                                <option value="">Seleccione una VLAN</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Line Profile</label>
                            <select name="ont_lineprofile" id="moverLineProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Line Profile</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Srv Profile</label>
                            <select name="ont_srvprofile" id="moverSrvProfileSelect" class="form-control" required>
                                <option value="">Seleccione un Srv Profile</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-exchange-alt"></i> Mover ONT
                        </button>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        let autofindDT = null;

        const enMovil = window.matchMedia('(max-width: 767.98px)').matches;

        $(document).ready(function () {
            // Rótulos para el modo ficha: se leen del propio encabezado
            // para no repetirlos aquí y que no se desincronicen.
            const rotulos = $('#autofindTable thead th').map(function () {
                return $(this).text().trim();
            }).get();

            autofindDT = $('#autofindTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Seleccione una OLT para ver las ONTs pendientes.'
                },
                // Cada ONT ocupa una ficha entera en el teléfono.
                pageLength: enMovil ? 10 : 25,
                columnDefs: [
                    { orderable: false, targets: [5] },
                    { defaultContent: '—', targets: '_all' }
                ],
                // El selector "mostrar N" ocupa una línea entera y no
                // aporta nada en un teléfono.
                dom: enMovil ? 'ftip' : 'lfrtip',
                // Las filas las añade JavaScript, no Blade: aquí es donde
                // se les pone la etiqueta que el CSS usa como rótulo.
                createdRow: function (fila) {
                    $(fila).find('td').each(function (i) {
                        // El serial encabeza la ficha y la acción va al
                        // pie, a lo ancho: ninguno de los dos lleva
                        // rótulo (quedaría redundante).
                        if (i === 0) {
                            $(this).addClass('celda-principal').attr('data-label', '');
                        } else if (i === rotulos.length - 1) {
                            $(this).addClass('celda-acciones').attr('data-label', '');
                        } else {
                            $(this).attr('data-label', rotulos[i] || '');
                        }
                    });
                }
            });
        });

        // Cambio de OLT
        document.getElementById('olt').addEventListener('change', function () {
            const oltId = this.value;
            document.getElementById('selectedOltId').value = oltId;
            const loader = document.getElementById('loader');

            autofindDT.clear().draw();

            ['vlanSelect', 'lineProfileSelect', 'srvProfileSelect'].forEach(id => {
                document.getElementById(id).innerHTML = '<option value="">Seleccione...</option>';
            });

            if (!oltId) return;

            loader.style.display = 'block';

            // ONTs Autofind + verificación de SN
            fetch(`/olts/${oltId}/onts-autofind`)
                .then(r => r.json())
                .then(data => {
                    loader.style.display = 'none';

                    if (data.error) {
                        autofindDT.row.add([
                            `<span class="text-danger">${data.error}</span>`,
                            '—', '—', '—', '—', '—'
                        ]).draw();
                        return;
                    }

                    if (data.length === 0) {
                        autofindDT.row.add([
                            '<span class="text-muted">No hay ONTs en autofind.</span>',
                            '—', '—', '—', '—', '—'
                        ]).draw();
                        return;
                    }

                    // Verificar cada SN contra la DB
                    const checks = data.map(ont =>
                        fetch(`/api/onts/check-sn/${encodeURIComponent(ont.ont_sn)}`)
                            .then(r => r.json())
                            .then(check => ({ ont, check }))
                    );

                    Promise.all(checks).then(results => {
                        results.forEach(({ ont, check }) => {
                            let accionBtn;

                            if (check.exists) {
                                accionBtn = `
                                    <button
                                        class="btn btn-warning btn-sm mover-btn"
                                        data-ont-id="${check.ont_id}"
                                        data-location="${ont.fspon}"
                                        data-sn="${ont.ont_sn}"
                                        data-current="${check.current_location}"
                                        title="Puerto actual: ${check.current_location}">
                                        <i class="fas fa-exchange-alt"></i> Mover a este puerto
                                    </button>`;
                            } else {
                                accionBtn = `
                                    <button
                                        class="btn btn-success btn-sm activar-btn"
                                        data-location="${ont.fspon}"
                                        data-sn="${ont.ont_sn}"
                                        data-vendor="${ont.vendor}"
                                        data-model="${ont.equipment_id}">
                                        <i class="fas fa-check-square"></i> Activar
                                    </button>`;
                            }

                            autofindDT.row.add([
                                ont.ont_sn,
                                ont.vendor,
                                ont.equipment_id,
                                ont.fspon,
                                ont.autofind_time,
                                accionBtn
                            ]);
                        });

                        autofindDT.draw();
                    });
                })
                .catch(() => {
                    loader.style.display = 'none';
                    autofindDT.row.add([
                        '<span class="text-danger">Error al conectar con la OLT</span>',
                        '—', '—', '—', '—', '—'
                    ]).draw();
                });

            // VLANs
            fetch(`/api/vlansolt/${oltId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="">Seleccione una VLAN</option>';
                    data.forEach(v => {
                        html += `<option value="${v.id_vlan}">${v.id_vlan} - ${v.name}</option>`;
                    });
                    document.getElementById('vlanSelect').innerHTML = html;
                });

            // Line Profiles
            fetch(`/api/lineprofiles/${oltId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="">Seleccione un Line Profile</option>';
                    data.forEach(p => {
                        html += `<option value="${p.id_line_profile}">${p.id_line_profile} - ${p.name}</option>`;
                    });
                    document.getElementById('lineProfileSelect').innerHTML = html;
                });

            // Srv Profiles
            fetch(`/api/srvprofiles/${oltId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="">Seleccione un Srv Profile</option>';
                    data.forEach(p => {
                        html += `<option value="${p.id_srv_profile}">${p.id_srv_profile} - ${p.name}</option>`;
                    });
                    document.getElementById('srvProfileSelect').innerHTML = html;
                });
        });

        // Botón activar → modal activación
        document.addEventListener('click', function (e) {
            if (e.target.closest('.activar-btn')) {
                const btn = e.target.closest('.activar-btn');
                document.getElementById('modalOntLocationView').value   = btn.getAttribute('data-location');
                document.getElementById('modalOntSn').value             = btn.getAttribute('data-sn');
                document.getElementById('modalOntSnView').value         = btn.getAttribute('data-sn');
                document.getElementById('modalVendor').value            = btn.getAttribute('data-vendor');
                document.getElementById('modalModel').value             = btn.getAttribute('data-model');
                document.getElementById('buscarContrato').value         = '';
                document.getElementById('clienteSeleccionadoView').value = '';
                document.getElementById('selectedContractId').value     = '';
                document.getElementById('selectedDescription').value    = '';

                cargarCajasDelPuerto(btn.getAttribute('data-location'));

                $('#activarOntModal').modal('show');
            }
        });

        // Botón mover → modal traslado
        document.addEventListener('click', function (e) {
            if (e.target.closest('.mover-btn')) {
                const btn = e.target.closest('.mover-btn');

                document.getElementById('moverSn').value         = btn.getAttribute('data-sn');
                document.getElementById('moverPortActual').value = btn.getAttribute('data-current');
                document.getElementById('moverPortNuevo').value  = btn.getAttribute('data-location');

                document.getElementById('moverVlanSelect').innerHTML =
                    document.getElementById('vlanSelect').innerHTML;
                document.getElementById('moverLineProfileSelect').innerHTML =
                    document.getElementById('lineProfileSelect').innerHTML;
                document.getElementById('moverSrvProfileSelect').innerHTML =
                    document.getElementById('srvProfileSelect').innerHTML;

                document.getElementById('formMoverOnt').action =
                    `/onts/${btn.getAttribute('data-ont-id')}/relocate`;

                $('#moverOntModal').modal('show');
            }
        });

        /* ============================================================
           ONT CON O SIN CONTRATO

           Un solo interruptor cambia el modo del formulario. La
           descripción es el mismo campo en los dos casos —lo que va a
           la OLT— y solo cambia si se llena sola o se escribe a mano.
           Tener un único campo evita el clásico "dos inputs con el
           mismo name" donde nunca se sabe cuál gana al enviar.
           ============================================================ */
        document.getElementById('ontSinContrato').addEventListener('change', function () {
            aplicarModoContratoOnt(this.checked);
        });

        /* ============================================================
           CAJA NAP Y PUERTO

           Solo se ofrecen las cajas que cuelgan del MISMO puerto PON
           donde se va a activar la ONT: son las únicas donde puede
           estar conectada físicamente. Se piden al abrir el modal y no
           al cargar la página porque el puerto libre de ahora puede
           estar ocupado dentro de diez minutos.

           Todo esto es opcional: si la OLT no tiene la red documentada
           o el sitio no pasa por una caja registrada, se deja en "sin
           registrar" y la ONT se activa igual.
           ============================================================ */
        let cajasDelPuerto = [];

        function cargarCajasDelPuerto(ubicacion) {
            const selCaja  = document.getElementById('ontNapBox');
            const selPuerto = document.getElementById('ontNapPort');
            const ayuda    = document.getElementById('ontNapAyuda');

            cajasDelPuerto = [];
            selCaja.innerHTML  = '<option value="">Sin registrar</option>';
            selPuerto.innerHTML = '<option value="">—</option>';
            selPuerto.disabled = true;

            const oltId = document.getElementById('selectedOltId').value;
            // La ubicación llega como "frame/slot/port"
            const partes = String(ubicacion || '').split('/');

            if (!oltId || partes.length < 3) {
                ayuda.textContent = 'No se pudo determinar el puerto PON de esta ONT.';
                return;
            }

            ayuda.textContent = 'Buscando cajas de este puerto…';

            const url = '{{ route('naps.by_pon_port') }}'
                + '?olt=' + encodeURIComponent(oltId)
                + '&slot=' + encodeURIComponent(partes[1])
                + '&port=' + encodeURIComponent(partes[2]);

            fetch(url)
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(function (cajas) {
                    cajasDelPuerto = cajas;

                    if (cajas.length === 0) {
                        ayuda.textContent = 'Este puerto PON no tiene cajas documentadas.';
                        return;
                    }

                    cajas.forEach(function (c) {
                        const opcion = document.createElement('option');
                        opcion.value = c.id;
                        opcion.textContent = c.codigo
                            + (c.nombre ? ' — ' + c.nombre : '')
                            + ' (' + c.disponibles + ' libres)';
                        // Una caja llena no se puede elegir, pero se
                        // muestra: saber que existe y está llena es
                        // información, no ruido.
                        opcion.disabled = c.puertos.length === 0;
                        selCaja.appendChild(opcion);
                    });

                    ayuda.textContent = cajas.length + ' caja(s) en este puerto PON.';
                })
                .catch(function () {
                    ayuda.textContent = 'No se pudieron cargar las cajas de este puerto.';
                });
        }

        document.getElementById('ontNapBox').addEventListener('change', function () {
            const selPuerto = document.getElementById('ontNapPort');
            const caja = cajasDelPuerto.find(c => String(c.id) === this.value);

            selPuerto.innerHTML = '<option value="">—</option>';
            selPuerto.disabled = !caja;

            if (!caja) {
                return;
            }

            caja.puertos.forEach(function (p) {
                const opcion = document.createElement('option');
                opcion.value = p.id;
                opcion.textContent = 'Puerto ' + p.numero;
                selPuerto.appendChild(opcion);
            });
        });

        function aplicarModoContratoOnt(sinContrato) {
            const bloque      = document.getElementById('ontBloqueContrato');
            const aviso       = document.getElementById('ontAvisoSinContrato');
            const descripcion = document.getElementById('selectedDescription');
            const ayuda       = document.getElementById('ontAyudaDescripcion');

            bloque.classList.toggle('d-none', sinContrato);
            aviso.classList.toggle('d-none', !sinContrato);

            // Al cambiar de modo se limpia lo del modo anterior: si no,
            // queda un contrato elegido en un formulario que dice que
            // no tiene contrato.
            document.getElementById('selectedContractId').value      = '';
            document.getElementById('clienteSeleccionadoView').value  = '';
            document.getElementById('buscarContrato').value           = '';
            document.getElementById('resultadosContrato').style.display = 'none';
            descripcion.value = '';

            // El bloque se OCULTA, no se quita del formulario: un
            // puerto de caja que quedara elegido se enviaría igual y
            // acabaría asignado a nadie. Se limpia a mano.
            document.getElementById('ontNapBox').value = '';
            document.getElementById('ontNapPort').innerHTML = '<option value="">—</option>';
            document.getElementById('ontNapPort').disabled = true;

            descripcion.readOnly = !sinContrato;

            ayuda.textContent = sinContrato
                ? 'Ej.: "Repetidor parque principal" o "ONT de pruebas laboratorio".'
                : 'Se toma del contrato seleccionado.';

            if (sinContrato) {
                descripcion.focus();
            }
        }

        // Buscador de contratos
        let buscarTimeout = null;

        document.getElementById('buscarContrato').addEventListener('input', function () {
            const q          = this.value.trim();
            const resultados = document.getElementById('resultadosContrato');

            clearTimeout(buscarTimeout);

            if (q.length < 2) {
                resultados.style.display = 'none';
                resultados.innerHTML     = '';
                return;
            }

            buscarTimeout = setTimeout(() => {
                fetch(`/api/contratos/buscar?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        resultados.innerHTML = '';

                        if (data.length === 0) {
                            resultados.innerHTML =
                                '<div class="list-group-item text-muted">Sin resultados</div>';
                            resultados.style.display = 'block';
                            return;
                        }

                        data.forEach(contrato => {
                            const item       = document.createElement('button');
                            item.type        = 'button';
                            item.className   = 'list-group-item list-group-item-action';
                            item.textContent = contrato.label;

                            item.addEventListener('click', function () {
                                document.getElementById('selectedContractId').value      = contrato.id;
                                document.getElementById('selectedDescription').value     = contrato.description;
                                document.getElementById('clienteSeleccionadoView').value = contrato.label;
                                document.getElementById('buscarContrato').value          = '';
                                resultados.style.display = 'none';
                                resultados.innerHTML     = '';
                            });

                            resultados.appendChild(item);
                        });

                        resultados.style.display = 'block';
                    });
            }, 300);
        });

        // Cerrar resultados al hacer click fuera
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#buscarContrato') && !e.target.closest('#resultadosContrato')) {
                document.getElementById('resultadosContrato').style.display = 'none';
            }
        });

        /* ============================================================
           PROGRESO AL AUTORIZAR UNA ONT

           La autorización configura el equipo por consola y tarda
           varios segundos. Se sustituye el formulario por un aviso de
           progreso y se bloquea el cierre del modal, para que quede
           claro que el sistema está trabajando y no se envíe dos veces.
           ============================================================ */
        document.getElementById('formActivarOnt').addEventListener('submit', function () {
            document.getElementById('activarCampos').style.display = 'none';
            document.getElementById('activarBotones').style.display = 'none';
            document.getElementById('activarProgreso').style.display = 'block';

            // Evitar que se cierre con Esc o clic fuera mientras trabaja
            $('#activarOntModal').data('bs.modal')._config.backdrop = 'static';
            $('#activarOntModal').data('bs.modal')._config.keyboard = false;
        });

        // Al reabrir el modal, restaurar el formulario (si la
        // autorización falló, el usuario debe poder reintentar)
        $('#activarOntModal').on('show.bs.modal', function () {
            document.getElementById('activarCampos').style.display = 'block';
            document.getElementById('activarBotones').style.display = 'flex';
            document.getElementById('activarProgreso').style.display = 'none';

            // Se vuelve siempre al modo por defecto (con contrato):
            // que la casilla quede marcada de la ONT anterior sería la
            // forma más fácil de dejar un equipo sin cliente por
            // descuido.
            document.getElementById('ontSinContrato').checked = false;
            aplicarModoContratoOnt(false);
        });
    </script>
@endsection
