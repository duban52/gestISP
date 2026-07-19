@extends('adminlte::page')

@section('title', 'Detalles de contrato')

@section('content_header')
    <h4 class="text-center">DETALLES DE FACTURA</h4>
@endsection

@section('content')



    <div class="row d-flex justify-content-center">

        <div class="card col-md-10 d-flex justify-content-end pl-3 pt-3 pb-3 pr-3 mt-3">
            <div>
                <a href="{{ route('invoices.download-pdf', $invoice->id) }}" title="Descargar PDF" class="btn btn-danger">
                    <i class="far fa-file-pdf"></i>
                </a>
            </div>

        </div>
        <div class="card col-md-5 ml-md-1">
            <div class="card-header">
                <h3><i class="far fa-user"></i> DATOS DEL SUSCRIPTOR</h3>
            </div>
            <div class="card-body row">
                <p class="col-6"><strong>C.C/NIT:</strong> {{ $invoice->contract->client->identity_number }}</p>
                <p class="col-6"><strong>SUSCRIPTOR:</strong> {{ $invoice->contract->client->name }} {{ $invoice->contract->client->last_name }}</p>
                <p class="col-6"><strong>DIRECCIÓN:</strong> {{ $invoice->contract->address }}</p>
                <p class="col-6"><strong>BARRIO:</strong> {{ $invoice->contract->neighborhood }}</p>
                <p class="col-6"><strong>MUNICIPIO:</strong> {{ $invoice->contract->neighborhood }}</p>
                <p class="col-6"><strong>CODIGO:</strong> {{ $invoice->contract->id}}</p>
                <p class="col-6"><strong>CORREO:</strong> {{ $invoice->contract->client->email }}</p>
                <p class="col-6"><strong>TELÉFONO:</strong> {{ $invoice->contract->client->number_phone}}</p>
            </div>
        </div>

        <div class="card col-md-5 ml-md-3">
            <div class="card-header">
                <h3><i class="fas fa-network-wired"></i> DATOS DE SERVICIO</h3>
            </div>
            <div class="card-body row">
                <p class="col-6"><strong>CÓDIGO:</strong></p>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <tr>
                            <th>CÓDIGO</th>
                            <th>DESCRIPCIÓN DEL SERVICIO</th>
                            <th>CANTIDAD</th>
                            <th>PRECIO UNIT.</th>
                            <th>IVA 19%</th>
                            <th>TOTAL</th>
                        </tr>
                        @foreach($invoice->invoice_items as $item)
                            <tr>
                                <td>{{ $item->id }}</td>
                                <td>{{ $item->description }}</td>
                                <td>{{ $item->quantity }}</td>
                                <td>{{ $item->unit_price }}</td>
                                <td>{{ $item->tax }}</td>
                                <td>{{ $item->total }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>

            </div>
        </div>

        <div class="card col-md-10 ml-md-1">
            <div class="card-header">
                <h3><i class="fas fa-file-invoice-dollar"></i> DATOS DE LA FACTURA</h3>
            </div>
            <div class="card-body row">
                <p class="col-6"><strong>NÚMERO:</strong> {{ $invoice->displayNumber() }}</p>
                <p class="col-6"><strong>TIPO:</strong> {{ $invoice->type }}</p>
                <p class="col-6"><strong>SUBTOTAL:</strong> {{ number_format($invoice->subtotal, 2) }}</p>
                <p class="col-6"><strong>IVA:</strong> {{ number_format($invoice->tax, 2) }}</p>
                <p class="col-6"><strong>TOTAL:</strong> {{ number_format($invoice->total, 2) }}</p>
                {{-- Saldo real de ESTA factura (total − pagos);
                     la deuda acumulada del contrato se lee en su
                     estado de cuenta, no aquí --}}
                <p class="col-6"><strong>SALDO PENDIENTE:</strong> {{ number_format($invoice->pending_invoice_amount, 2) }}</p>
                <p class="col-6"><strong>PERIODO FACTURADO:</strong> Del {{ $invoice->billed_period_short }} del mes de {{ $invoice->billed_month_name }}</p>
                <p class="col-6"><strong>FECHA DE GENERACIÓN:</strong> {{ $invoice->issue_date->format('d/m/Y') }}</p>
                <p class="col-6"><strong>FECHA DE VENCIMIENTO:</strong> {{ $invoice->due_date->format('d/m/Y') }}</p>
                <p class="col-6"><strong>ESTADO DE LA FACTURA:</strong> {{ $invoice->status}}</p>
                <p class="col-6"><strong>FACTURAS VENCIDAS DEL CONTRATO:</strong> {{ $invoice->contract->overdue_invoices_count }}</p>
                @if($invoice->status === \App\Billing\Enums\InvoiceStatus::Anulada->value)
                    <p class="col-12 text-danger">
                        <strong>ANULADA:</strong> {{ $invoice->voided_at?->format('d/m/Y H:i') }}
                        por {{ $invoice->voidedBy->name ?? '—' }} — {{ $invoice->void_reason }}
                    </p>
                @endif
            </div>
        </div>


        </div>

    </div>

@endsection

