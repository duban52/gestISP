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
             En que sucursal queda el servicio

             El CLIENTE es de la empresa, pero el CONTRATO es de una
             sucursal: ahi es donde se presta el servicio, y de ahi salen
             su prefijo, su consecutivo y sus reglas de facturacion.

             Solo se pregunta cuando hay mas de una sucursal alcanzable.
             Con una sola se asume, y este bloque no aparece.
             ============================================================ --}}
        @if($hayQueElegirSucursal)
            <div class="card card-outline card-warning">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-store mr-1"></i> Sucursal del servicio
                    </h3>
                </div>
                <div class="card-body">
                    <div class="form-group mb-0">
                        <label for="branch_id">Sucursal <span class="text-danger">*</span></label>
                        <select name="branch_id" id="branch_id"
                                class="form-control @error('branch_id') is-invalid @enderror" required>
                            <option value="">Seleccione la sucursal</option>
                            @foreach($sucursales as $sucursal)
                                <option value="{{ $sucursal->id }}" @selected(old('branch_id') == $sucursal->id)>
                                    {{ $sucursal->name }}
                                    @if($sucursal->contract_prefix)
                                        — contratos {{ $sucursal->contract_prefix }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text text-muted">
                            Determina el consecutivo del contrato y los planes disponibles.
                        </small>
                    </div>
                </div>
            </div>
        @endif

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
                        <label for="plan_id">
                            Plan de servicio <span class="text-danger">*</span>
                        </label>

                        {{-- Sin ningun plan dado de alta no hay contrato
                             posible: del plan salen el precio y los servicios
                             que se facturan. En vez de dejar un desplegable
                             vacio, se dice que falta y por donde se arregla. --}}
                        @if($plans->isEmpty())
                            <div class="alert alert-warning mb-0">
                                <h6 class="alert-heading">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    Todavia no hay planes
                                </h6>
                                <p class="mb-2 small">
                                    Un contrato necesita un plan: de el salen el precio, los
                                    servicios incluidos y el IVA de cada uno. Cree al menos uno
                                    antes de dar de alta contratos.
                                </p>
                                @can('plans.create')
                                    <a href="{{ route('plans.create') }}" class="btn btn-sm btn-warning">
                                        <i class="fas fa-plus mr-1"></i> Crear un plan
                                    </a>
                                @else
                                    <span class="small text-muted">
                                        Pidale a un administrador que cree los planes de esta sucursal.
                                    </span>
                                @endcan
                            </div>
                        @else
                            <select name="plan_id" id="plan_id" class="form-control" required>
                                <option value="">Seleccionar plan</option>
                                @foreach($plans as $plan)
                                    {{-- data-branch deja que el JS de abajo esconda
                                         los planes que no son de la sucursal
                                         elegida: un plan es de UNA sucursal. --}}
                                    <option value="{{ $plan->id }}" data-branch="{{ $plan->branch_id }}"
                                            @selected(old('plan_id') == $plan->id)>
                                        {{ $plan->name }}
                                    </option>
                                @endforeach
                            </select>

                            {{-- Lo llena el JS cuando la sucursal elegida no
                                 tiene ningun plan propio. --}}
                            <div id="avisoSinPlanes" class="alert alert-warning mt-2 d-none">
                                <span class="small"></span>
                                @can('plans.create')
                                    <a href="{{ route('plans.create') }}" class="alert-link small">
                                        Crear un plan
                                    </a>
                                @endcan
                            </div>
                        @endif

                        @error('plan_id')
                            <span class="text-danger small">* {{ $message }}</span>
                        @enderror
                    </div>

                    {{-- ============================================================
                         GRUPO DE CONTRATO

                         Decide como se factura este contrato: con
                         factura electronica o con documento interno.

                         Viene marcado el predeterminado de la empresa. Si la
                         empresa solo tiene ese, el campo se deja igualmente
                         a la vista: no es un tramite, es la clasificacion
                         fiscal del contrato y conviene que quien lo da de
                         alta la vea.
                         ============================================================ --}}
                    <div class="form-group col-md-6">
                        <label for="affinity_group_id">Grupo de afinidad</label>

                        @if($gruposAfinidad->isEmpty())
                            <div class="alert alert-warning mb-0">
                                <h6 class="alert-heading">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    Esta empresa no tiene grupos activos
                                </h6>
                                <p class="mb-2 small">
                                    El contrato se creara sin clasificar. Podra encontrarlo despues
                                    con el filtro <em>«Sin grupo asignado»</em> del listado.
                                </p>
                                @can('affinity_groups.create')
                                    <a href="{{ route('affinity_groups.index') }}"
                                       class="btn btn-sm btn-warning" target="_blank">
                                        <i class="fas fa-layer-group mr-1"></i> Administrar grupos
                                    </a>
                                @endcan
                            </div>
                        @else
                            <select name="affinity_group_id" id="affinity_group_id"
                                    class="form-control @error('affinity_group_id') is-invalid @enderror">
                                @foreach($gruposAfinidad as $grupoAfinidad)
                                    <option value="{{ $grupoAfinidad->id }}"
                                            data-electronica="{{ $grupoAfinidad->requires_electronic_invoicing ? '1' : '0' }}"
                                            @selected(old('affinity_group_id', $grupoPorDefecto?->id) == $grupoAfinidad->id)>
                                        {{ $grupoAfinidad->etiqueta() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('affinity_group_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @endif
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

    {{-- ============================================================
         Los planes son de UNA sucursal

         Al elegir la sucursal se dejan solo sus planes. Sin esto se
         podria asignar a un contrato de Bogota un plan de Medellin, y
         el precio saldria del sitio equivocado.

         Es ayuda de la pantalla, no la barrera: el servidor comprueba
         igualmente que el plan sea de la sucursal del contrato.
         ============================================================ --}}
    <script>
        (function () {
            const sucursal = document.getElementById('branch_id');

            // Solo existe cuando hay mas de una sucursal que elegir
            if (!sucursal) {
                return;
            }

            const planes = document.getElementById('plan_id');

            // No hay desplegable cuando no existe ningun plan: la vista
            // pinta el aviso en su lugar y aqui no hay nada que filtrar.
            if (!planes) {
                return;
            }

            const opciones = Array.from(planes.options).slice(1);
            const aviso = document.getElementById('avisoSinPlanes');

            function filtrar() {
                const elegida = sucursal.value;
                let visibles = 0;

                opciones.forEach(function (opcion) {
                    const suya = !elegida || opcion.dataset.branch === elegida;

                    opcion.hidden = !suya;
                    opcion.disabled = !suya;

                    if (suya) {
                        visibles++;
                    }

                    // Si el plan que estaba puesto ya no es de esta
                    // sede, se suelta: dejarlo seleccionado y oculto es
                    // la forma mas facil de mandar un dato invalido.
                    if (!suya && planes.value === opcion.value) {
                        planes.value = '';
                    }
                });

                // Hay planes, pero ninguno de la sucursal elegida. Sin
                // este aviso el desplegable se queda vacio sin explicar
                // por que, y parece que la pantalla esta rota.
                if (aviso) {
                    const faltan = elegida !== '' && visibles === 0;

                    aviso.classList.toggle('d-none', !faltan);
                    planes.classList.toggle('d-none', faltan);

                    if (faltan) {
                        const nombre = sucursal.options[sucursal.selectedIndex].text.trim();

                        aviso.querySelector('span').textContent =
                            'La sucursal "' + nombre + '" no tiene ningun plan. ';
                    }
                }
            }

            sucursal.addEventListener('change', filtrar);
            filtrar();
        })();
    </script>

@endsection
