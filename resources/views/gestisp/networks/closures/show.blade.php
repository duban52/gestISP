{{-- ============================================================
     Ficha de una mufla

     Es la pantalla que se abre ANTES de subir al poste. Por eso lo
     primero que se ve no es el inventario sino el impacto: a quién se
     deja sin servicio mientras la mufla esté abierta. Ese dato es el
     que decide si el trabajo se hace ahora o a las tres de la mañana.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Mufla ' . $mufla->code)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-box-open mr-2"></i>{{ $mufla->code }}
            @if($mufla->name)
                <small class="text-muted">— {{ $mufla->name }}</small>
            @endif
        </h1>
        <div>
            <a href="{{ route('closures.edit', $mufla) }}" class="btn btn-outline-primary">
                <i class="fas fa-cog"></i> Editar
            </a>
            <a href="{{ route('closures.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    {{-- ============================================================
         Impacto: lo primero, porque es lo que decide cuándo se trabaja
         ============================================================ --}}
    <div class="card card-outline card-{{ $impacto['total_clientes'] > 0 ? 'danger' : 'success' }} shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Si se abre esta mufla…
            </h3>
        </div>
        <div class="card-body py-3">
            @if($impacto['total_clientes'] === 0 && $impacto['total_cajas'] === 0)
                <p class="text-muted mb-0">
                    <i class="fas fa-check-circle text-success"></i>
                    Ningún cliente depende de esta mufla ahora mismo.
                    @if($mufla->splices->isEmpty())
                        Todavía no tiene fusiones registradas.
                    @endif
                </p>
            @else
                <div class="row align-items-center">
                    <div class="col-md-3 text-center">
                        <div class="h1 mb-0 text-danger">{{ $impacto['total_clientes'] }}</div>
                        <div class="text-muted text-uppercase small">clientes sin servicio</div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="h1 mb-0">{{ $impacto['total_cajas'] }}</div>
                        <div class="text-muted text-uppercase small">
                            de {{ $impacto['cajas_en_la_red'] }} cajas de la red
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex flex-wrap">
                            @foreach($impacto['cajas'] as $caja)
                                <a href="{{ $caja['url'] }}" class="btn btn-sm btn-outline-danger m-1">
                                    {{ $caja['codigo'] }}
                                    <span class="badge badge-light border ml-1">{{ $caja['clientes'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                @if($impacto['total_clientes'] > 0)
                    <hr>
                    <a data-toggle="collapse" href="#listaAfectados" role="button" class="small">
                        <i class="fas fa-caret-down"></i> Ver los clientes afectados
                    </a>
                    <div class="collapse mt-2" id="listaAfectados">
                        <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                            <table class="table table-sm mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>Contrato</th>
                                    <th>Cliente</th>
                                    <th>Teléfono</th>
                                    <th>Dirección</th>
                                    <th>Caja</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($impacto['contratos'] as $contrato)
                                    <tr>
                                        <td><a href="{{ route('contracts.show', $contrato['id']) }}">{{ $contrato['numero'] }}</a></td>
                                        <td>{{ $contrato['cliente'] ?? '—' }}</td>
                                        <td>{{ $contrato['telefono'] ?? '—' }}</td>
                                        <td><small>{{ $contrato['direccion'] }}</small></td>
                                        <td><small>{{ $contrato['caja'] }} / P{{ $contrato['puerto'] }}</small></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endif
        </div>
        <div class="card-footer py-2 text-muted small">
            Se calcula quitando esta mufla de la red y viendo qué cajas dejan de tener camino
            hasta una OLT. Si una caja llega por otra ruta, no aparece aquí.
        </div>
    </div>

    <div class="row">
        {{-- ---------- Datos ---------- --}}
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-info-circle mr-1"></i> Datos</h3>
                </div>
                <div class="card-body py-2">
                    <table class="table table-sm table-borderless mb-2">
                        <tr>
                            <td class="text-muted" style="width: 40%;">Red</td>
                            <td><a href="{{ route('networks.show', $mufla->network) }}">{{ $mufla->network->name }}</a></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Zona</td>
                            <td>
                                @if($mufla->zone)
                                    <span class="badge" style="background: {{ $mufla->zone->color }}; color:#fff;">
                                        {{ $mufla->zone->name }}
                                    </span>
                                @else
                                    <span class="text-muted">Sin zona</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Montaje</td>
                            <td>{{ $mufla->tipo_legible }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Dirección</td>
                            <td>{{ $mufla->address }}</td>
                        </tr>
                        @if($mufla->reference)
                            <tr>
                                <td class="text-muted">Referencia</td>
                                <td>{{ $mufla->reference }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="text-muted">Estado</td>
                            <td>
                                <span class="badge badge-{{ $mufla->status === 'operativa' ? 'success' : 'warning' }}">
                                    {{ $mufla->estado_legible }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Registrada por</td>
                            <td><small>{{ $mufla->user?->name }} {{ $mufla->user?->last_name }}</small></td>
                        </tr>
                    </table>

                    <div class="text-muted small mb-1">
                        Ocupación: {{ $ocupacion['usadas'] }} de {{ $ocupacion['capacidad'] }} fusiones
                        ({{ $mufla->tray_count }} bandeja(s) × {{ $mufla->splices_per_tray }})
                    </div>
                    <div class="progress" style="height: 22px;">
                        <div class="progress-bar bg-{{ $mufla->color_ocupacion }}"
                             style="width: {{ min($ocupacion['porcentaje'], 100) }}%;">
                            {{ $ocupacion['porcentaje'] }}%
                        </div>
                    </div>

                    @if($mufla->notes)
                        <div class="alert alert-light border mt-3 mb-0 py-2 small">{{ $mufla->notes }}</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ---------- Cables que llegan ---------- --}}
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0"><i class="fas fa-grip-lines mr-1"></i> Cables que llegan</h3>
                    <a href="{{ route('cables.create', ['network_id' => $mufla->optical_network_id]) }}"
                       class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus"></i> Registrar cable
                    </a>
                </div>
                <div class="card-body py-2">
                    @forelse($cables as $cable)
                        @php
                            $oc = $cable->ocupacion();
                        @endphp
                        <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->first ? 'border-top' : '' }}">
                            <span>
                                <a href="{{ route('cables.show', $cable) }}" class="font-weight-bold">{{ $cable->code }}</a>
                                <span class="badge badge-light border ml-1">{{ $cable->tipo_legible }}</span>
                                <small class="d-block text-muted">
                                    {{ $cable->capacidad_legible }} ·
                                    {{ $cable->desde_legible }} → {{ $cable->hasta_legible }}
                                </small>
                            </span>
                            <span class="text-right">
                                <span class="badge badge-{{ $cable->color_ocupacion }}">
                                    {{ $oc['en_uso'] }}/{{ $oc['capacidad'] }}
                                </span>
                                <small class="d-block text-muted">{{ $oc['libres'] }} libres</small>
                            </span>
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">
                            Ningún cable llega a esta mufla todavía.<br>
                            <small>Registre los cables indicando esta mufla como uno de sus extremos.</small>
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Fusiones
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0">
                <i class="fas fa-link mr-1"></i> Fusiones
                <span class="badge badge-secondary ml-1">{{ $mufla->splices->count() }}</span>
            </h3>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th class="text-center" style="width: 70px;">Bandeja</th>
                        <th>Hilo A</th>
                        <th>Hilo B</th>
                        <th class="text-center">Tipo</th>
                        <th class="text-center">Atenuación</th>
                        <th class="text-center" style="width: 60px;"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($mufla->splices as $fusion)
                        <tr>
                            <td class="text-center">
                                {{ $fusion->tray ? 'B' . $fusion->tray : '—' }}
                                @if($fusion->position)
                                    <small class="d-block text-muted">pos. {{ $fusion->position }}</small>
                                @endif
                            </td>
                            @foreach([$fusion->strandA, $fusion->strandB] as $hilo)
                                <td>
                                    <a href="{{ route('cables.show', $hilo->fiber_cable_id) }}">
                                        <strong>{{ $hilo->cable->code }}</strong>
                                    </a>
                                    <span class="d-block">
                                        <span class="badge" style="background: {{ $hilo->buffer_hex }}; color: {{ \App\Support\FiberColors::textoSobre($hilo->buffer_color) }};">
                                            B{{ $hilo->buffer_number }} {{ $hilo->buffer_color }}
                                        </span>
                                        <span class="badge" style="background: {{ $hilo->color_hex }}; color: {{ \App\Support\FiberColors::textoSobre($hilo->strand_color) }};">
                                            H{{ $hilo->strand_number }} {{ $hilo->strand_color }}
                                        </span>
                                    </span>
                                </td>
                            @endforeach
                            <td class="text-center"><small>{{ $fusion->tipo_legible }}</small></td>
                            <td class="text-center">
                                @if($fusion->loss_db !== null)
                                    <span class="badge badge-{{ $fusion->color_calidad }}">
                                        {{ number_format((float) $fusion->loss_db, 2) }} dB
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <form method="POST" action="{{ route('splices.destroy', $fusion) }}"
                                      onsubmit="return confirm('¿Deshacer esta fusión? Los dos hilos quedarán sueltos.');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-link text-danger p-0" title="Deshacer">
                                        <i class="fas fa-unlink"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Todavía no hay fusiones registradas en esta mufla.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ---------- Registrar una fusión ---------- --}}
        <div class="card-footer">
            @if($hilosDisponibles->isEmpty())
                <p class="text-muted mb-0 small">
                    <i class="fas fa-info-circle"></i>
                    No hay hilos disponibles para fusionar. Solo se ofrecen los de los cables
                    que llegan a esta mufla y que aún tienen un extremo suelto.
                </p>
            @else
                <form method="POST" action="{{ route('splices.store', $mufla) }}">
                    @csrf
                    <div class="row align-items-end">
                        <div class="col-md-3 form-group mb-2">
                            <label class="mb-1 small">Hilo A</label>
                            <select name="strand_a_id" class="form-control form-control-sm" required>
                                <option value="">Elija…</option>
                                @foreach($hilosDisponibles as $hilo)
                                    <option value="{{ $hilo->id }}">{{ $hilo->etiqueta_completa }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 form-group mb-2">
                            <label class="mb-1 small">Hilo B</label>
                            <select name="strand_b_id" class="form-control form-control-sm" required>
                                <option value="">Elija…</option>
                                @foreach($hilosDisponibles as $hilo)
                                    <option value="{{ $hilo->id }}">{{ $hilo->etiqueta_completa }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 form-group mb-2">
                            <label class="mb-1 small">Bandeja</label>
                            <input type="number" name="tray" class="form-control form-control-sm"
                                   min="1" max="{{ $mufla->tray_count }}">
                        </div>
                        <div class="col-md-2 form-group mb-2">
                            <label class="mb-1 small">Atenuación (dB)</label>
                            <input type="number" name="loss_db" class="form-control form-control-sm"
                                   step="0.01" min="0" max="20" placeholder="0.05">
                        </div>
                        <div class="col-md-2 form-group mb-2">
                            <button class="btn btn-sm btn-primary btn-block">
                                <i class="fas fa-link"></i> Fusionar
                            </button>
                        </div>
                    </div>
                    <small class="text-muted">
                        Una fusión bien hecha queda por debajo de 0,1 dB; por encima de 0,3 conviene rehacerla.
                    </small>
                </form>
            @endif
        </div>
    </div>

    {{-- ============================================================
         Splitters
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h3 class="card-title mb-0">
                <i class="fas fa-code-branch mr-1"></i> Splitters
                <span class="badge badge-secondary ml-1">{{ $mufla->splitters->count() }}</span>
            </h3>
        </div>

        <div class="card-body">
            @forelse($mufla->splitters as $splitter)
                <div class="{{ !$loop->first ? 'border-top pt-3 mt-3' : '' }}">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                        <h6 class="mb-0">
                            <span class="badge badge-info">{{ $splitter->ratio }}</span>
                            {{ $splitter->code ?: 'Sin código' }}
                            <small class="text-muted ml-2">
                                Entrada:
                                {{ $splitter->inputStrand?->etiqueta_completa ?? 'sin conectar' }}
                            </small>
                        </h6>
                        <small class="text-muted">
                            {{ $splitter->salidasUsadas() }} de {{ $splitter->output_count }} salidas en uso ·
                            pérdida ≈ {{ $splitter->perdida }} dB

                            <form method="POST" action="{{ route('splitters.destroy', $splitter) }}"
                                  class="d-inline ml-2"
                                  onsubmit="return confirm('¿Desmontar este splitter {{ $splitter->ratio }}?{{ $splitter->salidasUsadas() > 0 ? ' Tiene ' . $splitter->salidasUsadas() . ' salida(s) conectadas: esos hilos quedarán sueltos y las cajas que colgaban de ellos se quedarán sin camino.' : '' }}');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-link text-danger p-0" title="Desmontar splitter">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </small>
                    </div>

                    <div class="row">
                        @foreach($splitter->outputs as $salida)
                            <div class="col-md-3 mb-2">
                                <form method="POST" action="{{ route('splitters.output.connect', $salida) }}"
                                      class="input-group input-group-sm">
                                    @csrf @method('PUT')
                                    <div class="input-group-prepend">
                                        <span class="input-group-text {{ $salida->estaConectada() ? 'bg-primary text-white' : '' }}">
                                            {{ $salida->number }}
                                        </span>
                                    </div>
                                    <select name="strand_id" class="form-control form-control-sm">
                                        <option value="">Libre</option>
                                        @if($salida->strand)
                                            <option value="{{ $salida->strand->id }}" selected>
                                                {{ $salida->strand->etiqueta_completa }}
                                            </option>
                                        @endif
                                        @foreach($hilosDisponibles as $hilo)
                                            <option value="{{ $hilo->id }}">{{ $hilo->etiqueta_completa }}</option>
                                        @endforeach
                                    </select>
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" title="Guardar">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-muted text-center py-3 mb-0">
                    Esta mufla no tiene splitters. Se usan cuando de un hilo hay que repartir a varios.
                </p>
            @endforelse
        </div>

        <div class="card-footer">
            <form method="POST" action="{{ route('splitters.store', $mufla) }}">
                @csrf
                <div class="row align-items-end">
                    <div class="col-md-2 form-group mb-2">
                        <label class="mb-1 small">Reparto</label>
                        <select name="ratio" class="form-control form-control-sm" required>
                            @foreach(\App\Models\Splitter::ratios() as $clave => $texto)
                                <option value="{{ $clave }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 form-group mb-2">
                        <label class="mb-1 small">Código</label>
                        <input type="text" name="code" class="form-control form-control-sm" maxlength="30">
                    </div>
                    <div class="col-md-4 form-group mb-2">
                        <label class="mb-1 small">Hilo de entrada</label>
                        <select name="input_strand_id" class="form-control form-control-sm">
                            <option value="">Sin conectar todavía</option>
                            @foreach($hilosDisponibles as $hilo)
                                <option value="{{ $hilo->id }}">{{ $hilo->etiqueta_completa }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 form-group mb-2">
                        <label class="mb-1 small">Bandeja</label>
                        <input type="number" name="tray" class="form-control form-control-sm"
                               min="1" max="{{ $mufla->tray_count }}">
                    </div>
                    <div class="col-md-2 form-group mb-2">
                        <button class="btn btn-sm btn-primary btn-block">
                            <i class="fas fa-plus"></i> Montar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
