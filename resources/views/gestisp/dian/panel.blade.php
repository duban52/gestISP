@extends('adminlte::page')
@section('title', 'Facturación DIAN')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-file-invoice-dollar mr-2"></i>Facturación electrónica
            <small class="text-muted">{{ $empresa->nombreVisible() }}</small>
        </h1>
        <a href="{{ route('companies.show', $empresa) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a la empresa
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

    {{-- ============================================================
         Qué falta.

         Va primero porque es la pregunta que trae aquí a la gente. Es
         el mismo diagnóstico que `php artisan dian:diagnostico`.
         ============================================================ --}}
    <div class="card card-outline {{ $listo ? 'card-success' : 'card-warning' }}">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-clipboard-check mr-1"></i>
                {{ $listo ? 'Lista para emitir' : 'Qué falta para poder emitir' }}
            </h3>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0 tabla-movil">
                <tbody>
                @foreach($revision as $paso)
                    <tr>
                        <td style="width:40px" class="text-center">
                            @if($paso['ok'])
                                <i class="fas fa-check-circle text-success"></i>
                            @elseif($paso['bloqueante'])
                                <i class="fas fa-times-circle text-danger"></i>
                            @else
                                <i class="fas fa-exclamation-triangle text-warning"></i>
                            @endif
                        </td>
                        <td class="celda-principal" data-label=""><strong>{{ $paso['titulo'] }}</strong></td>
                        <td data-label="">{{ $paso['detalle'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @unless($listo)
            <div class="card-footer text-muted py-2">
                <small>
                    <i class="fas fa-info-circle"></i>
                    Encender la facturación electrónica es un paso aparte, y solo se puede
                    cuando no quede nada en rojo:
                    <code>php artisan dian:habilitar --empresa={{ $empresa->id }}</code>
                </small>
            </div>
        @endunless
    </div>

    <div class="row">
        {{-- ============================================================
             Configuración: el software autorizado.
             ============================================================ --}}
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-cogs mr-1"></i> Configuración</h3>
                </div>
                <form method="POST" action="{{ route('dian.configuracion', $empresa) }}">
                    @csrf @method('PUT')
                    <div class="card-body">
                        <div class="form-group">
                            <label>Identificador del software</label>
                            <input type="text" name="software_id" class="form-control"
                                   value="{{ old('software_id', $configuracion?->software_id) }}">
                            <small class="form-text text-muted">
                                El que asigna la DIAN al activar el software en su sistema.
                            </small>
                        </div>

                        <div class="form-group">
                            <label>PIN del software</label>
                            {{-- Nunca se devuelve: un campo que trae el
                                 secreto lo pone en el HTML, en la caché del
                                 navegador y en el historial de quien lo mire. --}}
                            <input type="password" name="software_pin" class="form-control" autocomplete="new-password"
                                   placeholder="{{ $configuracion?->software_pin ? '•••••••• (déjelo vacío para no cambiarlo)' : '' }}">
                            <small class="form-text text-muted">
                                El que usted eligió al activar el software. No se muestra nunca:
                                déjelo vacío si no lo va a cambiar.
                            </small>
                        </div>

                        <div class="form-group">
                            <label>Identificador del set de pruebas</label>
                            <input type="text" name="test_set_id" class="form-control"
                                   value="{{ old('test_set_id', $configuracion?->test_set_id) }}">
                            <small class="form-text text-muted">
                                Lo asigna la DIAN para la habilitación.
                            </small>
                        </div>

                        <div class="form-group mb-0">
                            <label>Dirección del servicio de la DIAN</label>
                            <input type="url" name="endpoint_override" class="form-control @error('endpoint_override') is-invalid @enderror"
                                   value="{{ old('endpoint_override', $configuracion?->endpoint_override) }}"
                                   placeholder="{{ $urlEnUso }}">
                            @error('endpoint_override')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="form-text text-muted">
                                <strong>Normalmente se deja vacía.</strong> El sistema usa sola la dirección
                                pública que corresponde al ambiente de la empresa —hoy sería
                                <code>{{ $urlEnUso }}</code>.
                                <br>
                                Solo hace falta ponerla si la DIAN la cambia, o si esta empresa transmite
                                a través de un proveedor tecnológico.
                            </small>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="badge badge-{{ $configuracion?->estaEnProduccion() ? 'success' : 'secondary' }}">
                                {{ $configuracion?->estaEnProduccion() ? 'Producción' : 'Pruebas' }}
                            </span>
                            @if($configuracion?->enabled_at)
                                <small class="text-muted ml-1">
                                    Habilitada el {{ $configuracion->enabled_at->format('d/m/Y') }}
                                </small>
                            @endif
                        </div>
                        @can('dian.manage')
                            <button class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                        @endcan
                    </div>
                </form>
            </div>
        </div>

        {{-- ============================================================
             Certificado: la clave privada de la empresa.
             ============================================================ --}}
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-key mr-1"></i> Certificado digital</h3>
                </div>
                <div class="card-body">
                    @if($certificados->isEmpty())
                        <p class="text-muted mb-3">Todavía no hay ningún certificado cargado.</p>
                    @else
                        <div class="table-responsive mb-3">
                            <table class="table table-sm mb-0">
                                <thead class="thead-light">
                                <tr><th>Certificado</th><th>Vigencia</th><th class="text-center">Estado</th><th></th></tr>
                                </thead>
                                <tbody>
                                @foreach($certificados as $certificado)
                                    <tr class="{{ $certificado->active ? '' : 'text-muted' }}">
                                        <td>{{ $certificado->name }}</td>
                                        <td>
                                            {{ optional($certificado->valid_until)->format('d/m/Y') ?: '—' }}
                                            @php($dias = $certificado->diasParaCaducar())
                                            @if($dias !== null && $dias <= 0)
                                                <span class="badge badge-danger">Caducado</span>
                                            @elseif($dias !== null && $dias <= 30)
                                                <span class="badge badge-warning">{{ $dias }} días</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-{{ $certificado->vigente() ? 'success' : 'secondary' }}">
                                                {{ $certificado->active ? ($certificado->vigente() ? 'En uso' : 'Fuera de vigencia') : 'Inactivo' }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            @if($certificado->active)
                                                @can('dian.manage')
                                                    <form method="POST" action="{{ route('dian.certificados.destroy', [$empresa, $certificado]) }}"
                                                          onsubmit="return confirm('Se dejará de firmar con este certificado. ¿Continuar?');">
                                                        @csrf @method('DELETE')
                                                        <button class="btn btn-sm btn-outline-secondary">Desactivar</button>
                                                    </form>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @can('dian.manage')
                        <form method="POST" action="{{ route('dian.certificados.store', $empresa) }}"
                              enctype="multipart/form-data">
                            @csrf
                            <div class="form-group">
                                <label>Archivo .p12</label>
                                <input type="file" name="certificado" class="form-control-file" accept=".p12,.pfx" required>
                            </div>
                            <div class="form-group">
                                <label>Contraseña del certificado</label>
                                <input type="password" name="password" class="form-control" autocomplete="new-password" required>
                            </div>
                            <button class="btn btn-primary btn-block">
                                <i class="fas fa-upload"></i> Cargar certificado
                            </button>
                        </form>
                    @endcan
                </div>
                <div class="card-footer text-muted py-2">
                    <small>
                        <i class="fas fa-lock"></i>
                        Contiene la <strong>clave privada</strong> con la que se firma todo lo que se
                        le presenta a la DIAN. Se guarda fuera del directorio público, su contraseña
                        se cifra, y no se puede volver a descargar.
                    </small>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Resoluciones: de aquí sale la numeración autorizada.
         ============================================================ --}}
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0"><i class="fas fa-stamp mr-1"></i> Resoluciones de numeración</h3>
            @can('dian.manage')
                <button class="btn btn-sm btn-primary" data-toggle="collapse" data-target="#nuevaResolucion">
                    <i class="fas fa-plus"></i> Añadir
                </button>
            @endcan
        </div>

        @can('dian.manage')
            <div class="collapse {{ $errors->has('resolution_number') || $errors->has('technical_key') ? 'show' : '' }}" id="nuevaResolucion">
                <div class="card-body bg-light border-bottom">
                    <form method="POST" action="{{ route('dian.resoluciones.store', $empresa) }}">
                        @csrf
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Número de resolución <span class="text-danger">*</span></label>
                                <input type="text" name="resolution_number" class="form-control @error('resolution_number') is-invalid @enderror"
                                       value="{{ old('resolution_number') }}" required>
                                @error('resolution_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-group col-md-3">
                                <label>Tipo de documento <span class="text-danger">*</span></label>
                                <select name="document_type_code" class="form-control">
                                    <option value="01" @selected(old('document_type_code') === '01')>Factura de venta</option>
                                    <option value="91" @selected(old('document_type_code') === '91')>Nota crédito</option>
                                    <option value="92" @selected(old('document_type_code') === '92')>Nota débito</option>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Desde <span class="text-danger">*</span></label>
                                <input type="date" name="valid_from" class="form-control" value="{{ old('valid_from') }}" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Hasta <span class="text-danger">*</span></label>
                                <input type="date" name="valid_until" class="form-control @error('valid_until') is-invalid @enderror"
                                       value="{{ old('valid_until') }}" required>
                                @error('valid_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Clave técnica <span class="text-danger">*</span></label>
                            <input type="password" name="technical_key" class="form-control @error('technical_key') is-invalid @enderror"
                                   autocomplete="new-password" required>
                            @error('technical_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="form-text text-muted">
                                La que viene con el rango. <strong>No viaja en ningún XML</strong>: entra en el
                                cálculo del CUFE, y es lo que impide falsificarlo conociendo solo la factura.
                                Se guarda cifrada y no se vuelve a mostrar.
                            </small>
                        </div>

                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="resActiva" name="active" value="1" checked>
                            <label class="custom-control-label" for="resActiva">Activa</label>
                        </div>

                        <button class="btn btn-primary"><i class="fas fa-save"></i> Guardar resolución</button>
                    </form>
                </div>
            </div>
        @endcan

        <div class="card-body p-0">
            @if($resoluciones->isEmpty())
                <p class="p-4 mb-0 text-muted">
                    Sin resolución no se puede numerar una factura electrónica: el número tiene que
                    salir de un rango que la DIAN autorizó.
                </p>
            @else
                <table class="table table-sm table-hover mb-0 tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>Resolución</th>
                        <th>Documento</th>
                        <th>Vigencia</th>
                        <th class="text-center">Rangos</th>
                        <th class="text-center">Estado</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($resoluciones as $resolucion)
                        <tr class="{{ $resolucion->active ? '' : 'text-muted' }}">
                            <td class="celda-principal" data-label=""><code>{{ $resolucion->resolution_number }}</code></td>
                            <td data-label="Documento">
                                @switch($resolucion->document_type_code)
                                    @case('01') Factura de venta @break
                                    @case('91') Nota crédito @break
                                    @case('92') Nota débito @break
                                    @default {{ $resolucion->document_type_code }}
                                @endswitch
                            </td>
                            <td data-label="Vigencia">
                                {{ $resolucion->valid_from->format('d/m/Y') }} –
                                {{ $resolucion->valid_until->format('d/m/Y') }}
                            </td>
                            <td class="text-center" data-label="Rangos">{{ $resolucion->ranges_count }}</td>
                            <td class="text-center" data-label="Estado">
                                <span class="badge badge-{{ $resolucion->vigente() ? 'success' : 'secondary' }}">
                                    {{ $resolucion->vigente() ? 'Vigente' : ($resolucion->active ? 'Fuera de vigencia' : 'Inactiva') }}
                                </span>
                            </td>
                            <td class="text-center celda-acciones" data-label="">
                                <a href="{{ route('dian.resolucion', [$empresa, $resolucion]) }}"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-list-ol"></i> Rangos
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
