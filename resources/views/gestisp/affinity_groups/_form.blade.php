{{--
    Campos del grupo de afinidad, compartidos por alta y edición.

    Parámetros: $grupo (null al crear)

    Los campos de la DIAN van en su propia tarjeta, plegada y con su
    aviso: hoy no hacen nada. Se pueden rellenar ya —y quien tenga la
    información a mano hará bien en dejarla puesta—, pero mezclarlos
    con los campos que sí tienen efecto haría pensar que ya se está
    facturando electrónicamente.
--}}
@php
    $grupo = $grupo ?? null;
@endphp

<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-tag mr-1"></i> Identificación</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="code">Código <span class="text-danger">*</span></label>
                <input type="text" name="code" id="code" maxlength="20"
                       class="form-control text-uppercase @error('code') is-invalid @enderror"
                       value="{{ old('code', $grupo?->code) }}" required>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">
                    Corto y estable: se usará en informes y, más adelante, en la serie de numeración.
                </small>
            </div>

            <div class="form-group col-md-6">
                <label for="name">Nombre <span class="text-danger">*</span></label>
                <input type="text" name="name" id="name" maxlength="120"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $grupo?->name) }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group col-md-3">
                <label for="sort_order">Orden</label>
                <input type="number" name="sort_order" id="sort_order" min="0" max="9999"
                       class="form-control @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', $grupo?->sort_order ?? 0) }}">
                @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">En qué orden aparece en los desplegables.</small>
            </div>
        </div>

        <div class="form-group">
            <label for="description">Descripción</label>
            <input type="text" name="description" id="description" maxlength="255"
                   class="form-control @error('description') is-invalid @enderror"
                   value="{{ old('description', $grupo?->description) }}">
            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<div class="card card-outline card-warning">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-file-invoice mr-1"></i> Cómo se factura</h3>
    </div>
    <div class="card-body">
        <div class="form-group">
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="requires_electronic_invoicing"
                       name="requires_electronic_invoicing" value="1"
                       @checked(old('requires_electronic_invoicing', $grupo?->requires_electronic_invoicing))>
                <label class="custom-control-label" for="requires_electronic_invoicing">
                    Facturación electrónica
                </label>
            </div>
            <small class="form-text text-muted">
                Los contratos de este grupo emitirán <strong>factura electrónica</strong>, con su
                firma digital y su numeración autorizada. Sin marcar, emiten un
                <strong>documento interno</strong>.
            </small>
        </div>

        {{-- El aviso del análisis fiscal. No es un detalle de estilo:
             es lo que protege a quien use el documento interno. --}}
        <div class="alert alert-secondary mb-3">
            <i class="fas fa-info-circle mr-1"></i>
            Un documento interno <strong>no puede llamarse factura</strong> ni parecerlo: va
            rotulado como documento interno o de cobro, con su propia numeración y sin los
            elementos de una factura electrónica. Esa distinción es justamente lo que protege a
            quien lo emite.
        </div>

        <div class="form-group mb-0">
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="requires_client_tax_data"
                       name="requires_client_tax_data" value="1"
                       @checked(old('requires_client_tax_data', $grupo?->requires_client_tax_data))>
                <label class="custom-control-label" for="requires_client_tax_data">
                    Exigir datos fiscales completos del cliente
                </label>
            </div>
            <small class="form-text text-muted">
                Avisa al <strong>dar de alta el contrato</strong> si al cliente le faltan datos,
                en vez de descubrirlo el día de emitir la factura.
            </small>
        </div>
    </div>
</div>

<div class="card card-outline card-secondary collapsed-card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-landmark mr-1"></i> Códigos DIAN</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-plus"></i>
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-secondary">
            <i class="fas fa-info-circle mr-1"></i>
            Estos códigos salen de los catálogos del anexo técnico de la DIAN y
            <strong>todavía no se usan</strong>: se validarán contra el catálogo y se llevarán al
            XML cuando exista la emisión electrónica. Puede dejarlos vacíos.
        </div>

        <div class="form-row">
            <div class="form-group col-md-4">
                <label for="dian_operation_type_code">Tipo de operación</label>
                <input type="text" name="dian_operation_type_code" id="dian_operation_type_code" maxlength="10"
                       class="form-control @error('dian_operation_type_code') is-invalid @enderror"
                       value="{{ old('dian_operation_type_code', $grupo?->dian_operation_type_code) }}">
                @error('dian_operation_type_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group col-md-4">
                <label for="default_payment_means_code">Medio de pago</label>
                <input type="text" name="default_payment_means_code" id="default_payment_means_code" maxlength="10"
                       class="form-control @error('default_payment_means_code') is-invalid @enderror"
                       value="{{ old('default_payment_means_code', $grupo?->default_payment_means_code) }}">
                @error('default_payment_means_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">Contado o crédito.</small>
            </div>

            <div class="form-group col-md-4">
                <label for="default_payment_method_code">Forma de pago</label>
                <input type="text" name="default_payment_method_code" id="default_payment_method_code" maxlength="10"
                       class="form-control @error('default_payment_method_code') is-invalid @enderror"
                       value="{{ old('default_payment_method_code', $grupo?->default_payment_method_code) }}">
                @error('default_payment_method_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">Efectivo, transferencia…</small>
            </div>
        </div>
    </div>
</div>

<div class="card card-outline card-info">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-toggle-on mr-1"></i> Estado</h3>
    </div>
    <div class="card-body">
        <div class="form-group">
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="active"
                       name="active" value="1"
                       @checked(old('active', $grupo?->active ?? true))>
                <label class="custom-control-label" for="active">Activo</label>
            </div>
            @error('active')<div class="text-danger small">{{ $message }}</div>@enderror
            <small class="form-text text-muted">
                Un grupo inactivo deja de ofrecerse al dar de alta contratos, pero
                <strong>no toca los que ya lo tienen</strong>.
            </small>
        </div>

        <div class="form-group mb-0">
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="is_default"
                       name="is_default" value="1"
                       @checked(old('is_default', $grupo?->is_default))>
                <label class="custom-control-label" for="is_default">Grupo predeterminado</label>
            </div>
            @error('is_default')<div class="text-danger small">{{ $message }}</div>@enderror
            <small class="form-text text-muted">
                Lo reciben los contratos que se den de alta sin elegir grupo. Solo puede haber
                uno por empresa: al marcar este, el anterior deja de serlo.
            </small>
        </div>
    </div>
</div>

<div class="card card-outline card-secondary">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-sticky-note mr-1"></i> Notas internas</h3>
    </div>
    <div class="card-body">
        <textarea name="notes" id="notes" rows="3" maxlength="2000"
                  class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $grupo?->notes) }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <small class="form-text text-muted">
            Para qué se creó el grupo y qué contratos deben ir en él. No sale en ningún documento.
        </small>
    </div>
</div>
