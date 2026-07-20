@extends('adminlte::page')
@section('title', 'OLTs')
@section('content_header')
    <div class="card p-3">
        <h2>EDITAR INFORMACIÓN DE LA OLT {{ $olt->name }}</h2>
    </div>
@endsection

@section('content')

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('olts.update', $olt) }}">
                @csrf
                @method('PUT')
                <div class="row">

                    <div class="form-group col-12 col-md-6">
                        <label for="name">Nombre de la OLT</label>
                        <input type="text" class="form-control" id="name" name="name"
                               placeholder="Ingrese un nombre para la OLT" minlength="5" maxlength="255"
                               value="{{ old('name', $olt->name) }}">
                        @error('name')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="ip_address">Dirección IP</label>
                        <input type="text" class="form-control" id="ip_address" name="ip_address"
                               placeholder="Ingrese la dirección IP de la OLT" minlength="5" maxlength="255"
                               value="{{ old('ip_address', $olt->ip_address) }}">
                        @error('ip_address')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="ssh_port">Puerto SSH</label>
                        <input type="text" class="form-control" id="ssh_port" name="ssh_port"
                               placeholder="Ingrese el puerto SSH de la OLT"
                               value="{{ old('ssh_port', $olt->ssh_port) }}">
                        @error('ssh_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="telnet_port">Puerto Telnet</label>
                        <input type="text" class="form-control" id="telnet_port" name="telnet_port"
                               placeholder="Ingrese el puerto Telnet de la OLT"
                               value="{{ old('telnet_port', $olt->telnet_port) }}">
                        @error('telnet_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="snmp_port">Puerto SNMP</label>
                        <input type="text" class="form-control" id="snmp_port" name="snmp_port"
                               placeholder="Ingrese el puerto SNMP de la OLT"
                               value="{{ old('snmp_port', $olt->snmp_port) }}">
                        @error('snmp_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    {{-- Ojo: estos dos campos mostraban el puerto SSH y un
                         valor vacío. Al guardar, la comunidad de lectura se
                         sobrescribía con "22" y las consultas SNMP dejaban
                         de funcionar. --}}
                    <div class="form-group col-12 col-md-6">
                        <label for="read_snmp_comunity">Comunidad SNMP Lectura</label>
                        <input type="text" class="form-control" id="read_snmp_comunity" name="read_snmp_comunity"
                               placeholder="Ingrese la comunidad SNMP de lectura"
                               value="{{ old('read_snmp_comunity', $olt->read_snmp_comunity) }}">
                        @error('read_snmp_comunity')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="write_snmp_comunity">Comunidad SNMP Escritura</label>
                        <input type="text" class="form-control" id="write_snmp_comunity" name="write_snmp_comunity"
                               placeholder="Ingrese la comunidad SNMP de escritura"
                               value="{{ old('write_snmp_comunity', $olt->write_snmp_comunity) }}">
                        @error('write_snmp_comunity')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="username">Usuario de acceso</label>
                        <input type="text" class="form-control" id="username" name="username"
                               placeholder="Ingrese el nombre de usuario"
                               value="{{ old('username', $olt->username) }}">
                        @error('username')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="password">Contraseña de acceso</label>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Déjela en blanco para conservar la actual">
                        <small class="form-text text-muted">
                            Solo escriba algo si desea cambiarla.
                        </small>
                        @error('password')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="brand">Marca</label>
                        <input type="text" class="form-control" id="brand" disabled
                               value="{{ $olt->brand }}">
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="model">Modelo</label>
                        <input type="text" class="form-control" id="model" disabled
                               value="{{ $olt->model }}">
                    </div>

                </div>

                <div class="col-12 text-center">
                    <input type="submit" value="Actualizar OLT" class="btn btn-primary col-md-3">
                </div>

            </form>
        </div>
    </div>

    {{-- Configuración de la OLT.
         Estas VLANs y perfiles ya existen en el equipo: aquí solo se
         registran para poder ofrecerlos al autorizar una ONT. --}}
    <div class="card card-primary card-outline card-outline-tabs">
        <div class="card-header p-0 border-bottom-0">
            <ul class="nav nav-tabs" id="oltConfigTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="vlans-tab" data-toggle="pill" href="#vlans-pane"
                       role="tab" aria-controls="vlans-pane" aria-selected="true">
                        <i class="fas fa-project-diagram mr-1"></i> VLANs
                        <span class="badge badge-primary ml-1" id="vlans-count">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="srv-tab" data-toggle="pill" href="#srv-pane"
                       role="tab" aria-controls="srv-pane" aria-selected="false">
                        <i class="fas fa-cogs mr-1"></i> Perfiles de servicio
                        <span class="badge badge-secondary ml-1" id="srv-count">0</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="line-tab" data-toggle="pill" href="#line-pane"
                       role="tab" aria-controls="line-pane" aria-selected="false">
                        <i class="fas fa-sliders-h mr-1"></i> Perfiles de línea
                        <span class="badge badge-secondary ml-1" id="line-count">0</span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content" id="oltConfigTabsContent">

                {{-- VLANs --}}
                <div class="tab-pane fade show active" id="vlans-pane" role="tabpanel" aria-labelledby="vlans-tab">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                        <p class="text-muted mb-2 mb-md-0">
                            <i class="fas fa-info-circle mr-1"></i>
                            VLANs disponibles en esta OLT para asignar a las ONTs.
                        </p>
                        <button class="btn btn-success" data-toggle="modal" data-target="#addVlanModal">
                            <i class="fas fa-plus mr-1"></i> Agregar VLAN
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0" id="vlans-table">
                            <thead class="thead-light">
                            <tr>
                                <th style="width: 140px;">VLAN ID</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th class="text-right" style="width: 110px;">Acciones</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                {{-- Perfiles de servicio --}}
                <div class="tab-pane fade" id="srv-pane" role="tabpanel" aria-labelledby="srv-tab">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                        <p class="text-muted mb-2 mb-md-0">
                            <i class="fas fa-info-circle mr-1"></i>
                            Perfiles de servicio (srv-profile) configurados en la OLT.
                        </p>
                        <button class="btn btn-success" data-toggle="modal" data-target="#addSrvProfileModal">
                            <i class="fas fa-plus mr-1"></i> Agregar perfil de servicio
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0" id="srvProfiles-table">
                            <thead class="thead-light">
                            <tr>
                                <th style="width: 140px;">Srv-profile ID</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th class="text-right" style="width: 110px;">Acciones</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                {{-- Perfiles de línea --}}
                <div class="tab-pane fade" id="line-pane" role="tabpanel" aria-labelledby="line-tab">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                        <p class="text-muted mb-2 mb-md-0">
                            <i class="fas fa-info-circle mr-1"></i>
                            Perfiles de línea (line-profile) configurados en la OLT.
                        </p>
                        <button class="btn btn-success" data-toggle="modal" data-target="#addLineProfileModal">
                            <i class="fas fa-plus mr-1"></i> Agregar perfil de línea
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0" id="lineProfiles-table">
                            <thead class="thead-light">
                            <tr>
                                <th style="width: 140px;">Line-profile ID</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th class="text-right" style="width: 110px;">Acciones</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- Confirmación de borrado.
         El listado refleja lo que hay configurado en la OLT: al
         eliminar aquí, en el equipo la VLAN o el perfil siguen
         existiendo. Conviene que quede claro antes de aceptar. --}}
    <div class="modal fade" id="confirmarBorradoModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle mr-1"></i> Confirmar eliminación
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">¿Desea eliminar <strong id="borradoNombre"></strong> del listado?</p>

                    <div class="alert alert-warning mb-0" id="borradoEnUso" style="display: none;">
                        <i class="fas fa-exclamation-circle mr-1"></i>
                        <span id="borradoEnUsoTexto"></span>
                    </div>

                    <p class="text-muted small mb-0 mt-2">
                        Solo se quita de GestISP. En la OLT la configuración no cambia.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <form method="POST" id="formBorrado" class="d-inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash mr-1"></i> Eliminar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Modales --}}
    @include('gestisp.olts.partials.config-modal', [
        'id'          => 'addVlanModal',
        'titulo'      => 'Agregar VLAN',
        'accion'      => route('olt.vlans.store'),
        'urlActualizar' => route('olt.vlans.update', '__ID__'),
        'campoId'     => 'id_vlan',
        'etiquetaId'  => 'ID de la VLAN',
        'tipoId'      => 'number',
        'ayudaId'     => 'Número entre 1 y 4094, tal como está creada en la OLT.',
        'maxNombre'   => 100,
        'bag'         => 'vlan',
        'olt'         => $olt,
    ])

    @include('gestisp.olts.partials.config-modal', [
        'id'          => 'addSrvProfileModal',
        'titulo'      => 'Agregar perfil de servicio',
        'accion'      => route('olt.srvprofiles.store'),
        'urlActualizar' => route('olt.srvprofiles.update', '__ID__'),
        'campoId'     => 'id_srv_profile',
        'etiquetaId'  => 'ID del srv-profile',
        'tipoId'      => 'text',
        'ayudaId'     => 'Identificador que tiene el perfil dentro de la OLT.',
        'maxNombre'   => 255,
        'bag'         => 'srvProfile',
        'olt'         => $olt,
    ])

    @include('gestisp.olts.partials.config-modal', [
        'id'          => 'addLineProfileModal',
        'titulo'      => 'Agregar perfil de línea',
        'accion'      => route('olt.lineprofiles.store'),
        'urlActualizar' => route('olt.lineprofiles.update', '__ID__'),
        'campoId'     => 'id_line_profile',
        'etiquetaId'  => 'ID del line-profile',
        'tipoId'      => 'text',
        'ayudaId'     => 'Identificador que tiene el perfil dentro de la OLT.',
        'maxNombre'   => 50,
        'bag'         => 'lineProfile',
        'olt'         => $olt,
    ])
@stop

@section('js')
    <script>
        {{-- Las tres pestañas cargan lo mismo con distinta fuente:
             una sola función evita repetir el mismo bloque tres veces. --}}
        document.addEventListener('DOMContentLoaded', () => {

            const cargarTabla = async ({ url, tabla, contador, campoId, vacio, modal }) => {
                const cuerpo = document.querySelector(`#${tabla} tbody`);
                const badge = document.querySelector(`#${contador}`);

                const fila = (contenido, clase = '') =>
                    `<tr><td colspan="4" class="text-center py-4 ${clase}">${contenido}</td></tr>`;

                cuerpo.innerHTML = fila(
                    '<i class="fas fa-spinner fa-spin mr-1"></i> Cargando...',
                    'text-muted'
                );

                try {
                    const respuesta = await fetch(url);

                    if (!respuesta.ok) {
                        throw new Error(`HTTP ${respuesta.status}`);
                    }

                    const registros = await respuesta.json();

                    badge.textContent = registros.length;

                    if (registros.length === 0) {
                        cuerpo.innerHTML = fila(
                            `<i class="fas fa-inbox fa-2x d-block mb-2 text-muted"></i>${vacio}`,
                            'text-muted'
                        );
                        return;
                    }

                    cuerpo.innerHTML = registros.map(registro => `
                        <tr>
                            <td>
                                <span class="badge badge-info">${escapar(registro[campoId])}</span>
                                ${registro.en_uso > 0
                                    ? `<span class="badge badge-light border ml-1" title="ONTs que la usan">
                                           <i class="fas fa-hdd mr-1"></i>${registro.en_uso}
                                       </span>`
                                    : ''}
                            </td>
                            <td>${escapar(registro.name)}</td>
                            <td class="text-muted">${escapar(registro.description ?? '')}</td>
                            <td class="text-right text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        title="Editar"
                                        data-editar
                                        data-modal="${modal}"
                                        data-url="${escapar(registro.update_url)}"
                                        data-id="${registro.id}"
                                        data-campo="${campoId}"
                                        data-valor="${escapar(registro[campoId])}"
                                        data-nombre="${escapar(registro.name)}"
                                        data-descripcion="${escapar(registro.description ?? '')}">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        title="Eliminar"
                                        data-eliminar
                                        data-url="${escapar(registro.destroy_url)}"
                                        data-nombre="${escapar(registro.name)}"
                                        data-en-uso="${registro.en_uso ?? 0}">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('');

                } catch (error) {
                    console.error(`Error al cargar ${tabla}:`, error);
                    badge.textContent = '!';
                    cuerpo.innerHTML = fila(
                        '<i class="fas fa-exclamation-triangle mr-1"></i> No se pudo cargar la información.',
                        'text-danger'
                    );
                }
            };

            // Los datos vienen de la OLT y se insertan como HTML:
            // se escapan para que una descripción con < o > no
            // rompa la tabla
            const escapar = (valor) => {
                const div = document.createElement('div');
                div.textContent = valor ?? '';
                return div.innerHTML;
            };

            cargarTabla({
                url: `{{ route('api.vlansolt', $olt->id) }}`,
                tabla: 'vlans-table',
                contador: 'vlans-count',
                campoId: 'id_vlan',
                vacio: 'No hay VLANs registradas para esta OLT.',
                modal: 'addVlanModal',
            });

            cargarTabla({
                url: `{{ route('api.srvProfile', $olt->id) }}`,
                tabla: 'srvProfiles-table',
                contador: 'srv-count',
                campoId: 'id_srv_profile',
                vacio: 'No hay perfiles de servicio registrados para esta OLT.',
                modal: 'addSrvProfileModal',
            });

            cargarTabla({
                url: `{{ route('api.lineProfile', $olt->id) }}`,
                tabla: 'lineProfiles-table',
                contador: 'line-count',
                campoId: 'id_line_profile',
                vacio: 'No hay perfiles de línea registrados para esta OLT.',
                modal: 'addLineProfileModal',
            });

            {{-- Las filas se generan después de cargar los datos, así
                 que los botones se escuchan a nivel de documento en
                 lugar de uno por uno. --}}
            document.addEventListener('click', (evento) => {
                const editar = evento.target.closest('[data-editar]');
                const eliminar = evento.target.closest('[data-eliminar]');

                if (editar) {
                    abrirEnModoEdicion(editar.dataset);
                }

                if (eliminar) {
                    prepararBorrado(eliminar.dataset);
                }
            });

            // El modal de crear se reutiliza para editar: cambia el
            // destino, el método y los valores
            const abrirEnModoEdicion = (datos) => {
                const modal = document.getElementById(datos.modal);
                const form = modal.querySelector('[data-form]');

                form.action = datos.url;
                form.querySelector('[data-metodo]').value = 'PUT';
                form.querySelector('[data-registro]').value = datos.id;
                modal.querySelector('[data-titulo]').textContent = form.dataset.tituloEditar;

                form.querySelector(`[name="${datos.campo}"]`).value = datos.valor;
                form.querySelector('[name="name"]').value = datos.nombre;
                form.querySelector('[name="description"]').value = datos.descripcion;

                $(modal).modal('show');
            };

            // Al abrir desde el botón "Agregar" se vuelve al modo
            // crear: si no, conservaría el destino de la última edición
            document.querySelectorAll('[data-target^="#add"]').forEach(boton => {
                boton.addEventListener('click', () => {
                    const modal = document.querySelector(boton.dataset.target);
                    const form = modal.querySelector('[data-form]');

                    form.action = form.dataset.crear;
                    form.querySelector('[data-metodo]').value = '';
                    form.querySelector('[data-registro]').value = '';
                    modal.querySelector('[data-titulo]').textContent = form.dataset.tituloCrear;

                    // Se vacían uno a uno: form.reset() devolvería los
                    // campos a lo que trae el HTML, que tras un error
                    // de validación son los valores anteriores
                    form.querySelectorAll('input:not([type=hidden]), textarea')
                        .forEach(campo => campo.value = '');
                });
            });

            const prepararBorrado = (datos) => {
                document.getElementById('formBorrado').action = datos.url;
                document.getElementById('borradoNombre').textContent = datos.nombre;

                const aviso = document.getElementById('borradoEnUso');
                const enUso = parseInt(datos.enUso ?? '0', 10);

                if (enUso > 0) {
                    document.getElementById('borradoEnUsoTexto').textContent =
                        `${enUso} ONT${enUso === 1 ? '' : 's'} de esta OLT ${enUso === 1 ? 'está usando' : 'están usando'} esta VLAN.`;
                    aviso.style.display = '';
                } else {
                    aviso.style.display = 'none';
                }

                $('#confirmarBorradoModal').modal('show');
            };
        });
    </script>
@stop
