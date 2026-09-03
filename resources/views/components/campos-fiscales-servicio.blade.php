{{--
    Datos fiscales del servicio.

    QUÉ SON Y POR QUÉ ESTÁN APARTE
    ------------------------------
    Lo que el XML de una factura electrónica exige de cada línea: el
    código de producto (UNSPSC o uno interno, hay que DECIR cuál) y la
    unidad de medida. Existían en la tabla desde la fase 8, pero no eran
    ni `fillable` en el modelo ni las ofrecía ningún formulario: el
    informe de completitud fiscal las exige sin que hubiera manera de
    completarlas.

    Van en su propia tarjeta, plegada, por la misma razón que las del
    cliente: **hoy no hacen falta para nada**, solo cuando el servicio
    entre en un contrato que factura electrónicamente.

    Parámetros: $servicio (null al crear)
--}}
@props(['servicio' => null])

@php
    $catalogo = \App\Models\FiscalCatalog::class;

    $tiposCodigo = $catalogo::opciones($catalogo::TIPO_CODIGO_PRODUCTO);
    $unidades = $catalogo::opciones($catalogo::UNIDAD_MEDIDA);
    $impuestos = $catalogo::opciones($catalogo::TIPO_IMPUESTO);
@endphp

<div class="card card-outline card-secondary collapsed-card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-barcode mr-1"></i> Datos fiscales
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
            Solo hacen falta si este servicio va a facturarse <strong>electrónicamente</strong>.
            Puede dejarlos vacíos y completarlos después.
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="product_code">Código de producto</label>
                <input type="text" name="product_code" id="product_code" maxlength="40"
                       class="form-control @error('product_code') is-invalid @enderror"
                       value="{{ old('product_code', $servicio?->product_code) }}">
                @error('product_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">El UNSPSC del servicio, o uno interno propio.</small>
            </div>

            <div class="form-group col-md-6">
                <label for="product_code_type">Tipo de código</label>
                <select name="product_code_type" id="product_code_type" class="form-control">
                    <option value="">Sin especificar</option>
                    @foreach($tiposCodigo as $codigo => $nombre)
                        <option value="{{ $codigo }}"
                                @selected(old('product_code_type', $servicio?->product_code_type) === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">Hay que decir cuál se está usando: el XML lo exige.</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label for="unit_measure_code">Unidad de medida</label>
                <select name="unit_measure_code" id="unit_measure_code" class="form-control" style="width:100%">
                    <option value="">Sin especificar</option>
                    @foreach($unidades as $codigo => $nombre)
                        <option value="{{ $codigo }}"
                                @selected(old('unit_measure_code', $servicio?->unit_measure_code) === $codigo)>
                            {{ $nombre }} ({{ $codigo }})
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">
                    Para un servicio de internet suele ser «unidad» (94).
                </small>
            </div>

            <div class="form-group col-md-6">
                <label for="tax_code">Tipo de impuesto</label>
                <select name="tax_code" id="tax_code" class="form-control">
                    <option value="">Sin especificar</option>
                    @foreach($impuestos as $codigo => $nombre)
                        <option value="{{ $codigo }}"
                                @selected(old('tax_code', $servicio?->tax_code) === $codigo)>
                            {{ $nombre }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // La unidad de medida tiene 1.093 códigos: sin buscador es
            // un desplegable inmanejable. Select2 lo activa la propia
            // pantalla con @@section('plugins.Select2', true).
            if (window.jQuery && jQuery.fn.select2) {
                jQuery('#unit_measure_code').select2({
                    width: '100%',
                    placeholder: 'Sin especificar',
                    allowClear: true,
                });
            }
        });
    </script>
@endpush
