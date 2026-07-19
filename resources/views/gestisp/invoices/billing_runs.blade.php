@extends('adminlte::page')

@section('title', 'Reportes de Facturación')

@section('content_header')
    <div class="card p-3">
        <h2>REPORTES DE FACTURACIÓN</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Historial de corridas de facturación de la sucursal:
         cada generación con sus conteos y totales (información
         gerencial). Los datos vienen de billing_runs, escritos
         por MonthlyBillingRun en cada generación.
         ============================================================ --}}
    <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted">
                Cada fila es una ejecución de "Generar facturas" con sus totales.
            </span>
            <a href="{{ route('invoices.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver a facturas
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="billingRunsTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Fecha de ejecución</th>
                        <th>Período</th>
                        <th>Contratos</th>
                        <th>Generadas</th>
                        <th>Omitidas</th>
                        <th>Subtotal</th>
                        <th>IVA</th>
                        <th>Total facturado</th>
                        <th>Ejecutado por</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($runs as $run)
                        <tr>
                            <td data-order="{{ $run->executed_at->timestamp }}">{{ $run->executed_at->format('d/m/Y H:i') }}</td>
                            <td>{{ \Carbon\Carbon::createFromFormat('Ym', $run->billed_year_month)->translatedFormat('F Y') }}</td>
                            <td>{{ $run->contracts_count }}</td>
                            <td><span class="badge badge-success">{{ $run->generated_count }}</span></td>
                            <td><span class="badge badge-secondary">{{ $run->skipped_count }}</span></td>
                            <td>${{ number_format($run->total_subtotal, 2) }}</td>
                            <td>${{ number_format($run->total_tax, 2) }}</td>
                            <td><strong>${{ number_format($run->total_billed, 2) }}</strong></td>
                            <td>{{ $run->user->name ?? '—' }} {{ $run->user->last_name ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#billingRunsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Aún no se han ejecutado corridas de facturación.'
                },
                pageLength: 25,
                // Más recientes primero
                order: [[0, 'desc']]
            });
        });
    </script>
@endsection
