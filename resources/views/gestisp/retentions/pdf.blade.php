{{-- ============================================================
     Reporte de retenciones practicadas

     Soporte para descontar en la declaración los impuestos que los
     clientes retuvieron y consignaron al Estado a nombre nuestro.
     Lleva base, tarifa y número de certificado porque sin esos tres
     datos la retención no se puede cruzar ni descontar.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Retenciones practicadas',
    'orientation' => 'landscape',
])

@section('meta')
    <tr>
        <td style="width: 30%">
            <span class="meta-label">Período</span>
            {{ \Carbon\Carbon::parse($desde)->format('d/m/Y') }}
            al {{ \Carbon\Carbon::parse($hasta)->format('d/m/Y') }}
        </td>
        <td style="width: 20%">
            <span class="meta-label">Registros</span>
            {{ $retenciones->count() }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Contratos afectados</span>
            {{ $retenciones->pluck('contract_id')->filter()->unique()->count() }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Total retenido</span>
            <span class="strong">${{ number_format($total, 2) }}</span>
        </td>
    </tr>
@endsection

@section('content')

    {{-- ---------- Totales por tipo ---------- --}}
    @if(!empty($totalesPorTipo))
        <div class="section-title">Resumen por tipo de retención</div>

        <table class="data">
            <thead>
            <tr>
                <th style="width: 60%">Tipo</th>
                <th style="width: 20%" class="text-right">Registros</th>
                <th style="width: 20%" class="text-right">Total retenido</th>
            </tr>
            </thead>
            <tbody>
            @foreach($totalesPorTipo as $datos)
                <tr>
                    <td>{{ $datos['label'] }}</td>
                    <td class="text-right">{{ $datos['count'] }}</td>
                    <td class="text-right strong">${{ number_format($datos['total'], 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    {{-- ---------- Detalle ---------- --}}
    <div class="section-title">Detalle</div>

    <table class="data">
        <thead>
        <tr>
            <th style="width: 8%">Fecha</th>
            <th style="width: 8%">Tipo</th>
            <th style="width: 17%">Concepto</th>
            <th style="width: 10%">Contrato</th>
            <th style="width: 17%">Cliente</th>
            <th style="width: 9%">Factura</th>
            <th style="width: 10%" class="text-right">Base</th>
            <th style="width: 6%" class="text-right">Tarifa</th>
            <th style="width: 10%" class="text-right">Retenido</th>
            <th style="width: 10%">Certificado</th>
        </tr>
        </thead>
        <tbody>
        @forelse($retenciones as $retencion)
            <tr>
                <td>{{ $retencion->created_at?->format('d/m/Y') }}</td>
                <td>{{ $retencion->tipo_corto }}</td>
                <td>{{ $retencion->concept_label ?? $retencion->concept_code ?? '—' }}</td>
                <td>{{ $retencion->contract?->numero_visible ?? '—' }}</td>
                <td>
                    {{ trim(($retencion->contract?->client?->name ?? '') . ' ' . ($retencion->contract?->client?->last_name ?? '')) ?: '—' }}
                    <br><span style="color:#777">{{ $retencion->contract?->client?->identity_number }}</span>
                </td>
                <td>{{ $retencion->invoice?->displayNumber() ?? '—' }}</td>
                <td class="text-right">${{ number_format((float) $retencion->base, 2) }}</td>
                <td class="text-right">{{ rtrim(rtrim(number_format((float) $retencion->rate, 3, ',', '.'), '0'), ',') }}%</td>
                <td class="text-right strong">${{ number_format((float) $retencion->amount, 2) }}</td>
                <td>{{ $retencion->certificate_number ?? 'Pendiente' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="text-center">No se practicaron retenciones en este período.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <div class="note">
        Las retenciones de este listado <strong>no ingresaron a la caja</strong>: el cliente las
        consignó a la DIAN o al municipio a nombre de la empresa. Constituyen un anticipo de
        impuesto y se descuentan en la declaración correspondiente, siempre que se cuente con
        el <strong>certificado de retención</strong> expedido por el cliente. Los registros
        marcados como "Pendiente" aún no tienen certificado y deben reclamarse.
    </div>

@endsection
