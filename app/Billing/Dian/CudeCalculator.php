<?php

namespace App\Billing\Dian;

/**
 * El CUDE: el identificador único de una nota crédito o débito.
 *
 * ES EL CUFE CON UN CAMBIO, Y ESE CAMBIO LO ES TODO
 * -------------------------------------------------
 * La cadena tiene exactamente la misma forma que la del CUFE —los
 * mismos catorce campos, en el mismo orden, con el mismo formato— salvo
 * en la penúltima posición:
 *
 *   · CUFE → la **clave técnica** de la resolución.
 *   · CUDE → el **PIN del software**.
 *
 * Tiene sentido: una nota no sale de un rango autorizado, así que no
 * hay clave técnica que usar. Lo que la respalda es el software que la
 * produjo.
 *
 * Confundirlas produce un hash perfectamente válido que la DIAN
 * rechaza, y mirando el resultado no hay forma de saber cuál de los dos
 * secretos se usó.
 *
 * VERIFICADO CONTRA EL EJEMPLO DE LA DIAN
 * ---------------------------------------
 * El anexo técnico 1.9 (§11.4.3) publica un ejemplo resuelto de CUDE de
 * nota crédito. Esta implementación lo reproduce exactamente, y la
 * prueba lo comprueba con esos mismos valores.
 *
 * POR QUÉ REUTILIZA CufeCalculator
 * --------------------------------
 * Porque el formateo de importes —truncados, no redondeados— y la
 * limpieza de los documentos son idénticos, y son justo donde están los
 * errores que cuestan días. Dos copias de esas reglas acabarían
 * divergiendo.
 */
class CudeCalculator
{
    public function __construct(
        private readonly CufeCalculator $formula = new CufeCalculator(),
    ) {
    }

    /**
     * Calcula el CUDE de una nota.
     *
     * @param  string  $numeroNota   Prefijo concatenado con el consecutivo (NC-1)
     * @param  string  $fecha        AAAA-MM-DD
     * @param  string  $hora         HH:MM:SS±HH:MM, con el huso incluido
     * @param  string  $pinDelSoftware  Va donde el CUFE lleva la clave técnica
     * @param  string  $ambiente     '1' producción, '2' pruebas
     */
    public function calcular(
        string $numeroNota,
        string $fecha,
        string $hora,
        float $valorSinImpuestos,
        float $iva,
        float $inc,
        float $ica,
        float $valorTotal,
        string $nitEmisor,
        string $documentoAdquiriente,
        string $pinDelSoftware,
        string $ambiente,
    ): string {
        return hash('sha384', $this->cadena(
            $numeroNota,
            $fecha,
            $hora,
            $valorSinImpuestos,
            $iva,
            $inc,
            $ica,
            $valorTotal,
            $nitEmisor,
            $documentoAdquiriente,
            $pinDelSoftware,
            $ambiente,
        ));
    }

    /**
     * La cadena que se firma, antes del hash.
     *
     * Igual que en el CUFE: cuando un CUDE no cuadra, lo único que
     * sirve para saber por qué es ver la cadena exacta. Y como lleva el
     * PIN dentro, NO debe registrarse en ningún log.
     */
    public function cadena(
        string $numeroNota,
        string $fecha,
        string $hora,
        float $valorSinImpuestos,
        float $iva,
        float $inc,
        float $ica,
        float $valorTotal,
        string $nitEmisor,
        string $documentoAdquiriente,
        string $pinDelSoftware,
        string $ambiente,
    ): string {
        // El PIN ocupa el sitio de la clave tecnica. La forma de la
        // cadena es la misma, asi que se reutiliza la del CUFE en vez
        // de escribirla otra vez y arriesgarse a que diverjan.
        return $this->formula->cadena(
            $numeroNota,
            $fecha,
            $hora,
            $valorSinImpuestos,
            $iva,
            $inc,
            $ica,
            $valorTotal,
            $nitEmisor,
            $documentoAdquiriente,
            $pinDelSoftware,
            $ambiente,
        );
    }
}
