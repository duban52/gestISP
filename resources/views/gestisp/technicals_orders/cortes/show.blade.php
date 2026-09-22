{{-- Lo que pasó con cada contrato de una tanda de corte masivo. --}}
@extends('adminlte::page')

@section('title', 'Corte masivo #' . $corte->id)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-cut mr-2"></i>Corte masivo #{{ $corte->id }}</h1>
        <div>
            <a href="{{ route('technicals_orders.cutoffs.excel', $corte) }}" class="btn btn-outline-success">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <a href="{{ route('technicals_orders.cutoffs.pdf', $corte) }}" class="btn btn-outline-danger" target="_blank" rel="noopener">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <a href="{{ route('technicals_orders.cutoffs') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Cortes masivos
            </a>
        </div>
    </div>
@endsection

@section('content')
    @include('gestisp.partials.resultado-accion')

    @php
        $estados = \App\Models\ContractCutoffItem::ETIQUETAS;
    @endphp

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-2">Fecha</dt>
                <dd class="col-sm-4">{{ $corte->created_at->format('d/m/Y H:i') }}</dd>
                <dt class="col-sm-2">Ordenado por</dt>
                <dd class="col-sm-4">{{ $corte->user?->name ?? '—' }}</dd>
                <dt class="col-sm-2">Sucursal</dt>
                <dd class="col-sm-4">{{ $corte->branch?->name ?? '—' }}</dd>
                <dt class="col-sm-2">Origen</dt>
                <dd class="col-sm-4">{{ $corte->source }}</dd>
                <dt class="col-sm-2">Motivo</dt>
                <dd class="col-sm-4">{{ $corte->reason }}</dd>
                <dt class="col-sm-2">Regla</dt>
                <dd class="col-sm-4">{{ $corte->threshold }} o más facturas vencidas</dd>
            </dl>
        </div>
    </div>

    <div class="row">
        @foreach($estados as $clave => [$etiqueta, $color])
            @continue(!($conteo[$clave] ?? 0))
            <div class="col-6 col-md-2">
                <div class="small-box bg-{{ $color }}">
                    <div class="inner">
                        <h3>{{ $conteo[$clave] }}</h3>
                        <p>{{ $etiqueta }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($enCurso)
        <div class="alert alert-info">
            <span class="spinner-border spinner-border-sm mr-1"></span>
            Se están cortando uno a uno; esta pantalla se actualiza sola cada 10 segundos.
            <small class="d-block">
                Si se queda «En cola» mucho tiempo, el trabajador de la cola no está corriendo en el servidor
                (<code>php artisan queue:work</code>).
            </small>
        </div>
    @endif

    @if(($conteo[\App\Models\ContractCutoffItem::INCOMPLETO] ?? 0) > 0)
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            Hay contratos suspendidos en el sistema a los que algún equipo no les respondió: siguen con servicio.
            Termínelos desde su ficha, o vuelva a subirlos en otra tanda cuando el equipo responda.
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>Contrato</th>
                        <th>Cliente</th>
                        <th class="text-center">Vencidas</th>
                        <th class="text-right">Debía</th>
                        <th>Resultado</th>
                        <th>Detalle</th>
                        <th>Orden</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        @php [$etiqueta, $color] = $estados[$item->status] ?? [$item->status, 'light']; @endphp
                        <tr>
                            <td class="text-monospace">
                                @if($item->contract_id)
                                    <a href="{{ route('contracts.show', $item->contract_id) }}">{{ $item->contract_number }}</a>
                                @else
                                    {{ $item->contract_number }}
                                @endif
                            </td>
                            <td>{{ $item->contract?->client?->fullName() ?? '—' }}</td>
                            <td class="text-center">{{ $item->overdue_count ?? '—' }}</td>
                            <td class="text-right">{{ $item->overdue_amount !== null ? '$' . number_format((float) $item->overdue_amount, 0, ',', '.') : '—' }}</td>
                            <td><span class="badge badge-{{ $color }}">{{ $etiqueta }}</span></td>
                            <td class="small">{{ $item->message }}</td>
                            <td>
                                @if($item->technicalOrder)
                                    <a href="{{ route('technicals_orders.show', $item->technical_order_id) }}">#{{ $item->technical_order_id }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

@if($enCurso)
    @section('js')
        <script>setTimeout(function () { window.location.reload(); }, 10000);</script>
    @endsection
@endif
