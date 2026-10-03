{{-- ============================================================
     Listado de clientes en PDF

     Lo mismo que la pantalla y con los mismos filtros: quien exporta
     después de filtrar espera llevarse lo que está viendo, no la
     empresa entera.

     Una fila por CLIENTE. Sus contratos van dentro de la celda —un
     cliente puede tener tres— porque abrir una fila por contrato
     convierte el listado de clientes en otro listado de contratos,
     que ya existe y es mejor para eso.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Listado de clientes',
    'orientation' => 'landscape',
])

@section('meta')
    <tr>
        <td style="width: 34%">
            <span class="meta-label">Filtros</span>
            {{ !empty($filtros) ? implode(' · ', $filtros) : 'Sin filtros: todos los clientes' }}
        </td>
        <td style="width: 22%">
            <span class="meta-label">Clientes</span>
            {{ $resumen['total'] }}
        </td>
        <td style="width: 22%">
            <span class="meta-label">Con contrato</span>
            {{ $resumen['con_contrato'] }}
        </td>
        <td style="width: 22%">
            <span class="meta-label">Sin contrato</span>
            {{ $resumen['sin_contrato'] }}
        </td>
    </tr>
@endsection

@section('content')

    <table class="data">
        <thead>
        <tr>
            <th style="width: 12%">Documento</th>
            <th style="width: 24%">Cliente</th>
            <th style="width: 10%">Tipo</th>
            <th style="width: 16%">Contacto</th>
            <th style="width: 14%">Contratos</th>
            <th style="width: 14%">Plan</th>
            <th style="width: 10%">Estado</th>
        </tr>
        </thead>
        <tbody>
        @forelse($clients as $client)
            <tr>
                <td>{{ $client->identity_number ?: '—' }}</td>
                <td>{{ trim($client->name . ' ' . ($client->last_name ?? '')) ?: '—' }}</td>
                <td>{{ $client->type_client ?: '—' }}</td>
                <td>
                    {{ $client->number_phone ?: '—' }}
                    @if($client->email)
                        <br><span class="muted">{{ $client->email }}</span>
                    @endif
                </td>
                <td>
                    @forelse($client->contracts as $contrato)
                        {{ $contrato->numero_visible ?: '—' }}<br>
                    @empty
                        <span class="muted">Sin contrato</span>
                    @endforelse
                </td>
                <td>
                    {{ $client->contracts->pluck('plan.name')->filter()->unique()->implode(', ') ?: '—' }}
                </td>
                <td>
                    {{ $client->contracts->pluck('status')->filter()->unique()->implode(', ') ?: '—' }}
                </td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="7">No se encontraron clientes con esos filtros.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

@endsection
