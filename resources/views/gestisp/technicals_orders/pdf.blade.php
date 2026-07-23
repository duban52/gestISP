{{-- ============================================================
     Comprobante de orden de servicio (PDF)

     Soporte ante el cliente: datos de la orden, material usado,
     solución, comentarios del técnico y del cliente, evidencia
     fotográfica y firma. Extiende la plantilla estándar de PDFs.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => $pdfTitle ?? ('Orden de servicio N.º ' . $order->id),
    'pdfSubtitle' => $pdfSubtitle ?? null,
    'branch' => $branch ?? null,
])

@section('meta')
    <tr>
        <td style="width: 25%">
            <span class="meta-label">N.º de orden</span>
            {{ $order->id }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Estado</span>
            {{ $order->status }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Creada</span>
            {{ $order->created_at?->format('d/m/Y h:i a') ?? '—' }}
        </td>
        <td style="width: 25%">
            <span class="meta-label">Última acción</span>
            {{ $order->updated_at?->format('d/m/Y h:i a') ?? '—' }}
        </td>
    </tr>
@endsection

@section('content')

    {{-- Estilos propios de este comprobante (evidencia y firma).
         Van aquí y no en @section('css') porque la plantilla base
         de PDFs no expone ese yield. --}}
    <style>
        table.photo-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 4px;
            table-layout: fixed;
        }
        table.photo-grid td {
            width: 33.33%;
            height: 120px;
            border: 1px solid #D9DEE4;
            background-color: #FAFBFC;
            text-align: center;
            vertical-align: middle;
            padding: 3px;
        }
        table.photo-grid img {
            max-width: 100%;
            max-height: 112px;
        }
        .signature-img {
            max-height: 70px;
            max-width: 200px;
            display: block;
            margin: 0 auto 2px auto;
        }
    </style>

    {{-- ---------- Cliente y contrato ---------- --}}
    <div class="section-title">Datos del cliente y del contrato</div>
    <table class="detail">
        <tr>
            <td class="label">Cliente</td>
            <td>{{ $order->contract?->client?->name }} {{ $order->contract?->client?->last_name }}</td>
            <td class="label">Identificación</td>
            <td>{{ $order->contract?->client?->identity_number ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Teléfono</td>
            <td>
                {{ $order->contract?->client?->number_phone ?? '—' }}{{ $order->contract?->client?->aditional_phone ? ', ' . $order->contract->client->aditional_phone : '' }}
            </td>
            <td class="label">N.º de contrato</td>
            <td>{{ $order->contract?->id ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Plan</td>
            <td>{{ $order->contract?->plan?->name ?? '—' }}</td>
            <td class="label">Barrio</td>
            <td>{{ $order->contract?->neighborhood ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Dirección</td>
            <td colspan="3">{{ $order->contract?->address ?? '—' }}</td>
        </tr>
    </table>

    {{-- ---------- Datos de la orden ---------- --}}
    <div class="section-title">Datos de la orden</div>
    <table class="detail">
        <tr>
            <td class="label">Tipo de orden</td>
            <td>{{ $order->type }}</td>
            <td class="label">Detalle</td>
            <td>{{ $order->detail }}</td>
        </tr>
        <tr>
            <td class="label">Técnico asignado</td>
            <td>{{ $order->assignedUser?->name ?? '—' }} {{ $order->assignedUser?->last_name ?? '' }}</td>
            <td class="label">Creada por</td>
            <td>{{ $order->createdBy?->name ?? 'Sistema' }} {{ $order->createdBy?->last_name ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Comentario inicial</td>
            <td colspan="3">{{ $order->initial_comment ?: '—' }}</td>
        </tr>
    </table>

    {{-- ---------- Solución y comentarios ---------- --}}
    <div class="section-title">Solución y comentarios</div>
    <table class="detail">
        <tr>
            <td class="label">Comentario del técnico</td>
            <td>{{ $order->observations_technical ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Comentario del cliente</td>
            <td>{{ $order->client_observation ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Solución aplicada</td>
            <td>{{ $order->solution ?: '—' }}</td>
        </tr>
        @if($order->rejection_reason)
            <tr>
                <td class="label">Motivo de rechazo</td>
                <td>{{ $order->rejection_reason }}</td>
            </tr>
        @endif
    </table>

    {{-- ---------- Materiales usados ---------- --}}
    <div class="section-title">Materiales utilizados</div>
    <table class="data">
        <thead>
        <tr>
            <th style="width: 55%">Material</th>
            <th style="width: 20%" class="text-right">Cantidad</th>
            <th style="width: 25%">Número de serie</th>
        </tr>
        </thead>
        <tbody>
        @forelse($order->materials as $material)
            <tr>
                <td>{{ $material->material?->name ?? '—' }}</td>
                <td class="text-right">{{ $material->quantity }}</td>
                <td>{{ $material->serial_number ?: '—' }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="3">No se registró material en esta orden.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    {{-- ---------- Evidencia fotográfica ---------- --}}
    @php
        $images = is_string($order->images) ? (json_decode($order->images) ?: []) : [];
        // Solo las que existen en disco (dompdf lee por ruta absoluta)
        $imagePaths = collect($images)
            ->map(fn ($img) => public_path($img))
            ->filter(fn ($path) => is_file($path))
            ->values();
    @endphp

    @if($imagePaths->isNotEmpty())
        <div class="section-title">Evidencia fotográfica</div>
        <table class="photo-grid">
            @foreach($imagePaths->chunk(3) as $row)
                <tr>
                    @foreach($row as $path)
                        <td><img src="{{ $path }}" alt="Evidencia"></td>
                    @endforeach
                    {{-- Rellenar la fila para mantener el ancho de celda --}}
                    @for($i = $row->count(); $i < 3; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif

    {{-- ---------- Firma del cliente ---------- --}}
    @php
        $signaturePath = $order->client_signature ? public_path($order->client_signature) : null;
        $hasSignature = $signaturePath && is_file($signaturePath);
    @endphp

    <div class="section-title">Conformidad del cliente</div>
    <table class="signature-area">
        <tr>
            <td>
                @if($hasSignature)
                    <img src="{{ $signaturePath }}" alt="Firma del cliente" class="signature-img">
                @endif
                <div class="signature-line">
                    Firma del cliente<br>
                    {{ $order->contract?->client?->name }} {{ $order->contract?->client?->last_name }}
                </div>
            </td>
            <td>
                <div class="signature-line">
                    Técnico responsable<br>
                    {{ $order->assignedUser?->name ?? '' }} {{ $order->assignedUser?->last_name ?? '' }}
                </div>
            </td>
        </tr>
    </table>

@endsection
