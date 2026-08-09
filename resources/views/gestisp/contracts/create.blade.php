{{-- ============================================================
     Alta de contrato

     El formulario está partido en tres bloques que son los tres
     momentos reales del alta: dónde vive el cliente, qué se le vende y
     cómo se le conecta. Antes eran tres tarjetas idénticas sin jerarquía
     y con los campos obligatorios mezclados entre los opcionales, así
     que no se veía qué faltaba por llenar.

     La ubicación en el mapa es OPCIONAL a propósito: no se puede
     bloquear un alta porque nadie haya ido todavía a tomar el punto.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Crear contrato')
@section('plugins.Select2', true)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h1 class="mb-0"><i class="fas fa-file-signature mr-2"></i>Nuevo contrato</h1>
            <small class="text-muted">
                Cliente: <strong>{{ $client->name }} {{ $client->last_name }}</strong>
                @if($client->identity_number)
                    · {{ $client->identity_number }}
                @endif
            </small>
        </div>
        <a href="{{ route('clients.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')

    @if($errors->any())
        <div class="alert alert-danger">
            <h6 class="mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Revise estos datos:</h6>
            <ul class="mb-0 pl-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('contracts.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <input type="hidden" value="{{ $client->id }}" name="client_id" id="client_id">

        {{-- ============================================================
             1. Dónde vive el cliente
             ============================================================ --}}
        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-house-user mr-1"></i> Datos de la residencia
                </h3>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-6">
                        <label for="department">Departamento <span class="text-danger">*</span></label>
                        <select class="form-control" id="department" name="department" required>
                            <option value="">Seleccione un departamento</option>
                            @foreach($colombiaLocations as $department => $municipalities)
                                <option value="{{ $department }}" @selected(old('department') === $department)>
                                    {{ $department }}
                                </option>
                            @endforeach
                        </select>
                        @error('department')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="municipality">Ciudad / Municipio <span class="text-danger">*</span></label>
                        <select class="form-control" id="municipality" name="municipality" required disabled>
                            <option value="">Primero seleccione un departamento</option>
                        </select>
                        @error('municipality')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="neighborhood">Barrio / Vereda</label>
                        <input type="text" class="form-control" id="neighborhood" name="neighborhood"
                               placeholder="Ingrese el nombre del barrio" minlength="5" maxlength="255"
                               value="{{ old('neighborhood') }}">
                        @error('neighborhood')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="address">Dirección</label>
                        <input type="text" class="form-control" id="address" name="address"
                               placeholder="Ingrese la dirección" maxlength="255"
                               value="{{ old('address') }}">
                        @error('address')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="home_type">Tipo de vivienda</label>
                        <select name="home_type" id="home_type" class="form-control">
                            <option value="">Seleccionar tipo de vivienda</option>
                            <option value="Propia" @selected(old('home_type') === 'Propia')>Propia</option>
                            <option value="En Arriendo" @selected(old('home_type') === 'En Arriendo')>Arrendada</option>
                            <option value="Otro" @selected(old('home_type') === 'Otro')>Otro</option>
                        </select>
                        @error('home_type')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="social_stratum">Estrato social</label>
                        <select name="social_stratum" id="social_stratum" class="form-control">
                            <option value="">Seleccionar estrato</option>
                            @foreach(range(1, 6) as $stratum)
                                <option value="{{ $stratum }}" @selected(old('social_stratum') == $stratum)>
                                    {{ $stratum }}
                                </option>
                            @endforeach
                        </select>
                        @error('social_stratum')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>
                </div>

                {{-- ---------- Ubicación en el mapa ---------- ---------------
                     Opcional, pero es lo que después permite sugerir la caja
                     NAP más cercana y comprobar que la instalación se cierre
                     en el sitio del cliente. --}}
                <hr>
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <h6 class="mb-0">
                        <i class="fas fa-map-pin text-danger mr-1"></i> Ubicación de la vivienda
                        <span class="badge badge-light border ml-1">Opcional</span>
                    </h6>
                    <small class="text-muted">Se puede agregar después desde la ficha del contrato.</small>
                </div>

                @php
                    // Los parámetros se arman aquí y no dentro de la directiva
                    // include: Blade corta su argumento en el primer paréntesis
                    // que cree de cierre sin contar los corchetes, y una
                    // expresión con arrays anidados compila partida.
                    $parametrosSelector = [
                        'mapId' => 'mapaContratoNuevo',
                        'latitude' => old('latitude'),
                        'longitude' => old('longitude'),
                        'height' => '340px',
                        'allowClear' => true,
                        'help' => 'Busque la dirección, haga clic sobre la puerta de la vivienda o use «Estoy aquí» si está en el sitio.',
                    ];
                @endphp

                @include('gestisp.partials.location-picker', $parametrosSelector)
            </div>
        </div>

        {{-- ============================================================
             2. Qué se le vende
             ============================================================ --}}
        <div class="card card-outline card-info">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-tags mr-1"></i> Datos del servicio</h3>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-6">
                        <label for="plan_id">Plan de servicio</label>
                        <select name="plan_id" id="plan_id" class="form-control">
                            <option value="">Seleccionar plan</option>
                            @foreach($plans as $plan)
                                <option value="{{ $plan->id }}" @selected(old('plan_id') == $plan->id)>
                                    {{ $plan->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('plan_id')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="permanence_clause">Cláusula de permanencia</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="permanence_clause" name="permanence_clause"
                                   placeholder="0" min="0" value="{{ old('permanence_clause') }}">
                            <div class="input-group-append">
                                <span class="input-group-text">meses</span>
                            </div>
                        </div>
                        @error('permanence_clause')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             3. Cómo se le conecta

             Todo este bloque es opcional en el alta: la mayoría de las
             veces se llena cuando el técnico instala, desde la ficha del
             contrato o al procesar la orden.
             ============================================================ --}}
        <div class="card card-outline card-secondary collapsed-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-cogs mr-1"></i> Datos técnicos</h3>
                <div class="card-tools">
                    <span class="badge badge-light border mr-2">Se pueden llenar al instalar</span>
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-6">
                        <label for="nap_port">Puerto NAP</label>
                        <input type="text" class="form-control" id="nap_port" name="nap_port"
                               placeholder="Ej.: NAP012 / P4" value="{{ old('nap_port') }}">
                        <small class="form-text text-muted">
                            La caja y el puerto reales se asignan desde la ficha del contrato,
                            que sí controla la ocupación.
                        </small>
                        @error('nap_port')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="cpe_sn">Serial del CPE</label>
                        <input type="text" class="form-control" id="cpe_sn" name="cpe_sn"
                               placeholder="Serial del equipo del cliente" maxlength="20" value="{{ old('cpe_sn') }}">
                        @error('cpe_sn')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="user_pppoe">Usuario PPPoE</label>
                        <input type="text" class="form-control" id="user_pppoe" name="user_pppoe"
                               placeholder="Usuario de la sesión" value="{{ old('user_pppoe') }}">
                        @error('user_pppoe')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="password_pppoe">Contraseña PPPoE</label>
                        <input type="text" class="form-control" id="password_pppoe" name="password_pppoe"
                               placeholder="Contraseña de la sesión" value="{{ old('password_pppoe') }}">
                        @error('password_pppoe')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="ssid_wifi">SSID WiFi</label>
                        <input type="text" class="form-control" id="ssid_wifi" name="ssid_wifi"
                               placeholder="Nombre de la red inalámbrica" value="{{ old('ssid_wifi') }}">
                        @error('ssid_wifi')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-6">
                        <label for="password_wifi">Contraseña WiFi</label>
                        <input type="text" class="form-control" id="password_wifi" name="password_wifi"
                               placeholder="Clave de la red inalámbrica" value="{{ old('password_wifi') }}">
                        @error('password_wifi')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-md-12">
                        <label for="comment">Comentario</label>
                        <textarea class="form-control" id="comment" name="comment" rows="2"
                                  placeholder="Novedades del sitio, referencias para llegar, acuerdos con el cliente…">{{ old('comment') }}</textarea>
                        @error('comment')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap">
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Al crear el contrato se genera automáticamente su orden de instalación.
                </small>
                <div>
                    <a href="{{ route('clients.index') }}" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Crear contrato
                    </button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('css')
    @include('gestisp.partials.leaflet-styles')
@endsection

@section('js')
    @include('gestisp.partials.leaflet-script')

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

            /* --------------------------------------------------------
               Sugerir la búsqueda de dirección con lo ya escrito

               Ahorra volver a teclear la dirección dentro del mapa, que
               es el motivo por el que casi nadie usaba el buscador.
               -------------------------------------------------------- */
            function proposeSearchText() {
                const search = document.getElementById('mapaContratoNuevoBuscar');

                if (!search || search.value.trim() !== '') {
                    return;
                }

                const parts = [
                    $('#address').val(),
                    $('#neighborhood').val(),
                    $municipalitySelect.val(),
                    $departmentSelect.val(),
                    'Colombia',
                ].filter(function (part) { return part; });

                if (parts.length > 1) {
                    search.value = parts.join(', ');
                }
            }

            $('#address, #neighborhood').on('blur', proposeSearchText);
            $municipalitySelect.on('change', proposeSearchText);
        });
    </script>
@endsection
