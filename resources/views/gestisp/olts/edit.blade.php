@extends('adminlte::page')
@section('title', 'OLTs')
@section('content_header')
    <div class="card p-3">
        <h2>EDITAR INFORMACIÓN DE LA OLT {{ $olt->name }}</h2>
    </div>
@endsection
@section('content')
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('olts.update', $olt) }}" enctype="multipart/form-data">
                @csrf
                <div class="row">

                    <div class="form-group col-12 col-md-6">
                        <label for="name">Nombre de la OLT</label>
                        <input type="text" class="form-control" id="name" name="name"
                               placeholder="Ingrese un nombre para la OLT" minlength="5" maxlength="255"
                               value="{{ $olt->name }}">
                        @error('name')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="ip_address">Dirección IP</label>
                        <input type="text" class="form-control" id="ip_address" name="ip_address"
                               placeholder="Ingrese la dirección IP de la OLT" minlength="5" maxlength="255"
                               value="{{ $olt->ip_address }}">
                        @error('ip_address')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="ssh_port">Puerto SSH</label>
                        <input type="text" class="form-control" id="ssh_port" name="ssh_port"
                               placeholder="Ingrese el puerto SSH de la OLT"
                               value="{{ $olt->ssh_port }}">
                        @error('ssh_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="telnet_port">Puerto Telnet</label>
                        <input type="text" class="form-control" id="telnet_port" name="telnet_port"
                               placeholder="Ingrese el puerto Telnet de la OLT"
                               value="{{ $olt->telnet_port  }}">
                        @error('telnet_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="snmp_port">Puerto SNMP</label>
                        <input type="text" class="form-control" id="snmp_port" name="snmp_port"
                               placeholder="Ingrese el puerto SNMP de la OLT"
                               value="{{ $olt->snmp_port  }}">
                        @error('snmp_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="read_snmp_comunity">Comunidad SNMP Lectura</label>
                        <input type="text" class="form-control" id="read_snmp_comunity" name="read_snmp_comunity"
                               placeholder="Ingrese la comunidad SNMP de lectura"
                               value="{{ $olt->ssh_port  }}">
                        @error('read_snmp_comunity')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="write_snmp_comunity">Comunidad SNMP Escritura</label>
                        <input type="text" class="form-control" id="write_snmp_comunity" name="write_snmp_comunity"
                               placeholder="Ingrese la comunidad SNMP de escritura"
                               value="{{ old('write_snmp_comunity') }}">
                        @error('write_snmp_comunity')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="username">Usuario de acceso</label>
                        <input type="text" class="form-control" id="username" name="username"
                               placeholder="Ingrese el nombre de usuario"
                               value="{{ $olt->username }}">
                        @error('username')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="password">Contraseña de acceso</label>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Ingrese la contraseña de acceso">
                        @error('password')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="brand">Marca</label>
                        <input type="text" class="form-control" id="brand" name="brand" disabled
                               placeholder="Ingrese la marca de la OLT"
                               value="{{ $olt->brand }}">
                        @error('brand')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="model">Modelo</label>
                        <input type="text" class="form-control" id="model" name="model" disabled
                               placeholder="Ingrese el modelo de la OLT"
                               value="{{ $olt->model }}">
                        @error('model')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                </div>

                <div class="col-12 text-center">
                    <input type="submit" value="Actualizar OLT" class="btn btn-primary col-md-3">
                </div>

            </form>
        </div>

        <div class="card">
            <ul class="nav nav-tabs" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#home-tab-pane" type="button" role="tab" aria-controls="home-tab-pane" aria-selected="true">Vlan</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-tab-pane" type="button" role="tab" aria-controls="profile-tab-pane" aria-selected="false">SrvProfile</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact-tab-pane" type="button" role="tab" aria-controls="contact-tab-pane" aria-selected="false">LineProfile</button>
                </li>
            </ul>
            <div class="tab-content" id="myTabContent">
                <div class="tab-pane fade show active" id="home-tab-pane" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
                    <button class="btn btn-success my-2" data-bs-toggle="modal" data-bs-target="#addVlanModal">
                        Agregar VLAN
                    </button>
                    <table class="table table-bordered" id="vlans-table">
                        <thead>
                        <tr>
                            <th>VLAN ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                        </tr>
                        </thead>
                        <tbody>
                        <!-- Aquí se insertarán las VLAN dinámicamente -->
                        </tbody>
                    </table>
                    <!-- Modal para agregar VLAN -->
                    <div class="modal fade" id="addVlanModal" tabindex="-1" aria-labelledby="addVlanModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title" id="addVlanModalLabel">Agregar VLAN</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                </div>

                                <form id="addVlanForm" action="{{ route('olt.vlans.store') }}" method="post">
                                    <div class="modal-body">
                                        @csrf
                                        <input type="hidden" id="olt_id" name="olt_id" value="{{ $olt->id }}">

                                        <div class="mb-3">
                                            <label for="id_vlan" class="form-label">ID VLAN</label>
                                            <input type="number" class="form-control" id="id_vlan" name="id_vlan" required>
                                        </div>

                                        <div class="mb-3">
                                            <label for="name" class="form-label">Nombre</label>
                                            <input type="text" class="form-control" id="name_vlan" name="name" required maxlength="100">
                                        </div>

                                        <div class="mb-3">
                                            <label for="description" class="form-label">Descripción</label>
                                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                        </div>
                                    </div>

                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">Guardar VLAN</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="profile-tab-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
                    <button>Agregar SrvProfile</button>
                    <table class="table table-bordered" id="srvProfiles-table">
                        <thead>
                        <tr>
                            <th>Srv-profile ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                        </tr>
                        </thead>
                        <tbody>
                        <!-- Aquí se insertarán los line profiles dinámicamente -->
                        </tbody>
                    </table>
                </div>
                <div class="tab-pane fade" id="contact-tab-pane" role="tabpanel" aria-labelledby="contact-tab" tabindex="0">
                    <button>Agregar LineProfile</button>
                    <table class="table table-bordered" id="lineProfiles-table">
                        <thead>
                        <tr>
                            <th>Line-profile ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                        </tr>
                        </thead>
                        <tbody>
                        <!-- Aquí se insertarán los line profiles dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" integrity="sha384-G/EV+4j2dNv+tEPo3++6LCgdCROaejBqfUeNjuKAiuXbjrxilcCdDz6ZAVfHWe1Y" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", async () => {
            const oltId = "{{ $olt->id }}"; // ID seguro como string
            const tableBody = document.querySelector("#vlans-table tbody");

            try {
                const response = await fetch(`{{ route('api.vlansolt', $olt->id) }}`); // URL entre comillas
                const vlans = await response.json();

                if (vlans.length === 0) {
                    tableBody.innerHTML = `<tr><td colspan="4" class="text-center">No hay VLANs registradas para esta OLT.</td></tr>`;
                    return;
                }

                vlans.forEach(vlan => {
                    const row = `
                    <tr>
                        <td>${vlan.id_vlan}</td>
                        <td>${vlan.name}</td>
                        <td>${vlan.description ?? ''}</td>
                    </tr>
                `;
                    tableBody.insertAdjacentHTML('beforeend', row);
                });

            } catch (error) {
                console.error("Error al cargar VLANs:", error);
                tableBody.innerHTML = `<tr><td colspan="4" class="text-center text-danger">Error al cargar VLANs.</td></tr>`;
            }
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", async () => {
            const oltId = "{{ $olt->id }}"; // ID seguro como string
            const tableBody = document.querySelector("#lineProfiles-table tbody");

            try {
                const response = await fetch(`{{ route('api.lineProfile', $olt->id) }}`); // URL entre comillas
                const lineProfiles = await response.json();

                if (lineProfiles.length === 0) {
                    tableBody.innerHTML = `<tr><td colspan="4" class="text-center">No hay Lineprofiles registradas para esta OLT.</td></tr>`;
                    return;
                }

                lineProfiles.forEach(lineProfile => {
                    const row = `
                    <tr>
                        <td>${lineProfile.id_line_profile}</td>
                        <td>${lineProfile.name}</td>
                        <td>${lineProfile.description ?? ''}</td>
                    </tr>
                `;
                    tableBody.insertAdjacentHTML('beforeend', row);
                });

            } catch (error) {
                console.error("Error al cargar lineProfiles:", error);
                tableBody.innerHTML = `<tr><td colspan="4" class="text-center text-danger">Error al cargar lineprofiles.</td></tr>`;
            }
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", async () => {
            const oltId = "{{ $olt->id }}"; // ID seguro como string
            const tableBody = document.querySelector("#srvProfiles-table tbody");

            try {
                const response = await fetch(`{{ route('api.srvProfile', $olt->id) }}`); // URL entre comillas
                const srvProfiles = await response.json();

                if (srvProfiles.length === 0) {
                    tableBody.innerHTML = `<tr><td colspan="4" class="text-center">No hay srvprofiles registradas para esta OLT.</td></tr>`;
                    return;
                }

                srvProfiles.forEach(srvProfile => {
                    const row = `
                    <tr>
                        <td>${srvProfile.id_srv_profile}</td>
                        <td>${srvProfile.name}</td>
                        <td>${srvProfile.description ?? ''}</td>
                    </tr>
                `;
                    tableBody.insertAdjacentHTML('beforeend', row);
                });

            } catch (error) {
                console.error("Error al cargar srvProfiles:", error);
                tableBody.innerHTML = `<tr><td colspan="4" class="text-center text-danger">Error al cargar srvprofiles.</td></tr>`;
            }
        });
    </script>


@endsection

