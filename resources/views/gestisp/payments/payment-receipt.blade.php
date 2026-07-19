{{-- ============================================================
     Recibo de caja (comprobante de pago)

     Documento que se entrega al cliente como soporte del pago
     recibido: identifica el pago, la factura afectada y el saldo
     que queda pendiente después del abono.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Recibo de caja',
    'pdfSubtitle' => 'Comprobante N.° ' . $payment->id,
    'branch' => $payment->invoice?->contract?->branch ?? null,
])

@php
    $invoice = $payment->invoice;
    $client = $invoice?->contract?->client;
    // Saldo de la factura DESPUÉS de aplicar este pago
    $pendingAfter = $invoice ? $invoice->getPendingAmount() : 0;
@endphp

@section('meta')
    <tr>
        <td style="width: 25%">
            <span class="meta-label">Recibo N.°</span>
            {{ $payment->id }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Fecha del pago</span>
            {{ $payment->payment_date?->format('d/m/Y') ?? now()->format('d/m/Y') }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Método de pago</span>
            {{ ucfirst($payment->payment_method) }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Recibido por</span>
            {{ $payment->user->name ?? '—' }} {{ $payment->user->last_name ?? '' }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Valor recibido (dato principal) ---------- --}}
    <table class="summary">
        <tr>
            <td colspan="2" style="width: 50%; background-color: #F1F5FA;">
                <span class="summary-label">Valor recibido</span>
                <span class="summary-value" style="font-size: 20px;">
                    ${{ number_format($payment->amount, 2) }}
                </span>
            </td>
            <td style="width: 25%">
                <span class="summary-label">Total de la factura</span>
                <span class="summary-value">${{ number_format($invoice->total ?? 0, 2) }}</span>
            </td>
            <td style="width: 25%">
                <span class="summary-label">Saldo pendiente</span>
                <span class="summary-value {{ $pendingAfter > 0 ? 'negative' : 'positive' }}">
                    ${{ number_format($pendingAfter, 2) }}
                </span>
            </td>
        </tr>
    </table>

    {{-- ---------- Datos del cliente ---------- --}}
    <div class="section-title">Datos del cliente</div>

    <table class="detail">
        <tr>
            <td class="label">Cliente</td>
            <td>{{ $client->name ?? '—' }} {{ $client->last_name ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Documento</td>
            <td>{{ $client->type_document ?? '' }} {{ $client->identity_number ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Contrato</td>
            <td>
                N.° {{ $invoice->contract_id ?? '—' }}
                @if($invoice?->contract?->address)
                    — {{ $invoice->contract->address }}
                    @if($invoice->contract->neighborhood)({{ $invoice->contract->neighborhood }})@endif
                @endif
            </td>
        </tr>
        @if($client?->number_phone)
            <tr>
                <td class="label">Teléfono</td>
                <td>{{ $client->number_phone }}</td>
            </tr>
        @endif
    </table>

    {{-- ---------- Factura afectada ---------- --}}
    <div class="section-title">Factura cancelada con este pago</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 18%">Factura</th>
            <th style="width: 24%">Período facturado</th>
            <th style="width: 16%">Vencimiento</th>
            <th style="width: 14%" class="text-right">Total</th>
            <th style="width: 14%" class="text-right">Abonado</th>
            <th style="width: 14%" class="text-right">Saldo</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="strong">{{ $invoice?->displayNumber() ?? '—' }}</td>
            <td>
                {{ $invoice->billed_period_short ?? '' }}
                {{ $invoice->billed_month_name ?? '' }}
            </td>
            <td>{{ $invoice?->due_date?->format('d/m/Y') ?? '—' }}</td>
            <td class="text-right">${{ number_format($invoice->total ?? 0, 2) }}</td>
            <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
            <td class="text-right">${{ number_format($pendingAfter, 2) }}</td>
        </tr>
        </tbody>
    </table>

    {{-- ---------- Detalles adicionales del pago ---------- --}}
    @if($payment->reference_number || $payment->notes)
        <div class="section-title">Información adicional</div>
        <table class="detail">
            @if($payment->reference_number)
                <tr>
                    <td class="label">N.° de referencia</td>
                    <td>{{ $payment->reference_number }}</td>
                </tr>
            @endif
            @if($payment->notes)
                <tr>
                    <td class="label">Notas</td>
                    <td>{{ $payment->notes }}</td>
                </tr>
            @endif
        </table>
    @endif

    <div class="note">
        @if($pendingAfter > 0)
            <strong>Este pago es un abono parcial.</strong>
            La factura {{ $invoice?->displayNumber() }} conserva un saldo pendiente de
            <strong>${{ number_format($pendingAfter, 2) }}</strong>.
        @else
            <strong>Factura cancelada en su totalidad.</strong>
            No queda saldo pendiente por este concepto.
        @endif
        <br>
        Este documento es el comprobante válido del pago recibido. Consérvelo para cualquier reclamación.
    </div>

    {{-- ---------- Firmas ---------- --}}
    <table class="signature-area">
        <tr>
            <td>
                <div class="signature-line">
                    {{ $payment->user->name ?? '' }} {{ $payment->user->last_name ?? '' }}<br>
                    Recibí conforme (cajero)
                </div>
            </td>
            <td>
                <div class="signature-line">
                    {{ $client->name ?? '' }} {{ $client->last_name ?? '' }}<br>
                    Firma del cliente
                </div>
            </td>
        </tr>
    </table>

@endsection
