@extends('adminlte::page')

@section('title', 'Ceder contrato')
{{-- Para el buscador del nuevo titular. --}}
@section('plugins.Select2', true)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-exchange-alt mr-2"></i>Ceder el contrato {{ $contract->numero_visible }}
        </h1>
        <a href="{{ route('contracts.show', $contract) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver al contrato
        </a>
    </div>
@endsection

@section('content')
    @include('gestisp.partials.resultado-accion')

    <div class="row">
        {{-- ============================ El contrato ============================ --}}
        <div class="col-12 col-lg-5">
            <div class="card card-outline card-primary">
                <div class="card-header py-2"><strong>Lo que se cede</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5">Titular actual</dt>
                        <dd class="col-7">
                            {{ trim(($contract->client?->name ?? '') . ' ' . ($contract->client?->last_name ?? '')) ?: '—' }}
                            <small class="d-block text-muted">{{ $contract->client?->identity_number }}</small>
                        </dd>
                        <dt class="col-5">Estado</dt>
                        <dd class="col-7">{{ $contract->status }}</dd>
                        <dt class="col-5">Plan</dt>
                        <dd class="col-7">{{ $contract->plan?->name ?? '—' }}</dd>
                        <dt class="col-5">Dirección</dt>
                        <dd class="col-7">{{ trim(($contract->address ?? '') . ' ' . ($contract->neighborhood ?? '')) ?: '—' }}</dd>
                        <dt class="col-5">Puerto NAP</dt>
                        <dd class="col-7">{{ $contract->nap_port ?: '—' }}</dd>
                        <dt class="col-5">Usuario PPPoE</dt>
                        <dd class="col-7">{{ $contract->user_pppoe ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            {{-- Lo que va a pasar, dicho ANTES de confirmar: quien cede
                 tiene que saber qué se le va a facturar al cedente. --}}
            <div class="card card-outline card-info">
                <div class="card-header py-2"><strong>Lo que va a pasar</strong></div>
                <div class="card-body">
                    <ul class="mb-0 pl-3">
                        <li>
                            Este contrato quedará <strong>«Cedido»</strong>. Sus facturas, pagos y
                            órdenes siguen a nombre del titular actual.
                        </li>
                        <li>
                            Se crea un <strong>contrato nuevo</strong> para el nuevo titular, con el mismo
                            plan, dirección y ubicación. Nace
                            <strong>«{{ $revision['estado_nuevo'] }}»</strong>.
                        </li>
                        <li>
                            La ONT, la cuenta PPPoE (con sus mismas credenciales) y el puerto NAP pasan al
                            contrato nuevo. <strong>No se toca la red</strong>: el servicio no se corta.
                        </li>
                        @if($revision['factura_del_mes'])
                            <li class="text-primary">
                                Se le factura <strong>al titular actual</strong> el mes en curso, que todavía
                                no tenía facturado.
                            </li>
                        @endif
                        @if($revision['cuotas_pendientes'] > 0)
                            <li class="text-primary">
                                Se le emite <strong>al titular actual</strong> una factura de liquidación con
                                lo que le queda de {{ $revision['cuotas_pendientes'] }} cargo(s) diferido(s).
                            </li>
                        @endif
                        <li>
                            El nuevo titular recibe su primera factura <strong>el mes siguiente</strong>:
                            este mes lo paga quien cede.
                        </li>
                        <li class="text-muted">
                            No se pasa el descuento, si lo había: era una condición del titular actual.
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- ============================ El formulario ============================ --}}
        <div class="col-12 col-lg-7">
            @if($revision['bloqueos'] !== [])
                <div class="alert alert-danger">
                    <h5 class="mb-2"><i class="fas fa-ban mr-1"></i> No se puede ceder todavía</h5>
                    <ul class="mb-0 pl-3">
                        @foreach($revision['bloqueos'] as $bloqueo)
                            <li>{{ $bloqueo }}</li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="card card-outline card-success">
                    <div class="card-header py-2"><strong>El nuevo titular</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('contracts.cession.store', $contract) }}"
                              data-procesando="Cediendo el contrato...">
                            @csrf

                            <div class="form-group">
                                <label for="client_id">Cliente que recibe el contrato <span class="text-danger">*</span></label>
                                <select name="client_id" id="client_id" class="form-control" required></select>
                                <small class="form-text text-muted">
                                    Busque por documento o nombre. Tiene que ser de la misma sucursal.
                                    ¿No existe todavía?
                                    <a href="{{ route('clients.create') }}" target="_blank" rel="noopener">Créelo primero</a>
                                    y vuelva a buscarlo aquí.
                                </small>
                                @error('client_id')<span class="text-danger small">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group">
                                <label for="affinity_group_id">Grupo de afinidad del contrato nuevo</label>
                                <select name="affinity_group_id" id="affinity_group_id" class="form-control">
                                    <option value="">Sin grupo</option>
                                    @foreach($grupos as $grupo)
                                        <option value="{{ $grupo->id }}"
                                            @selected((int) old('affinity_group_id', $contract->affinity_group_id) === $grupo->id)>
                                            {{ $grupo->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">
                                    Decide si sus facturas son electrónicas. Puede no ser el mismo del titular
                                    actual: una empresa suele exigir factura electrónica.
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="reason">Motivo <span class="text-danger">*</span></label>
                                <textarea name="reason" id="reason" rows="3" class="form-control" maxlength="500" required
                                          placeholder="Ej.: el titular se muda y el arrendatario que llega se queda con el servicio.">{{ old('reason') }}</textarea>
                                @error('reason')<span class="text-danger small">{{ $message }}</span>@enderror
                            </div>

                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="confirmar" name="confirmar" value="1" required>
                                    <label class="custom-control-label" for="confirmar">
                                        Leí lo que va a pasar: este contrato se cierra y el titular actual
                                        recibirá sus facturas de cierre.
                                    </label>
                                </div>
                                @error('confirmar')<span class="text-danger small">{{ $message }}</span>@enderror
                            </div>

                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-exchange-alt mr-1"></i> Ceder el contrato
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@section('js')
    <script>
        $(function () {
            $('#client_id').select2({
                width: '100%',
                placeholder: 'Escriba al menos 3 letras del documento o del nombre',
                minimumInputLength: 3,
                language: {
                    inputTooShort: () => 'Escriba al menos 3 caracteres',
                    noResults: () => 'Ningún cliente de esta sucursal coincide',
                    searching: () => 'Buscando…',
                },
                ajax: {
                    url: @json(route('contracts.cession.clients', $contract)),
                    dataType: 'json',
                    delay: 300,
                    data: (params) => ({ q: params.term }),
                    processResults: (datos) => datos,
                },
            });
        });
    </script>
@endsection
