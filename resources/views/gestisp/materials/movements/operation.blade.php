{{-- ============================================================
     El detalle de UNA operación de almacén

     Lo que el historial resume en una fila: qué se movió, de dónde a
     dónde, con qué seriales y con qué factura. De aquí sale el
     comprobante en PDF.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Movimiento n.º ' . $operacion)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-dolly mr-2"></i>Movimiento n.º {{ $operacion }}
        </h1>
        <div>
            @can('movements.pdf')
                <a href="{{ route('movements.operation_pdf', $operacion) }}" class="btn btn-danger"
                   target="_blank" rel="noopener">
                    <i class="far fa-file-pdf"></i> Comprobante en PDF
                </a>
            @endcan
            <a href="{{ route('movements.history') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Historial
            </a>
        </div>
    </div>
@endsection

@section('content')
    @if(session('success-create'))
        <div class="alert alert-success">{{ session('success-create') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-2">Tipo</dt>
                <dd class="col-sm-4">
                    @if($cabecera->type === 'Entrada')
                        <span class="badge badge-success">Entrada</span>
                    @elseif($cabecera->type === 'Salida')
                        <span class="badge badge-danger">Salida</span>
                    @else
                        <span class="badge badge-info">Transferencia</span>
                    @endif
                </dd>
                <dt class="col-sm-2">Fecha</dt>
                <dd class="col-sm-4">{{ $cabecera->created_at->format('d/m/Y H:i') }}</dd>

                <dt class="col-sm-2">Almacén de origen</dt>
                <dd class="col-sm-4">{{ $cabecera->warehouseOrigin->description ?? '—' }}</dd>
                <dt class="col-sm-2">Almacén de destino</dt>
                <dd class="col-sm-4">{{ $cabecera->warehouseDestination->description ?? '—' }}</dd>

                <dt class="col-sm-2">Motivo</dt>
                <dd class="col-sm-4">{{ $cabecera->reason ?: '—' }}</dd>
                <dt class="col-sm-2">Registrado por</dt>
                <dd class="col-sm-4">{{ $cabecera->user->name ?? '—' }} {{ $cabecera->user->last_name ?? '' }}</dd>

                @if($cabecera->supplier || $cabecera->invoice_number || $cabecera->invoice_date)
                    <dt class="col-sm-2">Proveedor</dt>
                    <dd class="col-sm-4">{{ $cabecera->supplier ?: '—' }}</dd>
                    <dt class="col-sm-2">Factura</dt>
                    <dd class="col-sm-4">
                        {{ $cabecera->invoice_number ?: '—' }}
                        @if($cabecera->invoice_date)
                            <span class="text-muted">del {{ $cabecera->invoice_date->format('d/m/Y') }}</span>
                        @endif
                    </dd>
                @endif
            </dl>
        </div>
    </div>

    {{-- Lo que se movió, por material. Los seriales van dentro de cada
         material y plegados: quinientos seriales abiertos no dejan ver
         nada más de la pantalla. --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-boxes mr-1"></i> Qué se movió</h3>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th class="text-right">Cantidad</th>
                        <th>Unidad</th>
                        @if($verCostos)
                            <th class="text-right">Valor unit. de compra</th>
                        @endif
                        <th>Números de serie</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($porMaterial as $materialId => $renglones)
                        @php
                            $primero = $renglones->first();
                            $seriales = $renglones->pluck('serial_number')->filter()->values();
                        @endphp
                        <tr>
                            <td>{{ $primero->material->name ?? '—' }}</td>
                            <td class="text-right">
                                {{ rtrim(rtrim(number_format($renglones->sum('quantity'), 2, ',', '.'), '0'), ',') }}
                            </td>
                            <td>{{ $primero->unit_of_measurement }}</td>
                            @if($verCostos)
                                <td class="text-right">
                                    {{ $primero->purchase_unit_value !== null
                                        ? '$' . number_format($primero->purchase_unit_value, 2, ',', '.')
                                        : '—' }}
                                </td>
                            @endif
                            <td>
                                @if($seriales->isEmpty())
                                    <span class="text-muted">—</span>
                                @else
                                    <details>
                                        <summary>{{ $seriales->count() }} serial(es)</summary>
                                        <div class="text-monospace small mt-1"
                                             style="max-height: 200px; overflow-y: auto;">
                                            {!! $seriales->map(fn ($s) => e($s))->implode('<br>') !!}
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted">
            {{ $movements->count() }} renglón(es) en este movimiento ·
            {{ $porMaterial->count() }} material(es) ·
            {{ $movements->whereNotNull('serial_number')->count() }} serial(es)
        </div>
    </div>
@endsection
