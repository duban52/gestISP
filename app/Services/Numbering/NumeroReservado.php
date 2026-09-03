<?php

namespace App\Services\Numbering;

/**
 * Un consecutivo ya reservado.
 *
 * Se devuelve como objeto y no como cadena porque quien lo pide suele
 * necesitar las tres cosas por separado: el numero completo para
 * enseñarlo, el consecutivo suelto para guardarlo en su columna, y la
 * serie de la que salio para dejar constancia de cual fue.
 *
 * Devolver solo la cadena obligaba a volver a partirla, y partir un
 * numero para recuperar sus partes es como se acaban colando errores
 * cuando alguien cambia el prefijo o el relleno.
 */
final class NumeroReservado
{
    public function __construct(
        public readonly SerieNumerable $serie,
        public readonly int $consecutivo,
        public readonly string $completo,
    ) {
    }

    public function __toString(): string
    {
        return $this->completo;
    }
}
