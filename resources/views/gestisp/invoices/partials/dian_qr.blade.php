{{--
    El QR de la representación gráfica, en la cabecera.

    Solo aparece cuando la factura ES electrónica. En una interna
    $dian llega null y esta celda no se pinta: una factura interna no
    puede parecerse a una electrónica, y un QR es justo lo que la haría
    parecerlo.

    POR QUÉ ARRIBA
    --------------
    Porque abajo no cabía. La hoja es media carta y el bloque
    DIAN se pintaba al final, después de los costos: el QR se iba a una
    segunda página él solo. Aquí ocupa el aire que ya sobraba entre los
    datos de la empresa y el recuadro de la factura.

    Es también donde lo pone la representación gráfica que se tomó de
    referencia, y donde la busca quien va a escanearla.

    EL TAMAÑO ESTÁ MEDIDO, NO ELEGIDO
    ---------------------------------
    60 px = 1,6 cm. A 78 px la factura no cabía en media carta: la
    cabecera crecía 24 pt y el total se iba a 433 pt contra los 396 de
    la hoja. 1,6 cm sigue siendo holgado para cualquier lector — el
    mínimo práctico está cerca de 1 cm.
--}}
@if(!empty($dian) && !empty($dian['qr']))
    <td style="padding-right: 10px; vertical-align: top;">
        <img src="{{ $dian['qr'] }}" width="60" height="60" alt="Código QR de la factura electrónica"/>
    </td>
@endif
