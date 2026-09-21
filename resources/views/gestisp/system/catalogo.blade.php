@extends('adminlte::page')

@section('title', 'Estados y órdenes')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-project-diagram mr-2"></i>Estados y órdenes</h1>
        <span class="badge badge-danger p-2">Solo superadministrador</span>
    </div>
@endsection

@section('content')
    @include('gestisp.partials.resultado-accion')

    {{-- Lo que se configura aquí decide a quién se le factura, qué
         contratos tienen servicio y qué le pasa a los equipos de un
         cliente al cerrar una orden. Conviene que eso esté dicho. --}}
    <div class="callout callout-warning">
        <p class="mb-1">
            Lo que cambie aquí se aplica <strong>desde el próximo documento</strong>: la corrida
            mensual, la suspensión por mora y el cierre de órdenes leen estas tablas.
        </p>
        <p class="mb-0 text-muted">
            Las filas marcadas <span class="badge badge-secondary">sistema</span> las nombra el
            código por su valor. Se pueden describir, colorear y desactivar, pero no renombrar
            ni borrar.
        </p>
    </div>

    <div class="card card-primary card-outline card-outline-tabs">
        <div class="card-header p-0 border-bottom-0">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-toggle="pill" href="#pane-estados" role="tab">
                        <i class="fas fa-file-contract mr-1"></i> Estados de contrato
                        <span class="badge badge-primary ml-1">{{ $estados->count() }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="pill" href="#pane-ordenes" role="tab">
                        <i class="fas fa-tools mr-1"></i> Tipos y detalles de orden
                        <span class="badge badge-secondary ml-1">{{ $tipos->sum(fn ($t) => $t->details->count()) }}</span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content">

                {{-- ============================ ESTADOS ============================ --}}
                <div class="tab-pane fade show active" id="pane-estados" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                        <p class="text-muted mb-2 mb-md-0">
                            <i class="fas fa-info-circle mr-1"></i>
                            Un estado responde tres preguntas: si se le factura, si tiene servicio
                            y si es una baja.
                        </p>
                        <button class="btn btn-success" data-toggle="modal" data-target="#modalEstado">
                            <i class="fas fa-plus mr-1"></i> Nuevo estado
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead class="thead-light">
                            <tr>
                                <th>Estado</th>
                                <th class="text-center">Se factura</th>
                                <th class="text-center">Corrida mensual</th>
                                <th class="text-center">Tiene servicio</th>
                                <th class="text-center">Es baja</th>
                                <th class="text-center">Activo</th>
                                <th class="text-right" style="width: 120px;">Acciones</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($estados as $estado)
                                <tr>
                                    <td>
                                        <span class="badge badge-{{ $estado->color }}">{{ $estado->name }}</span>
                                        @if($estado->is_system)
                                            <span class="badge badge-secondary ml-1" title="El código lo nombra por su valor">sistema</span>
                                        @endif
                                        <small class="d-block text-muted">{{ $estado->description }}</small>
                                    </td>
                                    <td class="text-center">@include('gestisp.system.partials.si-no', ['valor' => $estado->bills])</td>
                                    <td class="text-center">@include('gestisp.system.partials.si-no', ['valor' => $estado->auto_bills])</td>
                                    <td class="text-center">@include('gestisp.system.partials.si-no', ['valor' => $estado->has_service])</td>
                                    <td class="text-center">@include('gestisp.system.partials.si-no', ['valor' => $estado->is_final])</td>
                                    <td class="text-center">@include('gestisp.system.partials.si-no', ['valor' => $estado->active])</td>
                                    <td class="text-right">
                                        <button class="btn btn-sm btn-outline-primary btn-editar-estado"
                                                data-estado="{{ $estado->toJson() }}"
                                                data-url="{{ route('system.status.update', $estado) }}"
                                                title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        @unless($estado->is_system)
                                            <form method="POST" action="{{ route('system.status.destroy', $estado) }}"
                                                  class="d-inline" onsubmit="return confirm('¿Borrar el estado {{ $estado->name }}?');">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" title="Borrar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- ============================ ÓRDENES ============================ --}}
                <div class="tab-pane fade" id="pane-ordenes" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                        <p class="text-muted mb-2 mb-md-0">
                            <i class="fas fa-info-circle mr-1"></i>
                            El detalle decide a qué estado va el contrato al cerrar la orden y qué
                            se le hace a sus equipos.
                        </p>
                        <div>
                            <button class="btn btn-outline-success" data-toggle="modal" data-target="#modalTipo">
                                <i class="fas fa-plus mr-1"></i> Nuevo tipo
                            </button>
                            <button class="btn btn-success" data-toggle="modal" data-target="#modalDetalle">
                                <i class="fas fa-plus mr-1"></i> Nuevo detalle
                            </button>
                        </div>
                    </div>

                    @foreach($tipos as $tipo)
                        <div class="card card-outline {{ $tipo->active ? 'card-primary' : 'card-secondary' }} mb-3">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>{{ $tipo->name }}</strong>
                                    @if($tipo->is_system)
                                        <span class="badge badge-secondary ml-1">sistema</span>
                                    @endif
                                    @unless($tipo->active)
                                        <span class="badge badge-warning ml-1">desactivado</span>
                                    @endunless
                                    <small class="d-block text-muted">{{ $tipo->description }}</small>
                                </div>
                                <div>
                                    <button class="btn btn-sm btn-outline-primary btn-editar-tipo"
                                            data-tipo="{{ $tipo->toJson() }}"
                                            data-url="{{ route('system.order_type.update', $tipo) }}">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    @unless($tipo->is_system)
                                        <form method="POST" action="{{ route('system.order_type.destroy', $tipo) }}"
                                              class="d-inline" onsubmit="return confirm('¿Borrar el tipo {{ $tipo->name }}?');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    @endunless
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="thead-light">
                                    <tr>
                                        <th>Detalle</th>
                                        <th>Deja el contrato en</th>
                                        <th class="text-center">Cuenta PPPoE</th>
                                        <th class="text-center">ONT</th>
                                        <th class="text-center">Activo</th>
                                        <th class="text-right" style="width: 120px;">Acciones</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($tipo->details as $detalle)
                                        <tr>
                                            <td>
                                                <span class="badge" style="background: {{ $detalle->color }}; color: #fff;">
                                                    {{ $detalle->name }}
                                                </span>
                                                @if($detalle->is_system)
                                                    <span class="badge badge-secondary ml-1">sistema</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($detalle->target_contract_status)
                                                    {{ $detalle->target_contract_status }}
                                                @else
                                                    <span class="text-muted">no lo cambia</span>
                                                @endif
                                            </td>
                                            <td class="text-center">@include('gestisp.system.partials.accion', ['accion' => $detalle->pppoe_action])</td>
                                            <td class="text-center">@include('gestisp.system.partials.accion', ['accion' => $detalle->ont_action])</td>
                                            <td class="text-center">@include('gestisp.system.partials.si-no', ['valor' => $detalle->active])</td>
                                            <td class="text-right">
                                                <button class="btn btn-sm btn-outline-primary btn-editar-detalle"
                                                        data-detalle="{{ $detalle->toJson() }}"
                                                        data-url="{{ route('system.order_detail.update', $detalle) }}">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                @unless($detalle->is_system)
                                                    <form method="POST" action="{{ route('system.order_detail.destroy', $detalle) }}"
                                                          class="d-inline" onsubmit="return confirm('¿Borrar el detalle {{ $detalle->name }}?');">
                                                        @csrf @method('DELETE')
                                                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                @endunless
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-muted text-center py-3">Sin detalles.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>

            </div>
        </div>
    </div>

    @include('gestisp.system.partials.modales', ['estados' => $estados, 'tipos' => $tipos])
@endsection

@section('js')
    <script>
        // Un solo formulario por entidad, que sirve para crear y para
        // editar: se le cambia la acción y se rellenan los campos. Dos
        // formularios separados acabarían diciendo cosas distintas.
        function abrirFormulario(modalId, url, datos, metodo) {
            const $modal = $(modalId);
            const $form = $modal.find('form');

            $form.attr('action', url);
            $modal.find('input[name="_method"]').val(metodo);

            Object.entries(datos || {}).forEach(([campo, valor]) => {
                const $campo = $form.find(`[name="${campo}"]`);

                if (!$campo.length) return;

                if ($campo.attr('type') === 'checkbox') {
                    $campo.prop('checked', !!valor);
                } else {
                    $campo.val(valor);
                }
            });

            $modal.modal('show');
        }

        $(document).on('click', '.btn-editar-estado', function () {
            abrirFormulario('#modalEstado', $(this).data('url'), $(this).data('estado'), 'PUT');
            $('#modalEstado').find('[name="name"]').prop('readonly', $(this).data('estado').is_system);
        });

        $(document).on('click', '.btn-editar-tipo', function () {
            abrirFormulario('#modalTipo', $(this).data('url'), $(this).data('tipo'), 'PUT');
            $('#modalTipo').find('[name="name"]').prop('readonly', $(this).data('tipo').is_system);
        });

        $(document).on('click', '.btn-editar-detalle', function () {
            abrirFormulario('#modalDetalle', $(this).data('url'), $(this).data('detalle'), 'PUT');
            $('#modalDetalle').find('[name="name"]').prop('readonly', $(this).data('detalle').is_system);
        });

        // Al abrirlos desde el botón «Nuevo» vuelven a ser de creación.
        $('#modalEstado, #modalTipo, #modalDetalle').on('show.bs.modal', function (evento) {
            if (!evento.relatedTarget) return;   // vino de un botón de editar

            const $form = $(this).find('form');

            $form[0].reset();
            $form.attr('action', $form.data('crear'));
            $(this).find('input[name="_method"]').val('POST');
            $(this).find('[name="name"]').prop('readonly', false);
        });
    </script>
@endsection
