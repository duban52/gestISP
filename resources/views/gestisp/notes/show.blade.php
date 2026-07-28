@extends('adminlte::page')

@section('title', 'Nota ' . $nota->full_number)

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-file-invoice mr-2"></i>{{ $nota->etiqueta_tipo }} {{ $nota->full_number }}
        </h1>
        <div>
            <a href="{{ route('notes.pdf', $nota) }}" class="btn btn-danger" target="_blank">
                <i class="fas fa-file-pdf mr-1"></i> PDF
            </a>
            <a href="{{ route('notes.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-1"></i> Volver
            </a>
        </div>
    </div>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('error') }}
        </div>
    @endif

    @unless($nota->vigente)
        <div class="alert alert-secondary shadow-sm">
            <h5 class="mb-1"><i class="fas fa-ban mr-1"></i> Nota anulada</h5>
            Anulada el {{ $nota->voided_at?->format('d/m/Y H:i') }}
            por {{ $nota->voidedBy?->name }} {{ $nota->voidedBy?->last_name }}.
            <div class="mt-1"><strong>Motivo:</strong> {{ $nota->void_reason }}</div>
            <div class="mt-1 text-muted">Su efecto sobre la factura fue revertido.</div>
        </div>
    @endunless

    <div class="row">
        <div class="col-lg-7">
            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-info-circle mr-1"></i> Datos de la nota</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 38%">Tipo</th>
                            <td>
                                <span class="badge badge-{{ $nota->tipo()->disminuye() ? 'success' : 'warning' }}">
                                    {{ $nota->etiqueta_tipo }}
                                </span>
                                <small class="text-muted ml-1">
                                    {{ $nota->tipo()->disminuye() ? 'disminuye el saldo' : 'aumenta el saldo' }}
                                </small>
                            </td>
                        </tr>
                        <tr>
                            <th>Número</th>
                            <td><strong>{{ $nota->full_number }}</strong></td>
                        </tr>
                        <tr>
                            <th>Fecha de emisión</th>
                            <td>{{ $nota->issue_date?->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <th>Concepto (DIAN)</th>
                            <td>{{ $nota->concept_code }} — {{ $nota->concept_label }}</td>
                        </tr>
                        <tr>
                            <th>Motivo</th>
                            <td style="white-space: pre-line;">{{ $nota->reason }}</td>
                        </tr>
                        <tr>
                            <th>Emitida por</th>
                            <td>{{ $nota->user?->name }} {{ $nota->user?->last_name }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-calculator mr-1"></i> Valores</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 38%">Valor base</th>
                            <td class="text-right">${{ number_format((float) $nota->subtotal, 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Impuestos</th>
                            <td class="text-right">${{ number_format((float) $nota->tax, 2, ',', '.') }}</td>
                        </tr>
                        <tr class="bg-light">
                            <th>Total de la nota</th>
                            <td class="text-right">
                                <strong class="{{ $nota->tipo()->disminuye() ? 'text-success' : 'text-warning' }}">
                                    {{ $nota->tipo()->disminuye() ? '−' : '+' }}${{ number_format((float) $nota->total, 2, ',', '.') }}
                                </strong>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card card-outline card-secondary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-receipt mr-1"></i> Factura afectada</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 45%">Factura</th>
                            <td>
                                <a href="{{ route('invoices.show', $nota->invoice_id) }}">
                                    {{ $nota->invoice?->displayNumber() }}
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <th>Cliente</th>
                            <td>{{ $nota->invoice?->contract?->client?->name }} {{ $nota->invoice?->contract?->client?->last_name }}</td>
                        </tr>
                        <tr>
                            <th>Identificación</th>
                            <td>{{ $nota->invoice?->contract?->client?->identity_number ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>N.º de contrato</th>
                            <td>{{ $nota->invoice?->contract?->contract_number ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Total facturado</th>
                            <td>${{ number_format((float) ($nota->invoice?->total ?? 0), 2, ',', '.') }}</td>
                        </tr>
                        <tr class="bg-light">
                            <th>Saldo actual</th>
                            <td><strong>${{ number_format((float) ($nota->invoice?->pending_invoice_amount ?? 0), 2, ',', '.') }}</strong></td>
                        </tr>
                    </table>
                </div>
            </div>

            @if($nota->vigente)
                <div class="card card-outline card-danger shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-ban mr-1"></i> Anular la nota</h3>
                    </div>
                    <form action="{{ route('notes.void', $nota) }}" method="POST"
                          onsubmit="return confirm('¿Anular la nota? Su efecto sobre el saldo de la factura se revertirá.');">
                        @csrf
                        <div class="card-body">
                            <p class="text-muted">
                                Anularla revierte su efecto: el saldo de la factura vuelve a como estaba.
                                La nota no se borra, queda marcada como anulada.
                            </p>
                            <div class="form-group mb-0">
                                <label for="void_reason">Motivo de la anulación</label>
                                <textarea name="void_reason" id="void_reason" rows="2" class="form-control"
                                          minlength="10" required placeholder="Explique por qué se anula."></textarea>
                            </div>
                        </div>
                        <div class="card-footer text-right">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fas fa-ban mr-1"></i> Anular nota
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
@stop
