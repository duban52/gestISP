{{-- ============================================================
     Formulario de empresa, compartido por alta y edición.

     Solo EXIGE lo mínimo para operar. Los demás campos fiscales que
     pide la DIAN —tipo de organización, códigos DANE, responsabilidades—
     van en su propia tarjeta plegada más abajo: existen en la tabla
     desde la fase 8, pero hasta ahora ningún formulario los ofrecía y
     el informe de completitud fiscal los pedía sin que hubiera manera
     de completarlos. Se dejan opcionales por la misma razón que los del
     cliente: obligarlos aquí impediría dar de alta una empresa a quien
     todavía no tiene esa información a mano.
     ============================================================ --}}
@php
    $catalogo = \App\Models\FiscalCatalog::class;

    $tiposDocumento = $catalogo::opciones($catalogo::TIPO_DOCUMENTO);
    $organizaciones = $catalogo::opciones($catalogo::TIPO_ORGANIZACION);
    $departamentos = $catalogo::opciones($catalogo::DEPARTAMENTO);
    $responsabilidades = $catalogo::opciones($catalogo::RESPONSABILIDAD);

    $deptoActual = old('department_dane_code', $empresa->department_dane_code);
    $municipios = $deptoActual
        ? $catalogo::opciones($catalogo::MUNICIPIO, $deptoActual)
        : collect();

    $responsabilidadesActuales = old(
        'tax_responsibilities',
        $empresa->exists ? $empresa->taxResponsibilities->pluck('responsibility_code')->all() : [],
    );
@endphp

<div class="form-row">
    <div class="form-group col-md-7">
        <label>Razón social <span class="text-danger">*</span></label>
        <input type="text" name="legal_name" class="form-control @error('legal_name') is-invalid @enderror"
               value="{{ old('legal_name', $empresa->legal_name) }}" required>
        @error('legal_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <small class="form-text text-muted">Como aparece en el RUT.</small>
    </div>
    <div class="form-group col-md-5">
        <label>Nombre comercial</label>
        <input type="text" name="trade_name" class="form-control"
               value="{{ old('trade_name', $empresa->trade_name) }}">
        <small class="form-text text-muted">El que ve el cliente, si es distinto.</small>
    </div>
</div>

<div class="form-row">
    <div class="form-group col-md-3">
        <label>Tipo de documento <span class="text-danger">*</span></label>
        {{-- Sale del catálogo de la DIAN y no de una lista escrita a
             mano: es el mismo fallo que ya se corrigió en el cliente,
             donde una lista a mano guardaba «Persona Jurídica» cuando
             se elegía «Pasaporte». Se guarda el CÓDIGO, no el texto. --}}
        <select name="document_type_code" class="form-control @error('document_type_code') is-invalid @enderror">
            @foreach($tiposDocumento as $codigo => $nombre)
                <option value="{{ $codigo }}"
                        @selected(old('document_type_code', $empresa->document_type_code) === $codigo)>
                    {{ $nombre }}
                </option>
            @endforeach
        </select>
        @error('document_type_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="form-group col-md-5">
        <label>Número <span class="text-danger">*</span></label>
        <input type="text" name="document_number" class="form-control @error('document_number') is-invalid @enderror"
               value="{{ old('document_number', $empresa->document_number) }}" required>
        @error('document_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="form-group col-md-4">
        <label>Dígito de verificación</label>
        <input type="text" name="verification_digit" class="form-control" maxlength="1"
               value="{{ old('verification_digit', $empresa->verification_digit) }}">
        <small class="form-text text-muted">El que va tras el guion.</small>
    </div>
</div>

<div class="form-row">
    <div class="form-group col-md-6">
        <label>Dirección</label>
        <input type="text" name="address" class="form-control" value="{{ old('address', $empresa->address) }}">
    </div>
    <div class="form-group col-md-3">
        <label>Correo</label>
        <input type="email" name="email" class="form-control" value="{{ old('email', $empresa->email) }}">
    </div>
    <div class="form-group col-md-3">
        <label>Teléfono</label>
        <input type="text" name="phone" class="form-control" value="{{ old('phone', $empresa->phone) }}">
    </div>
</div>

{{-- ============================================================
     Datos fiscales adicionales.

     Existen en la tabla desde la fase 8 pero hasta ahora ningún
     formulario los pedía: el informe de completitud fiscal los exigía
     sin que hubiera manera de completarlos. Van plegados, igual que en
     el cliente, porque solo hacen falta para facturar electrónicamente.
     ============================================================ --}}
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
            Solo hacen falta para poder emitir <strong>factura electrónica</strong>.
            Puede dejarlos vacíos y completarlos después — el informe de completitud
            fiscal avisa cuando hagan falta de verdad.
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Tipo de organización</label>
                <select name="organization_type_code" class="form-control">
                    <option value="">Sin especificar</option>
                    @foreach($organizaciones as $codigo => $nombre)
                        <option value="{{ $codigo }}"
                                @selected(old('organization_type_code', $empresa->organization_type_code) === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Código postal</label>
                <input type="text" name="postal_code" class="form-control" maxlength="10"
                       value="{{ old('postal_code', $empresa->postal_code) }}">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Departamento</label>
                <select name="department_dane_code" id="company_department_dane_code" class="form-control">
                    <option value="">Sin especificar</option>
                    @foreach($departamentos as $codigo => $nombre)
                        <option value="{{ $codigo }}" @selected($deptoActual === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Municipio</label>
                <select name="municipality_dane_code" id="company_municipality_dane_code" class="form-control">
                    <option value="">Seleccione primero el departamento</option>
                    @foreach($municipios as $codigo => $nombre)
                        <option value="{{ $codigo }}"
                                @selected(old('municipality_dane_code', $empresa->municipality_dane_code) === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">Código DANE, el que pide el XML.</small>
            </div>
        </div>

        <div class="form-group mb-0">
            <label>Responsabilidades fiscales</label>
            <div class="row">
                @foreach($responsabilidades as $codigo => $nombre)
                    <div class="col-md-6">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input"
                                   id="empresa_resp{{ $codigo }}" name="tax_responsibilities[]"
                                   value="{{ $codigo }}"
                                   @checked(in_array($codigo, (array) $responsabilidadesActuales, true))>
                            <label class="custom-control-label" for="empresa_resp{{ $codigo }}">
                                <code>{{ $codigo }}</code> {{ $nombre }}
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="form-group">
    <label>Logo</label>
    @if($empresa->logo)
        <div class="mb-2">
            <img src="{{ asset('storage/' . $empresa->logo) }}" style="max-height: 90px">
        </div>
    @endif
    <input type="file" name="logo" class="form-control-file" accept="image/*">
    <small class="form-text text-muted">
        {{-- El logo es identidad del contribuyente: las sucursales de una
             misma empresa imprimen el mismo. Antes estaba en la sucursal y
             habia que subirlo en cada una. --}}
        Se usa en las facturas, los recibos y los correos de <strong>todas</strong>
        las sucursales de esta empresa. Máximo 2 MB.
    </small>
</div>

<hr>

{{-- ============================================================
     Modalidad de operación

     Es propiedad de la EMPRESA y no preferencia del usuario: define
     cómo trabaja esa organización. Qué ve cada persona dentro sigue
     dependiendo de sus sucursales y su rol, así que el gerente que ve
     las cinco y el cajero que ve la suya conviven igual.
     ============================================================ --}}
<h5>Modalidad de operación</h5>
@error('operation_mode')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

<div class="form-group">
    <div class="custom-control custom-radio mb-2">
        <input type="radio" id="modo_independiente" name="operation_mode" class="custom-control-input"
               value="{{ \App\Models\Company::MODO_INDEPENDIENTE }}"
               @checked(old('operation_mode', $empresa->operation_mode) !== \App\Models\Company::MODO_CONSOLIDADO)>
        <label class="custom-control-label" for="modo_independiente">
            <strong>Sucursales independientes</strong>
            <small class="d-block text-muted">
                Cada sucursal es un contexto propio. El usuario elige una al entrar y
                trabaja solo con sus datos. Es el modo de siempre.
            </small>
        </label>
    </div>
    <div class="custom-control custom-radio">
        <input type="radio" id="modo_consolidado" name="operation_mode" class="custom-control-input"
               value="{{ \App\Models\Company::MODO_CONSOLIDADO }}"
               @checked(old('operation_mode', $empresa->operation_mode) === \App\Models\Company::MODO_CONSOLIDADO)>
        <label class="custom-control-label" for="modo_consolidado">
            <strong>Panel consolidado</strong>
            <small class="d-block text-muted">
                Todas las sucursales desde un solo panel. Al crear un documento hay que
                elegir en qué sucursal queda, y cada una conserva sus prefijos y
                consecutivos. Necesita al menos dos sucursales.
            </small>
        </label>
    </div>
</div>

<div class="form-group">
    <div class="custom-control custom-checkbox">
        <input type="checkbox" class="custom-control-input" id="active" name="active" value="1"
               @checked(old('active', $empresa->active ?? true))>
        <label class="custom-control-label" for="active">Empresa activa</label>
    </div>
</div>

@push('js')
    <script>
        // El municipio depende del departamento. Se piden al servidor y
        // no se cargan los 1.122 de golpe: misma razon que en el
        // cliente, y mismo endpoint.
        (function () {
            const depto = document.getElementById('company_department_dane_code');
            const muni = document.getElementById('company_municipality_dane_code');

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
