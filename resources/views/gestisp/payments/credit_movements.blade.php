@extends('adminlte::page')

@section('title', 'Saldo a favor')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0"><i class="fas fa-piggy-bank mr-2"></i>Saldo a favor del contrato</h1>
        <a href="{{ route('contracts.show', $contrato) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver al contrato
        </a>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-success"><i class="fas fa-piggy-bank"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Disponible</span>
                    <span class="info-box-number">${{ number_format($saldoAFavor, 2, ',', '.') }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="callout callout-info">
                <p class="mb-0">
                    Contrato <strong>{{ $contrato->numero_visible }}</strong> —
                    {{ $contrato->client?->name }} {{ $contrato->client?->last_name }}.
                    El saldo se consume solo, aplicándose a las facturas a medida que se generan.
                </p>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list mr-1"></i> Movimientos</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Movimiento</th>
                        <th>Origen</th>
                        <th>Detalle</th>
                        <th class="text-right">Valor</th>
                        <th>Registrado por</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($movimientos as $movimiento)
                        <tr>
                            <td class="text-nowrap">{{ $movimiento->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                @if($movimiento->es_entrada)
                                    <span class="badge badge-success">Entrada</span>
                                @else
                                    <span class="badge badge-info">Aplicado</span>
                                @endif
                            </td>
                            <td>{{ $movimiento->origen_legible }}</td>
                            <td>
                                {{ $movimiento->description }}
                                @if($movimiento->invoice)
                                    <a href="{{ route('invoices.show', $movimiento->invoice_id) }}"
                                       class="badge badge-light border ml-1">
                                        {{ $movimiento->invoice->displayNumber() }}
                                    </a>
                                @endif
                                @if($movimiento->note)
                                    <a href="{{ route('notes.show', $movimiento->credit_debit_note_id) }}"
                                       class="badge badge-light border ml-1">
                                        {{ $movimiento->note->full_number }}
                                    </a>
                                @endif
                            </td>
                            <td class="text-right">
                                <strong class="{{ $movimiento->es_entrada ? 'text-success' : 'text-muted' }}">
                                    {{ $movimiento->es_entrada ? '+' : '−' }}${{ number_format((float) $movimiento->amount, 2, ',', '.') }}
                                </strong>
                            </td>
                            <td><small>{{ $movimiento->user?->name }} {{ $movimiento->user?->last_name }}</small></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Este contrato no tiene movimientos de saldo a favor.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@stop
