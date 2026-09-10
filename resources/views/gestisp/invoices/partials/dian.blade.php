{{--
    Pie DIAN de la representación gráfica.

    Solo se pinta cuando la factura ES electrónica y ya tiene su
    documento; en las internas $dian llega null y aquí no sale nada, que
    es lo correcto: una factura interna no puede parecerse a una
    electrónica.

    QUÉ CAMBIÓ Y POR QUÉ
    --------------------
    Esto era un bloque con el QR dentro y seis líneas de texto, y no
    cabía: la hoja es media carta y el bloque iba al final,
    detrás de los costos, así que se desbordaba a una segunda página
    con el QR solo en ella.

    Ahora el QR va en la cabecera —véase `dian_qr`— y aquí queda lo que
    la ley obliga a imprimir, en tres líneas: CUFE, resolución y estado
    de validación. Es la forma que tiene la representación gráfica que
    se tomó de referencia.

    El CUFE y el QR salen del documento de verdad. Antes el CUFE estaba
    escrito a mano en la plantilla —el mismo en todas las facturas— y no
    había QR.
--}}
@if(!empty($dian))
    {{-- Sin tabla ni contenedor propios: se pinta dentro de la fila de
         costos. Cada bloque suelto cuesta espacio vertical, y en media
         carta eso es la diferencia entre caber y no caber. --}}
    <p style="margin: 0; font-size: 6px; line-height: 1.1; word-wrap: break-word;">
        <strong>CUFE:</strong> {{ $dian['cufe'] }}
    </p>
    <p style="margin: 0; font-size: 6px; line-height: 1.1;">
        @if($dian['resolucion'])
            Resolución DIAN {{ $dian['resolucion']['numero'] }}
            @if($dian['resolucion']['prefijo'])
                — autoriza {{ $dian['resolucion']['prefijo'] }}
                del {{ $dian['resolucion']['desde'] }} al {{ $dian['resolucion']['hasta'] }}
            @endif
            @if($dian['resolucion']['valida_desde'] && $dian['resolucion']['valida_hasta'])
                — vigente del {{ $dian['resolucion']['valida_desde']->format('d/m/Y') }}
                al {{ $dian['resolucion']['valida_hasta']->format('d/m/Y') }}
            @endif
            —
        @endif

        {{-- La fecha de validación solo si la DIAN contestó. Antes se
             imprimía siempre, con la fecha de creación de la factura:
             decía que estaba validada cuando todavía no lo estaba. --}}
        @if($dian['validado_en'])
            Validada por la DIAN el {{ $dian['validado_en']->format('d/m/Y H:i') }}
        @else
            Pendiente de validación por la DIAN
        @endif

        @if($dian['ambiente'] === \App\Models\DianConfiguration::PRUEBAS)
            <strong>— AMBIENTE DE PRUEBAS, SIN VALIDEZ FISCAL</strong>
        @endif
    </p>
@endif
