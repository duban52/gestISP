{{-- ============================================================
     Edición de cliente

     EL FALLO QUE TENÍA ESTA PANTALLA
     --------------------------------
     El formulario de ELIMINAR estaba escrito DENTRO del de
     actualizar. HTML no admite formularios anidados: el navegador
     descarta la etiqueta del interior y sus campos pasan a ser del
     exterior. El formulario de actualizar acababa llevando DOS
     campos `_method` —PUT y DELETE—, PHP se queda con el último, y
     Laravel enrutaba la petición al borrado.

     Es decir: EL BOTÓN «ACTUALIZAR CLIENTE» ELIMINABA AL CLIENTE.
     Comprobado en un navegador: un solo formulario en la página y
     `_method = ["PUT", "DELETE"]` en los dos botones.

     Por eso aquí hay dos formularios HERMANOS, nunca uno dentro de
     otro, y el de eliminar vive en su propia tarjeta al final. Hay
     una prueba que falla si alguien vuelve a anidarlos.

     QUÉ SE PUEDE EDITAR
     -------------------
     Solo el contacto y los datos fiscales. El documento y el nombre
     identifican al cliente —están en sus facturas— y no se cambian
     desde aquí; por eso se muestran como texto y no como campos
     deshabilitados que parecen editables.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Editar cliente')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h1 class="mb-0"><i class="fas fa-user-edit mr-2"></i>Editar cliente</h1>
            <small class="text-muted">
                <strong>{{ $client->name }} {{ $client->last_name }}</strong>
                · {{ $client->identity_number }}
            </small>
        </div>
        <a href="{{ route('clients.index') }}" class="btn btn-secondary" data-cancelar>
            <i class="fas fa-arrow-left"></i> Volver al listado
        </a>
    </div>
@endsection

@section('content')

    {{-- El controlador avisa con `success`; `success-update` se
         conserva por si alguna pantalla antigua todavía lo usa. Antes
         solo se pintaba el segundo, así que guardar bien no decía
         nada. --}}
    @if(session('success') || session('success-update'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') ?: session('success-update') }}
        </div>
    @endif

    @include('gestisp.partials.alerta-error')

    {{-- ==================== FORMULARIO 1: actualizar ==================== --}}
    <form method="POST" action="{{ route('clients.update', $client) }}" id="formCliente">
        @csrf
        @method('PUT')

        <div class="card card-outline card-secondary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-id-card mr-1"></i> Identificación</h3>
                <div class="card-tools">
                    <span class="badge badge-light border">
                        <i class="fas fa-lock mr-1"></i> No se edita
                    </span>
                </div>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Tipo de cliente</small>
                        <strong>{{ $client->type_client ?: '—' }}</strong>
                    </div>

                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Tipo de documento</small>
                        @if($client->document_type_code)
                            <strong>{{ $client->tipoDocumento() }}</strong>
                        @else
                            {{-- Cliente de antes de la fase 8: nunca tuvo código.
                                 Se deja completar UNA vez —no hay otro sitio
                                 donde hacerlo, y el informe de completitud
                                 fiscal lo exige—. En cuanto se guarde, pasa a
                                 mostrarse bloqueado como los demás. --}}
                            <select class="form-control form-control-sm @error('document_type_code') is-invalid @enderror"
                                    id="document_type_code" name="document_type_code">
                                <option value="">Sin especificar</option>
                                @foreach(\App\Models\FiscalCatalog::opciones(\App\Models\FiscalCatalog::TIPO_DOCUMENTO) as $codigo => $nombre)
                                    <option value="{{ $codigo }}" @selected(old('document_type_code') === (string) $codigo)>
                                        {{ $nombre }}
                                    </option>
                                @endforeach
                            </select>
                            @error('document_type_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="form-text text-muted">
                                No tiene tipo registrado. Selecciónelo: no podrá cambiarlo después.
                            </small>
                        @endif
                    </div>

                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Número de documento</small>
                        <strong>{{ $client->identity_number }}</strong>
                    </div>

                    <div class="col-md-3 mb-2">
                        <small class="text-muted d-block">Nombre</small>
                        <strong>{{ $client->name }} {{ $client->last_name }}</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-phone-alt mr-1"></i> Contacto</h3>
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-4">
                        <label for="number_phone">Teléfono principal <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control @error('number_phone') is-invalid @enderror"
                               id="number_phone" name="number_phone"
                               value="{{ old('number_phone', $client->number_phone) }}"
                               maxlength="20" inputmode="tel" required>
                        @error('number_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group col-md-4">
                        <label for="aditional_phone">Teléfono adicional</label>
                        <input type="tel" class="form-control @error('aditional_phone') is-invalid @enderror"
                               id="aditional_phone" name="aditional_phone"
                               value="{{ old('aditional_phone', $client->aditional_phone) }}"
                               placeholder="Opcional" maxlength="20" inputmode="tel">
                        @error('aditional_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group col-md-4">
                        <label for="email">Correo electrónico</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email"
                               value="{{ old('email', $client->email) }}"
                               placeholder="Opcional: nombre@dominio.com" maxlength="255">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <x-campos-fiscales-cliente :cliente="$client" />

        <div class="d-flex justify-content-end flex-wrap mb-4" style="gap: 8px;">
            <a href="{{ route('clients.index') }}" class="btn btn-outline-secondary" data-cancelar>
                Cancelar
            </a>
            <button type="submit" class="btn btn-primary" id="btnGuardarCliente">
                <i class="fas fa-save mr-1"></i> Guardar cambios
            </button>
        </div>
    </form>
    {{-- ==================== fin del formulario 1 ==================== --}}

    {{-- ==================== FORMULARIO 2: eliminar ====================
         HERMANO del anterior, jamás dentro. Ver la cabecera del archivo.

         El aviso de antes decía «todos los contratos vinculados al
         cliente se eliminarán». Ya no es verdad —y qué bien—: un
         cliente con contratos o con facturas no se puede eliminar, y
         aquí se dice de entrada en vez de dejar pulsar el botón para
         descubrirlo. --}}
    @php
        $contratosDelCliente = $client->contracts()->count();
    @endphp

    <div class="card card-outline card-danger">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-1"></i> Eliminar cliente</h3>
        </div>

        <div class="card-body d-flex justify-content-between align-items-center flex-wrap" style="gap: 12px;">
            @if($contratosDelCliente > 0)
                <div class="text-muted">
                    Este cliente tiene
                    <strong>{{ $contratosDelCliente }} {{ $contratosDelCliente === 1 ? 'contrato' : 'contratos' }}</strong>
                    y no se puede eliminar: sus facturas y su historial dependen de él.
                    Para darlo de baja, retire sus contratos con una orden de retiro.
                </div>
                <button type="button" class="btn btn-outline-danger" disabled>
                    <i class="fas fa-trash mr-1"></i> Eliminar
                </button>
            @else
                <div class="text-muted">
                    Solo para altas hechas por error. Un cliente sin contratos ni facturas
                    se elimina definitivamente; no hay forma de deshacerlo.
                </div>
                <form action="{{ route('clients.destroy', $client) }}" method="POST" id="formEliminarCliente"
                      onsubmit="return confirm('¿Eliminar definitivamente a este cliente? Esta acción no se puede deshacer.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash mr-1"></i> Eliminar
                    </button>
                </form>
            @endif
        </div>
    </div>
@endsection

@section('js')
    @include('gestisp.clients._formulario-js', ['formulario' => 'formCliente', 'boton' => 'btnGuardarCliente'])
@endsection
