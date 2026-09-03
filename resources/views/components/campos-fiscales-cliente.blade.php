{{--
    Datos fiscales del cliente.

    QUÉ SON Y POR QUÉ ESTÁN APARTE
    ------------------------------
    Es lo que el XML de una factura electrónica exige del adquiriente y
    que hasta ahora no se pedía en ningún sitio. Van en su propia
    tarjeta, plegada, porque **hoy no hacen falta para nada**: se
    rellenan cuando el cliente vaya a recibir factura electrónica, y
    mezclarlos con los datos de siempre haría pensar que son
    obligatorios.

    El informe de completitud fiscal dice qué clientes los tienen
    incompletos, para poder ir rellenándolos antes de que hagan falta
    de verdad.

    LOS CÓDIGOS SALEN DEL CATÁLOGO
    ------------------------------
    No de listas escritas a mano. Cambian por resolución, y una lista
    en el código exige un despliegue para actualizarla. Además, la
    lista que había escrita a mano tenía un fallo: una opción decía
    «Pasaporte» y guardaba «Persona Jurídica».

    Parámetros: $cliente (null al crear)
--}}
@props(['cliente' => null])

@php
    $catalogo = \App\Models\FiscalCatalog::class;

    $tiposDocumento = $catalogo::opciones($catalogo::TIPO_DOCUMENTO);
    $organizaciones = $catalogo::opciones($catalogo::TIPO_ORGANIZACION);
    $departamentos = $catalogo::opciones($catalogo::DEPARTAMENTO);
    $responsabilidades = $catalogo::opciones($catalogo::RESPONSABILIDAD);

    $deptoActual = old('department_dane_code', $cliente?->department_dane_code);
    $municipios = $deptoActual
        ? $catalogo::opciones($catalogo::MUNICIPIO, $deptoActual)
        : collect();

    $responsabilidadesActuales = old(
        'tax_responsibilities',
        $cliente?->taxResponsibilities->pluck('responsibility_code')->all() ?? [],
    );
@endphp

<div class="card card-outline card-secondary collapsed-card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-landmark mr-1"></i> Datos fiscales
        </h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-plus"></i>
            </button>
        </div>
    </div>

    <div class="card-body">
        <div class="alert alert-secondary">
            <i class="fas fa-info-circle mr-1"></i>
            Solo hacen falta si este cliente va a recibir <strong>factura electrónica</strong>.
            Puede dejarlos vacíos y completarlos después.
        </div>

        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="verification_digit">Dígito de verificación</label>
                <input type="text" name="verification_digit" id="verification_digit" maxlength="1"
                       class="form-control @error('verification_digit') is-invalid @enderror"
                       value="{{ old('verification_digit', $cliente?->verification_digit) }}">
                @error('verification_digit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">El que sigue al NIT. Solo para personas jurídicas.</small>
            </div>

            <div class="form-group col-md-8">
                <label for="organization_type_code">Tipo de organización</label>
                <select name="organization_type_code" id="organization_type_code" class="form-control">
                    <option value="">Sin especificar</option>
                    @foreach($organizaciones as $codigo => $nombre)
                        <option value="{{ $codigo }}"
                                @selected(old('organization_type_code', $cliente?->organization_type_code) === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="fiscal_address">Dirección fiscal</label>
            <input type="text" name="fiscal_address" id="fiscal_address" maxlength="255"
                   class="form-control @error('fiscal_address') is-invalid @enderror"
                   value="{{ old('fiscal_address', $cliente?->fiscal_address) }}">
            @error('fiscal_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small class="form-text text-muted">
                Dónde recibe correspondencia el contribuyente. <strong>No es la del contrato</strong>,
                que es la dirección donde está instalado el servicio.
            </small>
        </div>

        <div class="form-row">
            <div class="form-group col-md-5">
                <label for="department_dane_code">Departamento</label>
                <select name="department_dane_code" id="department_dane_code" class="form-control">
                    <option value="">Sin especificar</option>
                    @foreach($departamentos as $codigo => $nombre)
                        <option value="{{ $codigo }}" @selected($deptoActual === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group col-md-5">
                <label for="municipality_dane_code">Municipio</label>
                <select name="municipality_dane_code" id="municipality_dane_code" class="form-control">
                    <option value="">Seleccione primero el departamento</option>
                    @foreach($municipios as $codigo => $nombre)
                        <option value="{{ $codigo }}"
                                @selected(old('municipality_dane_code', $cliente?->municipality_dane_code) === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">Código DANE, el que pide el XML.</small>
            </div>

            <div class="form-group col-md-2">
                <label for="postal_code">Código postal</label>
                <input type="text" name="postal_code" id="postal_code" maxlength="10"
                       class="form-control"
                       value="{{ old('postal_code', $cliente?->postal_code) }}">
            </div>
        </div>

        <div class="form-group mb-0">
            <label>Responsabilidades fiscales</label>
            <div class="row">
                @foreach($responsabilidades as $codigo => $nombre)
                    <div class="col-md-6">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input"
                                   id="resp{{ $codigo }}" name="tax_responsibilities[]"
                                   value="{{ $codigo }}"
                                   @checked(in_array($codigo, (array) $responsabilidadesActuales, true))>
                            <label class="custom-control-label" for="resp{{ $codigo }}">
                                <code>{{ $codigo }}</code> {{ $nombre }}
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@push('js')
    <script>
        // El municipio depende del departamento. Se piden al servidor y
        // no se cargan los 1.122 de golpe: en movil eso es un
        // desplegable inmanejable y medio megabyte de HTML.
        (function () {
            const depto = document.getElementById('department_dane_code');
            const muni = document.getElementById('municipality_dane_code');

            if (!depto || !muni) {
                return;
            }

            depto.addEventListener('change', function () {
                muni.innerHTML = '<option value="">Cargando…</option>';

                if (!this.value) {
                    muni.innerHTML = '<option value="">Seleccione primero el departamento</option>';
                    return;
                }

                fetch('{{ route('fiscal.municipios') }}?departamento=' + encodeURIComponent(this.value))
                    .then((r) => r.json())
                    .then((datos) => {
                        muni.innerHTML = '<option value="">Sin especificar</option>';

                        Object.entries(datos).forEach(([codigo, nombre]) => {
                            const opcion = document.createElement('option');
                            opcion.value = codigo;
                            opcion.textContent = nombre;
                            muni.appendChild(opcion);
                        });
                    })
                    .catch(() => {
                        muni.innerHTML = '<option value="">No se pudieron cargar</option>';
                    });
            });
        })();
    </script>
@endpush
