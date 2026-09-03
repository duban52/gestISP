<?php

namespace App\Billing\Dian;

/**
 * El contenido del código QR de la factura electrónica.
 *
 * QUÉ LLEVA
 * ---------
 * El anexo técnico 1.9 (§11.7) fija exactamente qué campos van y con
 * qué etiqueta: número, fecha, hora con huso, NIT del facturador,
 * documento del adquiriente, los tres importes, el CUFE y la URL de
 * consulta. No es texto libre ni un JSON: son esas etiquetas, en ese
 * orden.
 *
 * PARA QUÉ SIRVE
 * --------------
 * Es lo que hace verificable la representación gráfica. Quien recibe un
 * PDF puede escanear el QR, llegar al sitio de la DIAN y comparar lo
 * que dice el papel con lo que dice la DIAN. Si no coinciden, el
 * documento es apócrifo.
 *
 * Por eso el QR va en TODAS las páginas de la representación gráfica,
 * no solo en la primera: el anexo lo exige explícitamente.
 *
 * LOS IMPORTES, IGUAL QUE EN EL CUFE
 * ----------------------------------
 * Punto decimal, dos decimales, sin separadores de miles ni símbolo de
 * peso. Y los documentos sin puntos ni guiones.
 *
 * UNA INCONSISTENCIA DEL PROPIO ANEXO
 * -----------------------------------
 * El ejemplo de QR que imprime el anexo trae un CUFE de 64 caracteres
 * —un SHA-256—, cuando el CUFE que el mismo documento especifica en
 * §11.2 es SHA-384, de 96. Es un ejemplo heredado de una versión
 * anterior. Lo que vale es la lista de campos y su formato, no ese
 * valor: aquí se usa el CUFE que llegue, calculado por CufeCalculator.
 */
class QrContent
{
    /** Donde se consulta un documento ya validado. */
    public const URL_PRODUCCION = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=';
    public const URL_HABILITACION = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=';

    /**
     * El texto que se codifica en el QR.
     *
     * @param  string  $numeroFactura  El cbc:ID completo (SETP990000001)
     * @param  string  $fecha          AAAA-MM-DD
     * @param  string  $hora           HH:MM:SS±HH:MM
     * @param  string  $nitFacturador  Sin puntos ni guiones
     * @param  string  $documentoAdquiriente  Ídem
     * @param  float   $valorSinImpuestos
     * @param  float   $iva
     * @param  float   $otrosImpuestos
     * @param  float   $valorTotal
     * @param  string  $cufe
     * @param  bool    $produccion     Decide a qué URL apunta el QR
     */
    public function construir(
        string $numeroFactura,
        string $fecha,
        string $hora,
        string $nitFacturador,
        string $documentoAdquiriente,
        float $valorSinImpuestos,
        float $iva,
        float $otrosImpuestos,
        float $valorTotal,
        string $cufe,
        bool $produccion,
    ): string {
        // Una etiqueta por línea, en el orden del anexo. El salto de
        // línea es parte del formato: no es cosmética.
        return implode("\n", [
            'NumFac: ' . $numeroFactura,
            'FecFac: ' . $fecha,
            'HorFac: ' . $hora,
            'NitFac: ' . $this->soloDigitos($nitFacturador),
            'DocAdq: ' . $this->soloDigitos($documentoAdquiriente),
            'ValFac: ' . $this->importe($valorSinImpuestos),
            'ValIva: ' . $this->importe($iva),
            'ValOtroIm: ' . $this->importe($otrosImpuestos),
            'ValTolFac: ' . $this->importe($valorTotal),
            'CUFE: ' . $cufe,
            $this->url($cufe, $produccion),
        ]);
    }

    /**
     * La URL de consulta del documento.
     *
     * El ambiente NO se deduce del contexto: se recibe. Una factura
     * emitida en pruebas tiene que seguir apuntando al catálogo de
     * habilitación aunque la empresa ya haya pasado a producción, o el
     * enlace de un documento viejo dejaría de resolver.
     */
    public function url(string $cufe, bool $produccion): string
    {
        return ($produccion ? self::URL_PRODUCCION : self::URL_HABILITACION) . $cufe;
    }

    /** Punto decimal, dos decimales, sin separadores ni moneda. */
    private function importe(float $valor): string
    {
        return number_format($valor, 2, '.', '');
    }

    private function soloDigitos(string $documento): string
    {
        return preg_replace('/\D/', '', $documento) ?? '';
    }
}
