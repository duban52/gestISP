<?php

namespace App\Billing\Dian;

use Illuminate\Support\Carbon;

/**
 * El CUFE: el identificador único de una factura electrónica.
 *
 * QUÉ ES
 * ------
 * Un SHA-384 sobre catorce datos de la factura concatenados, más la
 * **clave técnica** de la resolución. Esa clave no viaja en ningún XML
 * —viene con el rango de numeración y se consulta por servicio web—, y
 * es lo que hace que un CUFE no se pueda falsificar conociendo solo
 * los datos de la factura.
 *
 * VERIFICADO CONTRA EL EJEMPLO DE LA DIAN
 * ---------------------------------------
 * El anexo técnico 1.9 (§11.2.1) publica un ejemplo resuelto: unos
 * datos de entrada y su CUFE. Esta implementación lo reproduce
 * exactamente, y la prueba lo comprueba con esos mismos valores.
 *
 * **Cuidado con la cadena impresa en el PDF.** El anexo muestra la
 * concatenación de su propio ejemplo con el guion del huso horario
 * perdido (`10:53:1005:00` en vez de `10:53:10-05:00`), seguramente al
 * maquetar. Quien la copie literalmente obtiene un hash distinto del
 * que el mismo documento da por bueno. Lo que vale es la fórmula, no
 * esa cadena.
 *
 * EL FORMATO DE CADA DATO IMPORTA
 * -------------------------------
 * Un decimal de más, un separador de miles o un símbolo de peso
 * cambian el hash y con él el CUFE. Por eso el formateo vive aquí y no
 * en quien llama: los importes van con punto decimal, dos decimales
 * **truncados** —no redondeados— y sin separadores.
 */
class CufeCalculator
{
    /** Los tres impuestos que entran en el CUFE, en su orden fijo. */
    public const IVA = '01';
    public const INC = '04';
    public const ICA = '03';

    /**
     * Calcula el CUFE.
     *
     * @param  string  $numeroFactura  Prefijo concatenado con el consecutivo (SETP990000001)
     * @param  string  $fecha          AAAA-MM-DD
     * @param  string  $hora           HH:MM:SS±HH:MM, con el huso incluido
     * @param  float   $valorSinImpuestos
     * @param  float   $iva            0.0 si no aplica
     * @param  float   $inc            0.0 si no aplica
     * @param  float   $ica            0.0 si no aplica
     * @param  float   $valorTotal
     * @param  string  $nitEmisor      Sin puntos, guiones ni dígito de verificación
     * @param  string  $documentoAdquiriente  Ídem
     * @param  string  $claveTecnica   La del rango de numeración
     * @param  string  $ambiente       '1' producción, '2' pruebas
     */
    public function calcular(
        string $numeroFactura,
        string $fecha,
        string $hora,
        float $valorSinImpuestos,
        float $iva,
        float $inc,
        float $ica,
        float $valorTotal,
        string $nitEmisor,
        string $documentoAdquiriente,
        string $claveTecnica,
        string $ambiente,
    ): string {
        return hash('sha384', $this->cadena(
            $numeroFactura,
            $fecha,
            $hora,
            $valorSinImpuestos,
            $iva,
            $inc,
            $ica,
            $valorTotal,
            $nitEmisor,
            $documentoAdquiriente,
            $claveTecnica,
            $ambiente,
        ));
    }

    /**
     * La cadena que se firma, antes de aplicarle el hash.
     *
     * Se expone aparte porque cuando un CUFE sale distinto del que
     * espera la DIAN, lo único que sirve para averiguar por qué es ver
     * la cadena exacta que se usó. Comparar dos hashes no dice nada.
     */
    public function cadena(
        string $numeroFactura,
        string $fecha,
        string $hora,
        float $valorSinImpuestos,
        float $iva,
        float $inc,
        float $ica,
        float $valorTotal,
        string $nitEmisor,
        string $documentoAdquiriente,
        string $claveTecnica,
        string $ambiente,
    ): string {
        return $numeroFactura
            . $fecha
            . $hora
            . $this->importe($valorSinImpuestos)
            . self::IVA . $this->importe($iva)
            . self::INC . $this->importe($inc)
            . self::ICA . $this->importe($ica)
            . $this->importe($valorTotal)
            . $this->soloDigitos($nitEmisor)
            . $this->soloDigitos($documentoAdquiriente)
            . $claveTecnica
            . $ambiente;
    }

    /**
     * Un importe como lo quiere el CUFE.
     *
     * Punto decimal, dos decimales **truncados** y sin separadores de
     * miles ni símbolo de moneda.
     *
     * Truncados y no redondeados: el anexo dice «truncados», y
     * redondear 1500000.005 a 1500000.01 en vez de 1500000.00 produce
     * un CUFE que la DIAN rechaza. `number_format` redondea, así que
     * no sirve.
     */
    private function importe(float $valor): string
    {
        $truncado = (int) ($valor * 100) / 100;

        return number_format($truncado, 2, '.', '');
    }

    /**
     * El documento sin puntos, guiones ni dígito de verificación.
     *
     * El dígito de verificación se quita en quien llama, no aquí: este
     * método no puede saber si el último dígito es parte del número o
     * el DV. Lo que hace es limpiar la puntuación, que es de donde
     * vienen la mayoría de los CUFE que no cuadran.
     */
    private function soloDigitos(string $documento): string
    {
        return preg_replace('/\D/', '', $documento) ?? '';
    }

    /**
     * La hora tal y como la quiere el CUFE, con el huso.
     *
     * El huso NO es opcional y su guion tampoco: es justamente lo que
     * el PDF del anexo perdió al maquetar su ejemplo.
     */
    public function horaDe(Carbon $momento): string
    {
        return $momento->format('H:i:sP');
    }
}
