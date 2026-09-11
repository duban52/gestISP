{{-- ============================================================
     Inventario de almacén

     Existencias actuales del almacén: cantidad por material y
     los seriales registrados de los equipos.
     ============================================================ --}}
@extends('gestisp.pdf.layout', [
    'pdfTitle' => 'Inventario de almacén',
    'pdfSubtitle' => $warehouse->description,
])

@php
    // FALLA CERRADO. `$verCostos` lo decide el controlador con el permiso
    // `materials.costs`; si por lo que sea la plantilla se renderiza sin
    // el, se asume que NO se pueden ver. Un dato de negocio se oculta por
    // defecto y se ensena a proposito, nunca al reves.
    $verCostos = $verCostos ?? false;
    $resumenValor = $resumenValor ?? ['total' => 0.0, 'materiales_sin_valorar' => 0, 'completo' => true];
@endphp

@php
    $items = collect($inventoriesData);
    // Materiales que se controlan por serial (tienen SNs listados)
    $withSerials = $items->filter(fn ($i) => !empty($i['sns']));
@endphp

@section('meta')
    <tr>
        <td style="width: 30%">
            <span class="meta-label">Almacén</span>
            {{ $warehouse->description }}
        </td>
        <td style="width: 23%">
            <span class="meta-label">Materiales distintos</span>
            {{ $items->count() }}
        </td>
        <td style="width: 23%">
            <span class="meta-label">Unidades totales</span>
            {{ number_format($items->sum('quantity'), 2) }}
        </td>
        <td style="width: 24%">
            <span class="meta-label">Corte</span>
            {{ now()->format('d/m/Y h:i a') }}
        </td>
    </tr>
    @if($verCostos)
        <tr>
            <td colspan="4">
                <span class="meta-label">Valor del inventario</span>
                {{ $resumenValor['completo'] ? '' : 'desde ' }}${{ number_format($resumenValor['total'], 2) }}
                @if(!$resumenValor['completo'])
                    <span style="font-size: 8px;">
                        ({{ $resumenValor['materiales_sin_valorar'] }}
                        {{ $resumenValor['materiales_sin_valorar'] === 1 ? 'material' : 'materiales' }}
                        sin valor de compra registrado)
                    </span>
                @endif
            </td>
        </tr>
    @endif
@endsection

@section('content')

    <div class="section-title">Existencias</div>

    <table class="data">
        <thead>
        {{-- EL PDF SE GUARDA, SE REENVÍA Y SE IMPRIME.
             Por eso `$verCostos` lo decide el controlador con el permiso
             `materials.costs` y no la plantilla: si el permiso solo se
             comprobara en la pantalla, bastaría con descargar el
             inventario para saltárselo. --}}
        <tr>
            <th style="width: {{ $verCostos ? '26%' : '32%' }}">Material</th>
            <th style="width: 10%" class="text-right">Cantidad</th>
            <th style="width: 11%">Unidad</th>
            @if($verCostos)
                <th style="width: 13%" class="text-right">V. unitario</th>
                <th style="width: 14%" class="text-right">V. total</th>
            @endif
            <th style="width: {{ $verCostos ? '26%' : '43%' }}">Seriales registrados</th>
        </tr>
        </thead>
        <tbody>
        @forelse($items as $inventory)
            <tr>
                <td>{{ $inventory['material'] }}</td>
                <td class="text-right strong">{{ number_format($inventory['quantity'], 2) }}</td>
                <td>{{ $inventory['unit_of_measurement'] }}</td>
                @if($verCostos)
                    {{-- «—» y no «$0,00»: lo que no tiene precio
                         registrado no es que fuera gratis. --}}
                    <td class="text-right">
                        {{ $inventory['valor']['unitario'] !== null ? '$' . number_format($inventory['valor']['unitario'], 2) : '—' }}
                    </td>
                    <td class="text-right">
                        {{ $inventory['valor']['total'] !== null ? '$' . number_format($inventory['valor']['total'], 2) : '—' }}
                        @if($inventory['valor']['unidades_sin_valorar'] > 0)*@endif
                    </td>
                @endif
                {{-- Los seriales pueden ser una lista larga: la
                     celda quiebra el texto para no desbordar --}}
                <td>{{ $inventory['sns'] ?: '—' }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="{{ $verCostos ? 6 : 4 }}">El almacén no tiene existencias registradas.</td>
            </tr>
        @endforelse
        </tbody>
        @if($items->isNotEmpty())
            <tfoot>
            <tr>
                <td>TOTAL</td>
                <td class="text-right">{{ number_format($items->sum('quantity'), 2) }}</td>
                @if($verCostos)
                    <td></td>
                    <td class="text-right">
                        {{ $resumenValor['completo'] ? '' : '≥ ' }}${{ number_format($resumenValor['total'], 2) }}
                    </td>
                @endif
                <td colspan="2">
                    {{ $items->count() }} material(es) · {{ $withSerials->count() }} con control de serial
                </td>
            </tr>
            </tfoot>
        @endif
    </table>

    <div class="note">
        Documento de existencias al corte indicado. Las cantidades reflejan los
        movimientos registrados en el sistema hasta ese momento; cualquier
        diferencia física debe registrarse como movimiento de ajuste.
        @if($verCostos)
            <br>
            Los valores son de COMPRA: lo que se pagó por el material, no su precio de
            venta ni su valor en libros. En los equipos es el costo exacto de cada unidad;
            en los consumibles, el promedio ponderado de las compras. Lo marcado con
            «—» o con «*» no tiene valor de compra registrado y NO se suma al total,
            que por eso se presenta como cifra mínima.
        @endif
    </div>

    {{-- ---------- Firmas ---------- --}}
    <table class="signature-area">
        <tr>
            <td>
                <div class="signature-line">
                    @auth{{ auth()->user()->name }} {{ auth()->user()->last_name }}@endauth<br>
                    Generado por
                </div>
            </td>
            <td>
                <div class="signature-line">
                    Verificado por (responsable de almacén)
                </div>
            </td>
        </tr>
    </table>

@endsection
