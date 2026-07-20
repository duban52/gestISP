{{--
    Gráfica de barras para PDF.

    dompdf no ejecuta JavaScript, así que Chart.js no sirve aquí.
    Las barras se dibujan con celdas de tabla de ancho porcentual:
    es la técnica que dompdf renderiza de forma predecible, sin
    depender de su soporte parcial de SVG.

    Parámetros:
      $filas  [['etiqueta' => ..., 'valor' => float, 'color' => '#hex'], ...]
      $dinero true para formatear como moneda
--}}
@php
    $maximo = collect($filas)->max('valor') ?: 1;
    $dinero = $dinero ?? false;
@endphp

<table class="data" style="table-layout: fixed;">
    <colgroup>
        <col style="width: 30%;">
        <col style="width: 52%;">
        <col style="width: 18%;">
    </colgroup>
    <tbody>
    @forelse ($filas as $fila)
        @php
            // Un valor en cero no dibuja barra: con un mínimo de 1%
            // aparecería una marca de color que se lee como si
            // hubiera algo
            $ancho = ($maximo > 0 && $fila['valor'] > 0)
                ? max(1, round($fila['valor'] / $maximo * 100))
                : 0;
        @endphp
        <tr>
            <td>{{ $fila['etiqueta'] }}</td>
            <td style="padding: 3px 6px;">
                {{-- La barra es una tabla anidada: un div con ancho
                     porcentual dentro de una celda no se dimensiona
                     de forma fiable en dompdf --}}
                @if ($ancho > 0)
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: {{ $ancho }}%; background-color: {{ $fila['color'] ?? '#1F4E79' }}; height: 11px; border: none; padding: 0;"></td>
                            <td style="border: none; padding: 0;"></td>
                        </tr>
                    </table>
                @endif
            </td>
            <td class="text-right strong">
                @if ($dinero)
                    ${{ number_format($fila['valor'], 0, ',', '.') }}
                @else
                    {{ number_format($fila['valor']) }}
                @endif
            </td>
        </tr>
    @empty
        <tr class="empty-row"><td colspan="3">Sin datos en el período.</td></tr>
    @endforelse
    </tbody>
</table>
