<?php

namespace App\Services\Numbering;

/**
 * Lo que DocumentNumberService necesita saber de una serie.
 *
 * POR QUE UNA INTERFAZ Y NO UNA SOLA TABLA
 * ----------------------------------------
 * Hay dos tablas de series y van a seguir siendo dos:
 *
 *   · `document_sequences` — la numeracion INTERNA, la que se inventa
 *     la empresa. Contratos y notas.
 *   · `invoice_numbering_sequences` — las facturas, que ya nacieron
 *     con resolucion, vigencia y rango porque se diseño anticipando la
 *     DIAN. Sus consecutivos se moveran a la tabla fiscal cuando esa
 *     exista; moverlos ahora a la interna seria moverlos dos veces, y
 *     un consecutivo de factura movido de mas es justo lo que no
 *     conviene repetir.
 *
 * Lo que SI tiene que ser uno solo es el ALGORITMO: sumar uno,
 * comprobar el rango y formatear. Sobre todo el rango — emitir pasado
 * el rango autorizado es emitir con numeros que nadie autorizo, y esa
 * comprobacion no puede tener dos versiones que se separen con el
 * tiempo.
 *
 * Esta interfaz es lo minimo que el servicio necesita para hacer ese
 * trabajo sobre cualquiera de las dos.
 */
interface SerieNumerable
{
    /** Ultimo consecutivo entregado. */
    public function consecutivoActual(): int;

    /** Primer numero autorizado, o null si no hay rango. */
    public function rangoDesde(): ?int;

    /** Ultimo numero autorizado, o null si no hay rango. */
    public function rangoHasta(): ?int;

    /** Como se ve ese consecutivo ya emitido. */
    public function formatearNumero(int $consecutivo): string;

    /** Nombre corto para los mensajes de error. */
    public function nombreDeSerie(): string;

    /** Deja el contador en ese consecutivo. */
    public function avanzarA(int $consecutivo): void;
}
