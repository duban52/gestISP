@extends('adminlte::page')

@section('title', 'Crear contrato')
@section('plugins.Select2', true)
@section('content_header')
    <h2>Asignación de contrato para el cliente: {{ $client->name }} {{ $client->last_name }}</h2>
@endsection
@section('content')

            <form action="{{ route('contracts.store') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <!-- Campos ocultos para branch_id y client_id -->
                <input type="hidden" value="{{ $client->id }}" name="client_id" id="client_id">

                <div class="card">
                    <div class="card-head text-center mt-3">
                        <h4 class="text-info">DATOS DE LA RESIDENCIA</h4>
                    </div>

                    <div class="card-body">
                        <div class="row">
                            <!-- Barrio -->
                            <div class="form-group col-md-6">
                                <label for="neighborhood">Barrio</label>
                                <input type="text" class="form-control" id="neighborhood" name="neighborhood"
                                       placeholder="Ingrese el nombre del barrio" minlength="5" maxlength="255"
                                       value="{{ old('neighborhood') }}">
                                @error('neighborhood')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label for="department">Departamento <span class="text-danger">*</span></label>
                                <select class="form-control" id="department" name="department" required>
                                    <option value="">Seleccione un departamento</option>
                                    @foreach($colombiaLocations as $department => $municipalities)
                                        <option value="{{ $department }}" {{ old('department') === $department ? 'selected' : '' }}>
                                            {{ $department }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('department')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <div class="form-group col-md-6">
                                <label for="municipality">Ciudad / Municipio <span class="text-danger">*</span></label>
                                <select class="form-control" id="municipality" name="municipality" required disabled>
                                    <option value="">Primero seleccione un departamento</option>
                                </select>
                                @error('municipality')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <!-- Dirección -->
                            <div class="form-group col-md-6">
                                <label for="address">Dirección</label>
                                <input type="text" class="form-control" id="address" name="address"
                                       placeholder="Ingrese la dirección" maxlength="255"
                                       value="{{ old('address') }}">
                                @error('address')
                                <span class="text-danger">
                                     <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>
                            <!-- Tipo de vivienda -->
                            <div class="form-group col-md-6">
                                <label for="home_type">Tipo de vivienda</label>
                                <select name="home_type" id="home_type" class="form-control">
                                    <option value="">Seleccionar tipo de vivienda</option>
                                    <option value="Propia">Propia</option>
                                    <option value="En Arriendo">Arrendada</option>
                                    <option value="Otro">Otro</option>

                                </select>
                                @error('type_home')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                 </span>
                                @enderror
                            </div>

                            <!-- Estrato social -->
                            <div class="form-group col-md-6">
                                <label for="social_stratum">Estrato Social</label>
                                <select name="social_stratum" id="social_stratum" class="form-control">
                                    <option value="">Seleccionar Estrato</option>
                                    @foreach(range(1, 6) as $stratum)
                                        <option value="{{ $stratum }}" {{ old('social_stratum') == $stratum ? 'selected' : '' }}>
                                            {{ $stratum }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('social_stratum')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>


                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head text-center mt-3">
                        <h4 class="text-info">DATOS DEL SERVICIO</h4>
                    </div>

                    <div class="card-body">
                        <div class="row">
                            <!-- Plan de Servicio -->
                            <div class="form-group col-md-6">
                                <label for="plan_id">Plan de Servicio</label>
                                <select name="plan_id" id="plan_id" class="form-control">
                                    <option value="">Seleccionar Plan</option>
                                    @foreach($plans as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Cláusula de permanencia -->
                            <div class="form-group col-md-6">
                                <label for="permanence_clause">Cláusula de Permanencia</label>
                                <input type="number" class="form-control" id="permanence_clause" name="permanence_clause"
                                       placeholder="Ingrese la cláusula en meses" value="{{ old('permanence_clause') }}">
                                @error('permanence_clause')
                                <span class="text-danger">
                                     <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>
                        </div>
                        </div>
                    </div>


                <div class="card">
                    <div class="card-head text-center mt-3">
                        <h4 class="text-info">DATOS TÉCNICOS</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Puerto NAP -->
                            <div class="form-group col-md-6">
                                <label for="nap_port">Puerto NAP</label>
                                <input type="text" class="form-control" id="nap_port" name="nap_port"
                                       placeholder="Ingrese el puerto NAP" value="{{ old('nap_port') }}">
                                @error('nap_port')
                                <span class="text-danger">
                                     <span>* {{ $message }}</span>
                                 </span>
                                @enderror
                            </div>

                            <!-- Serial CPE -->
                            <div class="form-group col-md-6">
                                <label for="cpe_sn">Serial CPE</label>
                                <input type="text" class="form-control" id="cpe_sn" name="cpe_sn"
                                       placeholder="Ingrese el serial CPE" maxlength="20" value="{{ old('cpe_sn') }}">
                                @error('cpe_sn')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <!-- Usuario PPPoE -->
                            <div class="form-group col-md-6">
                                <label for="user_pppoe">Usuario PPPoE</label>
                                <input type="text" class="form-control" id="user_pppoe" name="user_pppoe"
                                       placeholder="Ingrese el usuario PPPoE" value="{{ old('user_pppoe') }}">
                                @error('user_pppoe')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <!-- Contraseña PPPoE -->
                            <div class="form-group col-md-6">
                                <label for="password_pppoe">Contraseña PPPoE</label>
                                <input type="password" class="form-control" id="password_pppoe" name="password_pppoe"
                                       placeholder="Ingrese la contraseña PPPoE" value="{{ old('password_pppoe') }}">
                                @error('password_pppoe')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <!-- SSID WiFi -->
                            <div class="form-group col-md-6">
                                <label for="ssid_wifi">SSID WiFi</label>
                                <input type="text" class="form-control" id="ssid_wifi" name="ssid_wifi"
                                       placeholder="Ingrese el SSID WiFi" value="{{ old('ssid_wifi') }}">
                                @error('ssid_wifi')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <!-- Contraseña WiFi -->
                            <div class="form-group col-md-6">
                                <label for="password_wifi">Contraseña WiFi</label>
                                <input type="text" class="form-control" id="password_wifi" name="password_wifi"
                                       placeholder="Ingrese la contraseña WiFi" value="{{ old('password_wifi') }}">
                                @error('password_wifi')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>

                            <!-- Comentario -->
                            <div class="form-group col-md-12">
                                <label for="comment">Comentario</label>
                                <textarea class="form-control" id="comment" name="comment"
                                          placeholder="Ingrese un comentario">{{ old('comment') }}</textarea>
                                @error('comment')
                                <span class="text-danger">
                                    <span>* {{ $message }}</span>
                                </span>
                                @enderror
                            </div>
                        </div>

                        <div class="col-12 text-center">
                            <!-- Botón para enviar -->
                            <button type="submit" class="btn btn-primary">Crear Contrato</button>
                        </div>
                    </div>
                </div>
            </div>


            </form>



@endsection

@section('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const municipalitiesByDepartment = @json($colombiaLocations, JSON_UNESCAPED_UNICODE);
            const $departmentSelect = $('#department');
            const $municipalitySelect = $('#municipality');
            const previousMunicipality = @json(old('municipality'));

            // Select2 mantiene el formulario compacto y agrega búsqueda
            // por texto, útil especialmente para los 1.104 municipios.
            $departmentSelect.select2({
                width: '100%',
                placeholder: 'Busque o seleccione un departamento',
                allowClear: true
            });

            $municipalitySelect.select2({
                width: '100%',
                placeholder: 'Primero seleccione un departamento',
                allowClear: true
            });

            function loadMunicipalities(selectedMunicipality = '') {
                const department = $departmentSelect.val();
                const municipalities = municipalitiesByDepartment[department] || [];

                $municipalitySelect.empty();

                if (!department) {
                    $municipalitySelect
                        .prop('disabled', true)
                        .append(new Option('Primero seleccione un departamento', ''))
                        .trigger('change');
                    return;
                }

                $municipalitySelect
                    .prop('disabled', false)
                    .append(new Option('Busque o seleccione una ciudad o municipio', ''));

                municipalities.forEach(function (municipality) {
                    $municipalitySelect.append(new Option(
                        municipality,
                        municipality,
                        false,
                        municipality === selectedMunicipality
                    ));
                });

                $municipalitySelect.trigger('change');
            }

            $departmentSelect.on('change', function () {
                loadMunicipalities();
            });

            loadMunicipalities(previousMunicipality);
        });
    </script>
@endsection
