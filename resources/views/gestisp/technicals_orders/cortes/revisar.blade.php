{{-- Paso 2: lo que pasaría con cada contrato. Aquí no se ha cortado nada. --}}
@extends('adminlte::page')

@section('title', 'Revisar corte masivo')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-cut mr-2"></i>Revisar el corte</h1>
        <a href="{{ route('technicals_orders.cutoffs') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Cambiar la lista
        </a>
    </div>
@endsection

@section('content')
    @include('gestisp.partials.resultado-accion')

    @php
        $estados = \App\Services\ContractMassCutoff::ESTADOS;
        $aCortar = $resumen['lista'] ?? 0;
    @endphp

    <div class="row">
        @foreach($estados as $clave => [$etiqueta, $color])
            @continue(!($resumen[$clave] ?? 0))
            <div class="col-6 col-md-2">
                <div class="small-box bg-{{ $color }}">
                    <div class="inner">
                        <h3>{{ $resumen[$clave] }}</h3>
                        <p>{{ $etiqueta }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-muted">
        {{ $origen }} · {{ count($filas) }} número(s) · se corta con <strong>{{ $umbral }}</strong> o más facturas vencidas.
        Nada se ha cortado todavía.
    </p>

    @if($aCortar > 0)
        <div class="card card-outline card-danger">
            <div class="card-header"><strong>Confirmar el corte de {{ $aCortar }} contrato(s)</strong></div>
            <form method="POST" action="{{ route('technicals_orders.cutoffs.store') }}"
                  data-procesando="Poniendo los cortes en marcha...">
                @csrf
                <input type="hidden" name="branch_id" value="{{ $branchId }}">
                <input type="hidden" name="origen" value="{{ $origen }}">
                {{-- Van TODOS los números, no solo los que se cortan: el
                     servidor los vuelve a revisar y la tanda guarda la
                     lista entera con el motivo de cada descarte. --}}
                @foreach($numeros as $numero)
                    <input type="hidden" name="numeros[]" value="{{ $numero }}">
                @endforeach
                <div class="card-body">
                    <div class="form-group">
                        <label for="reason">Motivo <span class="text-danger">*</span></label>
                        <input type="text" name="reason" id="reason" class="form-control" maxlength="450" required
                               value="{{ old('reason') }}" placeholder="Ej.: corte de cartera de septiembre">
                        <small class="form-text text-muted">Queda en la orden administrativa de cada contrato.</small>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="confirmar" name="confirmar" value="1" required>
                        <label class="custom-control-label" for="confirmar">
                            Revisé la lista: estos {{ $aCortar }} contrato(s) se suspenden y se quedan sin servicio.
                        </label>
                    </div>
                </div>
                <div class="card-footer text-right">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-cut mr-1"></i> Cortar {{ $aCortar }} contrato(s)
                    </button>
                </div>
            </form>
        </div>
    @else
        <div class="alert alert-info">
            <i class="fas fa-info-circle mr-1"></i> Ningún contrato de la lista se puede cortar. Abajo se dice por qué.
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>Contrato</th>
                        <th>Cliente</th>
                        <th>Estado actual</th>
                        <th class="text-center">Vencidas</th>
                        <th class="text-right">Debe</th>
                        <th>Resultado</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($filas as $fila)
                        @php [$etiqueta, $color] = $estados[$fila['estado']]; @endphp
                        <tr>
                            <td class="text-monospace">
                                @if($fila['contrato_id'])
                                    <a href="{{ route('contracts.show', $fila['contrato_id']) }}" target="_blank" rel="noopener">{{ $fila['numero'] }}</a>
                                @else
                                    {{ $fila['numero'] }}
                                @endif
                            </td>
                            <td>{{ $fila['cliente'] ?? '—' }}</td>
                            <td>{{ $fila['estado_contrato'] ?? '—' }}</td>
                            <td class="text-center">{{ $fila['contrato_id'] ? $fila['vencidas'] : '—' }}</td>
                            <td class="text-right">{{ $fila['contrato_id'] ? '$' . number_format($fila['monto'], 0, ',', '.') : '—' }}</td>
                            <td><span class="badge badge-{{ $color }}">{{ $etiqueta }}</span></td>
                            <td class="small">{{ $fila['mensaje'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
