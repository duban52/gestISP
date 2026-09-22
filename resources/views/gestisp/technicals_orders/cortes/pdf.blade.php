{{-- ============================================================
     Reporte de un corte masivo por mora (PDF)

     Toda la lista, también lo que no se cortó y por qué: es el soporte
     de quién ordenó el corte, con qué motivo y a quién alcanzó.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => $pdfTitle,
    'pdfSubtitle' => $pdfSubtitle ?? null,
    'branch' => $branch ?? null,
])

@section('meta')
    <tr>
        <td style="width: 20%">
            <span class="meta-label">Ordenado por</span>
            {{ $corte->user?->name ?? '—' }}
        </td>
        <td style="width: 20%">
            <span class="meta-label">Origen de la lista</span>
            {{ $corte->source }}
        </td>
        <td style="width: 20%">
            <span class="meta-label">Regla</span>
            {{ $corte->threshold }} o más facturas vencidas
        </td>
        <td style="width: 40%">
            <span class="meta-label">Motivo</span>
            {{ $corte->reason }}
        </td>
    </tr>
@endsection

@section('content')
    @php
        $etiquetas = \App\Models\ContractCutoffItem::ETIQUETAS;
    @endphp

    <div class="section-title">Resumen</div>
    <table class="data">
        <thead>
        <tr>
            <th>En la lista</th>
            @foreach($etiquetas as $clave => [$etiqueta, $color])
                <th class="text-right">{{ $etiqueta }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>{{ $items->count() }}</td>
            @foreach($etiquetas as $clave => [$etiqueta, $color])
                <td class="text-right">{{ $conteo[$clave] ?? 0 }}</td>
            @endforeach
        </tr>
        </tbody>
    </table>

    <div class="section-title">Contratos</div>
    <table class="data">
        <thead>
        <tr>
            <th style="width: 10%">Contrato</th>
            <th style="width: 10%">Documento</th>
            <th style="width: 18%">Cliente</th>
            <th style="width: 6%" class="text-right">Vencidas</th>
            <th style="width: 9%" class="text-right">Debía</th>
            <th style="width: 9%">Resultado</th>
            <th style="width: 38%">Detalle</th>
        </tr>
        </thead>
        <tbody>
        @foreach($items as $item)
            <tr>
                <td>{{ $item->contract_number }}</td>
                <td>{{ $item->contract?->client?->identity_number ?? '—' }}</td>
                <td>{{ $item->contract?->client?->fullName() ?? '—' }}</td>
                <td class="text-right">{{ $item->overdue_count ?? '—' }}</td>
                <td class="text-right">{{ $item->overdue_amount !== null ? '$' . number_format((float) $item->overdue_amount, 0, ',', '.') : '—' }}</td>
                <td>{{ $etiquetas[$item->status][0] ?? $item->status }}</td>
                <td>{{ $item->message }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
