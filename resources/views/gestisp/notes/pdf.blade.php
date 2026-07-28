{{-- ============================================================
     Nota crédito / débito (PDF)

     Documento soporte de la corrección de una factura. Lleva los
     datos que exige la normativa: emisor, adquiriente, factura
     afectada, concepto del ajuste, motivo y valores.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => $nota->etiqueta_tipo,
    'pdfSubtitle' => $nota->full_number,
    'branch' => $branch ?? null,
])

@php
    $cliente = $nota->invoice?->contract?->client;
    $contrato = $nota->invoice?->contract;
@endphp

@section('meta')
    <tr>
        <td style="width: 25%">
            <span class="meta-label">Documento</span>
            {{ $nota->full_number }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Fecha de emisión</span>
            {{ $nota->issue_date?->format('d/m/Y') }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Factura afectada</span>
            {{ $nota->invoice?->displayNumber() }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Estado</span>
            {{ $nota->status }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Adquiriente ---------- --}}
    <div class="section-title">Datos del adquiriente</div>
    <table class="detail">
        <tr>
            <td class="label">Nombre</td>
            <td>{{ trim(($cliente?->name ?? '') . ' ' . ($cliente?->last_name ?? '')) ?: '—' }}</td>
            <td class="label">Identificación</td>
            <td>{{ $cliente?->identity_number ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">N.º de contrato</td>
            <td>{{ $contrato?->contract_number ?? '—' }}</td>
            <td class="label">Dirección</td>
            <td>{{ $contrato?->address ?? '—' }}</td>
        </tr>
    </table>

    {{-- ---------- Factura afectada ---------- --}}
    <div class="section-title">Factura que se corrige</div>
    <table class="detail">
        <tr>
            <td class="label">Número de factura</td>
            <td>{{ $nota->invoice?->displayNumber() }}</td>
            <td class="label">Fecha de la factura</td>
            <td>{{ $nota->invoice?->issue_date?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Período facturado</td>
            <td>{{ $nota->invoice?->billed_period ?? '—' }}</td>
            <td class="label">Total facturado</td>
            <td>${{ number_format((float) ($nota->invoice?->total ?? 0), 2) }}</td>
        </tr>
    </table>

    {{-- ---------- Motivo del ajuste ---------- --}}
    <div class="section-title">Concepto del ajuste</div>
    <table class="data">
        <thead>
        <tr>
            <th style="width: 12%">Código</th>
            <th style="width: 45%">Concepto</th>
            <th style="width: 43%">Motivo</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>{{ $nota->concept_code }}</td>
            <td>{{ $nota->concept_label }}</td>
            <td>{{ $nota->reason }}</td>
        </tr>
        </tbody>
    </table>

    {{-- ---------- Valores ---------- --}}
    <div class="section-title">Valores</div>
    <table class="data">
        <thead>
        <tr>
            <th style="width: 70%">Descripción</th>
            <th style="width: 30%" class="text-right">Valor</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>Valor base del ajuste</td>
            <td class="text-right">${{ number_format((float) $nota->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Impuestos</td>
            <td class="text-right">${{ number_format((float) $nota->tax, 2) }}</td>
        </tr>
        </tbody>
        <tfoot>
        <tr>
            <td>TOTAL {{ mb_strtoupper($nota->etiqueta_tipo) }}</td>
            <td class="text-right">${{ number_format((float) $nota->total, 2) }}</td>
        </tr>
        </tfoot>
    </table>

    <div class="note">
        @if($nota->tipo()->disminuye())
            Esta nota crédito <strong>disminuye</strong> el valor a cargo del adquiriente
            en la factura {{ $nota->invoice?->displayNumber() }}.
        @else
            Esta nota débito <strong>aumenta</strong> el valor a cargo del adquiriente
            en la factura {{ $nota->invoice?->displayNumber() }}.
        @endif
        Saldo de la factura al momento de generar este documento:
        <strong>${{ number_format((float) ($nota->invoice?->pending_invoice_amount ?? 0), 2) }}</strong>.

        @unless($nota->vigente)
            <br><br><strong>DOCUMENTO ANULADO</strong> el {{ $nota->voided_at?->format('d/m/Y') }}.
            Motivo: {{ $nota->void_reason }}
        @endunless
    </div>

    <table class="signature-area">
        <tr>
            <td>
                <div class="signature-line">
                    Emitida por<br>
                    {{ $nota->user?->name }} {{ $nota->user?->last_name }}
                </div>
            </td>
            <td>
                <div class="signature-line">Recibido por el adquiriente</div>
            </td>
        </tr>
    </table>

@endsection
