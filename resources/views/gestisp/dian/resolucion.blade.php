@extends('adminlte::page')
@section('title', 'Rangos de numeración')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-list-ol mr-2"></i>Resolución {{ $resolucion->resolution_number }}
            <small class="text-muted">{{ $empresa->nombreVisible() }}</small>
        </h1>
        <a href="{{ route('dian.panel', $empresa) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')
    @includeWhen(session('success'), 'gestisp.companies._alerta', ['tipo' => 'success', 'texto' => session('success')])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="callout callout-info">
        De aquí sale el número de cada factura electrónica. Un prefijo o un desde/hasta mal
        puestos producen documentos con numeración que <strong>la DIAN no autorizó</strong>, y
        eso no se descubre al guardar: se descubre al emitir.
    </div>

    {{-- ============================================================
         La resolución.
         ============================================================ --}}
    <div class="card">
        <div class="card-header py-2">
            <h3 class="card-title mb-0"><i class="fas fa-stamp mr-1"></i> Datos de la resolución</h3>
        </div>
        <form method="POST" action="{{ route('dian.resoluciones.update', [$empresa, $resolucion]) }}">
            @csrf @method('PUT')
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Número <span class="text-danger">*</span></label>
                        <input type="text" name="resolution_number" class="form-control"
                               value="{{ old('resolution_number', $resolucion->resolution_number) }}" required>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Tipo de documento <span class="text-danger">*</span></label>
                        <select name="document_type_code" class="form-control">
                            @foreach(['01' => 'Factura de venta', '91' => 'Nota crédito', '92' => 'Nota débito'] as $codigo => $nombre)
                                <option value="{{ $codigo }}"
                                        @selected(old('document_type_code', $resolucion->document_type_code) === $codigo)>
                                    {{ $nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <label>Desde <span class="text-danger">*</span></label>
                        <input type="date" name="valid_from" class="form-control"
                               value="{{ old('valid_from', $resolucion->valid_from->format('Y-m-d')) }}" required>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Hasta <span class="text-danger">*</span></label>
                        <input type="date" name="valid_until" class="form-control"
                               value="{{ old('valid_until', $resolucion->valid_until->format('Y-m-d')) }}" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Clave técnica</label>
                    <input type="password" name="technical_key" class="form-control" autocomplete="new-password"
                           placeholder="•••••••• (déjela vacía para no cambiarla)">
                    <small class="form-text text-muted">
                        No se muestra nunca. Cambiarla altera el CUFE de todo lo que se emita a
                        partir de ahora — las facturas ya emitidas conservan el suyo.
                    </small>
                </div>

                <div class="custom-control custom-checkbox">
                    <input type="checkbox" class="custom-control-input" id="activa" name="active" value="1"
                           @checked(old('active', $resolucion->active))>
                    <label class="custom-control-label" for="activa">Activa</label>
                </div>
            </div>
            @can('dian.manage')
                <div class="card-footer">
                    <button class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                </div>
            @endcan
        </form>
    </div>

    {{-- ============================================================
         Los rangos.
         ============================================================ --}}
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0"><i class="fas fa-hashtag mr-1"></i> Rangos autorizados</h3>
            @can('dian.manage')
                <button class="btn btn-sm btn-primary" data-toggle="collapse" data-target="#nuevoRango">
                    <i class="fas fa-plus"></i> Añadir rango
                </button>
            @endcan
        </div>

        @can('dian.manage')
            <div class="collapse {{ $errors->has('prefix') || $errors->has('range_start') || $errors->has('range_end') ? 'show' : '' }}" id="nuevoRango">
                <div class="card-body bg-light border-bottom">
                    <form method="POST" action="{{ route('dian.rangos.store', [$empresa, $resolucion]) }}">
                        @csrf
                        <div class="form-row">
                            <div class="form-group col-md-2">
                                <label>Prefijo <span class="text-danger">*</span></label>
                                <input type="text" name="prefix" class="form-control text-uppercase @error('prefix') is-invalid @enderror"
                                       value="{{ old('prefix') }}" maxlength="10" required>
                                @error('prefix')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-group col-md-3">
                                <label>Desde <span class="text-danger">*</span></label>
                                <input type="number" name="range_start" class="form-control @error('range_start') is-invalid @enderror"
                                       value="{{ old('range_start') }}" min="1" required>
                                @error('range_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-group col-md-3">
                                <label>Hasta <span class="text-danger">*</span></label>
                                <input type="number" name="range_end" class="form-control @error('range_end') is-invalid @enderror"
                                       value="{{ old('range_end') }}" min="1" required>
                                @error('range_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-group col-md-4">
                                <label>Sucursal</label>
                                <select name="branch_id" class="form-control">
                                    <option value="">Toda la empresa</option>
                                    @foreach($sucursales as $sucursal)
                                        <option value="{{ $sucursal->id }}" @selected(old('branch_id') == $sucursal->id)>
                                            {{ $sucursal->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">
                                    La resolución se le da al NIT, pero se puede repartir por sede.
                                </small>
                            </div>
                        </div>

                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="rangoActivo" name="active" value="1" checked>
                            <label class="custom-control-label" for="rangoActivo">Activo</label>
                        </div>

                        <button class="btn btn-primary"><i class="fas fa-save"></i> Guardar rango</button>
                    </form>
                </div>
            </div>
        @endcan

        <div class="card-body p-0">
            @if($rangos->isEmpty())
                <p class="p-4 mb-0 text-muted">
                    Esta resolución todavía no tiene ningún rango: sin él no se puede emitir.
                </p>
            @else
                <table class="table table-sm table-hover mb-0 tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>Prefijo</th>
                        <th>Rango</th>
                        <th>Sucursal</th>
                        <th class="text-right">Gastado</th>
                        <th class="text-right">Quedan</th>
                        <th class="text-center">Estado</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($rangos as $rango)
                        <tr class="{{ $rango->active ? '' : 'text-muted' }}">
                            <td class="celda-principal" data-label=""><code>{{ $rango->prefix }}</code></td>
                            <td data-label="Rango">
                                {{ number_format($rango->range_start, 0, ',', '.') }} –
                                {{ number_format($rango->range_end, 0, ',', '.') }}
                            </td>
                            <td data-label="Sucursal">{{ $rango->branch?->name ?? 'Toda la empresa' }}</td>
                            <td class="text-right" data-label="Gastado">
                                {{ $rango->current_number ? number_format($rango->current_number, 0, ',', '.') : '—' }}
                            </td>
                            <td class="text-right" data-label="Quedan">
                                {{ number_format($rango->restantes(), 0, ',', '.') }}
                                @if($rango->porAgotarse())
                                    <span class="badge badge-warning">Por agotarse</span>
                                @endif
                            </td>
                            <td class="text-center" data-label="Estado">
                                <span class="badge badge-{{ $rango->active ? 'success' : 'secondary' }}">
                                    {{ $rango->active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="text-center celda-acciones" data-label="">
                                @can('dian.manage')
                                    <form method="POST" action="{{ route('dian.rangos.alternar', [$empresa, $resolucion, $rango]) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary">
                                            {{ $rango->active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card-footer text-muted py-2">
            <small>
                <i class="fas fa-info-circle"></i>
                El consecutivo ya gastado no se edita: moverlo hacia atrás repetiría números ya
                emitidos, y hacia delante dejaría huecos que hay que justificar ante la DIAN.
                Un rango tampoco se borra — de él salieron facturas que tienen que poder decir
                con qué resolución se emitieron.
            </small>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
