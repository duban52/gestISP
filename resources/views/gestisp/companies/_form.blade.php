{{-- ============================================================
     Formulario de empresa, compartido por alta y edición.

     Solo pide lo que hace falta para operar. Los campos fiscales que
     exige la DIAN —responsabilidades, régimen, códigos DANE— existen
     en la tabla pero se piden en la fase de facturación electrónica:
     obligarlos aquí impediría dar de alta una empresa a quien todavía
     no tiene esa información a mano.
     ============================================================ --}}
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
        <select name="document_type_code" class="form-control">
            {{-- Códigos oficiales de la DIAN. Se guarda el CÓDIGO y no
                 el texto: el texto cambia entre versiones del anexo
                 técnico y el código no. --}}
            <option value="31" @selected(old('document_type_code', $empresa->document_type_code) === '31')>NIT</option>
            <option value="13" @selected(old('document_type_code', $empresa->document_type_code) === '13')>Cédula de ciudadanía</option>
            <option value="22" @selected(old('document_type_code', $empresa->document_type_code) === '22')>Cédula de extranjería</option>
        </select>
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
