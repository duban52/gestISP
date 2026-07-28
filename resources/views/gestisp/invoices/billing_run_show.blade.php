@extends('adminlte::page')

@section('title', 'Detalle de la facturación')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-file-invoice-dollar mr-2"></i>
            Facturación de {{ $run->periodo_legible }}
        </h1>
        <a href="{{ route('invoices.billing_runs') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver al listado
        </a>
    </div>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @endif

    {{-- ============================================================
         Ficha de la corrida y descargas
         ============================================================ --}}
    <div class="card card-outline card-primary shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0">
                <i class="fas fa-info-circle mr-1"></i> Corrida N.º {{ $run->id }}
            </h3>
            <div class="btn-group">
                <a href="{{ route('billing_runs.excel', $run) }}" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel mr-1"></i> Excel
                </a>
                <a href="{{ route('billing_runs.csv', $run) }}" class="btn btn-info btn-sm">
                    <i class="fas fa-file-csv mr-1"></i> CSV
                </a>
                <a href="{{ route('billing_runs.pdf', $run) }}" class="btn btn-danger btn-sm">
                    <i class="fas fa-file-pdf mr-1"></i> PDF
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 120px;">Ejecutada</th>
                            <td>{{ $run->executed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Ejecutada por</th>
                            <td>{{ $run->user?->name ?? 'Sistema' }} {{ $run->user?->last_name }}</td>
                        </tr>
                        <tr>
                            <th>Sucursal</th>
                            <td>{{ $run->branch?->name ?? '—' }}</td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-9">
                    <div class="row">
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box mb-2">
                                <span class="info-box-icon bg-primary"><i class="fas fa-file-invoice"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Facturas</span>
                                    <span class="info-box-number">{{ $resumen['facturas'] }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box mb-2">
                                <span class="info-box-icon bg-secondary"><i class="fas fa-users-slash"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Omitidas</span>
                                    <span class="info-box-number">{{ $run->skipped_count }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box mb-2">
                                <span class="info-box-icon bg-success"><i class="fas fa-dollar-sign"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Total facturado</span>
                                    <span class="info-box-number" style="font-size:1.05rem;">
                                        ${{ number_format($resumen['total'], 0, ',', '.') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box mb-2">
                                <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Saldo por cobrar</span>
                                    <span class="info-box-number" style="font-size:1.05rem;">
                                        ${{ number_format($resumen['saldo_pendiente'], 0, ',', '.') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if($run->detalle_deducido)
                <div class="alert alert-info mb-0 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Esta corrida es anterior al registro del detalle, así que las facturas se
                    muestran <strong>agrupadas por sucursal y período</strong>. Las corridas
                    nuevas listan exactamente las facturas que generaron.
                </div>
            @endif
        </div>
    </div>

    {{-- ============================================================
         Facturas generadas
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list mr-1"></i> Facturas generadas</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="runInvoicesTable" class="table table-hover table-sm w-100">
                    <thead class="thead-light">
                    <tr>
                        <th>Factura</th>
                        <th>N.º contrato</th>
                        <th>Identificación</th>
                        <th>Cliente</th>
                        <th>Plan</th>
                        <th>Detalle facturado</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">Impuestos</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Saldo</th>
                        <th>Estado</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($facturas as $factura)
                        @php
                            $cliente = $factura->contract?->client;
                        @endphp
                        <tr>
                            <td><strong>{{ $factura->displayNumber() }}</strong></td>
                            <td>{{ $factura->contract?->contract_number ?? '—' }}</td>
                            <td>{{ $cliente?->identity_number ?? '—' }}</td>
                            <td>{{ trim(($cliente?->name ?? '') . ' ' . ($cliente?->last_name ?? '')) ?: '—' }}</td>
                            <td>{{ $factura->contract?->plan?->name ?? '—' }}</td>
                            <td>
                                {{-- Desglose de lo cobrado: servicios del plan
                                     y cargos adicionales incluidos --}}
                                @forelse($factura->invoice_items as $item)
                                    <div class="small">
                                        {{ $item->description }}
                                        <span class="text-muted">
                                            ${{ number_format((float) $item->total, 0, ',', '.') }}
                                        </span>
                                    </div>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td class="text-right">${{ number_format((float) $factura->subtotal, 0, ',', '.') }}</td>
                            <td class="text-right">${{ number_format((float) $factura->tax, 0, ',', '.') }}</td>
                            <td class="text-right"><strong>${{ number_format((float) $factura->total, 0, ',', '.') }}</strong></td>
                            <td class="text-right">${{ number_format((float) $factura->pending_invoice_amount, 0, ',', '.') }}</td>
                            <td>
                                @php
                                    $color = match(true) {
                                        str_contains(strtolower($factura->status), 'pagad') => 'success',
                                        str_contains(strtolower($factura->status), 'vencid') => 'danger',
                                        str_contains(strtolower($factura->status), 'anul') => 'secondary',
                                        str_contains(strtolower($factura->status), 'riesgo') => 'warning',
                                        default => 'info',
                                    };
                                @endphp
                                <span class="badge badge-{{ $color }}">{{ $factura->status }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr class="bg-light">
                        <th colspan="6" class="text-right">Totales</th>
                        <th class="text-right">${{ number_format($resumen['subtotal'], 0, ',', '.') }}</th>
                        <th class="text-right">${{ number_format($resumen['impuestos'], 0, ',', '.') }}</th>
                        <th class="text-right">${{ number_format($resumen['total'], 0, ',', '.') }}</th>
                        <th class="text-right">${{ number_format($resumen['saldo_pendiente'], 0, ',', '.') }}</th>
                        <th></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
@stop

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@stop

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(function () {
            $('#runInvoicesTable').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
                pageLength: 25,
                order: [[0, 'asc']],
                columnDefs: [{ defaultContent: '—', targets: '_all' }],
            });
        });
    </script>
@stop
