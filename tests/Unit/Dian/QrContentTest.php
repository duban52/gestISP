<?php

namespace Tests\Unit\Dian;

use App\Billing\Dian\QrContent;
use PHPUnit\Framework\TestCase;

/**
 * El contenido del código QR.
 *
 * POR QUÉ IMPORTA TANTO EL FORMATO
 * --------------------------------
 * El QR es lo que hace verificable la representación gráfica: quien
 * recibe el PDF escanea, llega al catálogo de la DIAN y compara. Si las
 * etiquetas o el orden no son los del anexo (§11.7), lo que se imprime
 * deja de ser comparable con lo que la DIAN tiene.
 *
 * DOS ERRATAS DEL PROPIO ANEXO
 * ----------------------------
 * El ejemplo de QR que imprime el documento trae la fecha como
 * «2019-16-01» —mes 16— y un CUFE de 64 caracteres, o sea un SHA-256,
 * cuando el CUFE que el mismo anexo especifica en §11.2 es SHA-384 de
 * 96. Son restos de una versión anterior. Por eso aquí NO se compara
 * contra ese ejemplo: se defiende la lista de campos y su formato, que
 * es lo que el anexo sí especifica sin ambigüedad.
 */
class QrContentTest extends TestCase
{
    private const CUFE = '8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4';

    private function construir(bool $produccion = true): string
    {
        return (new QrContent())->construir(
            numeroFactura: 'SETP990000001',
            fecha: '2019-01-16',
            hora: '10:53:10-05:00',
            nitFacturador: '700085371',
            documentoAdquiriente: '800199436',
            valorSinImpuestos: 1500000.00,
            iva: 285000.00,
            otrosImpuestos: 0.00,
            valorTotal: 1785000.00,
            cufe: self::CUFE,
            produccion: $produccion,
        );
    }

    public function test_lleva_las_etiquetas_del_anexo_en_su_orden(): void
    {
        $lineas = explode("\n", $this->construir());

        $this->assertSame('NumFac: SETP990000001', $lineas[0]);
        $this->assertSame('FecFac: 2019-01-16', $lineas[1]);
        $this->assertSame('HorFac: 10:53:10-05:00', $lineas[2]);
        $this->assertSame('NitFac: 700085371', $lineas[3]);
        $this->assertSame('DocAdq: 800199436', $lineas[4]);
        $this->assertSame('ValFac: 1500000.00', $lineas[5]);
        $this->assertSame('ValIva: 285000.00', $lineas[6]);
        $this->assertSame('ValOtroIm: 0.00', $lineas[7]);
        $this->assertSame('ValTolFac: 1785000.00', $lineas[8]);
        $this->assertSame('CUFE: ' . self::CUFE, $lineas[9]);
    }

    public function test_la_ultima_linea_es_la_url_de_consulta(): void
    {
        // Es lo que hace que escanear el QR lleve a la DIAN y no a un
        // texto suelto que nadie puede comprobar.
        $lineas = explode("\n", $this->construir());

        $this->assertSame(
            'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=' . self::CUFE,
            end($lineas),
        );
    }

    public function test_en_pruebas_apunta_al_catalogo_de_habilitacion(): void
    {
        // Un documento de pruebas no existe en el catálogo de
        // producción: el enlace no resolvería.
        $this->assertStringContainsString(
            'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=' . self::CUFE,
            $this->construir(produccion: false),
        );
    }

    public function test_los_documentos_van_sin_puntuacion(): void
    {
        $qr = (new QrContent())->construir(
            'SETP990000001', '2019-01-16', '10:53:10-05:00',
            '700.085.371', '800-199-436',
            1500000.00, 285000.00, 0.00, 1785000.00,
            self::CUFE, true,
        );

        $this->assertStringContainsString('NitFac: 700085371', $qr);
        $this->assertStringContainsString('DocAdq: 800199436', $qr);
    }

    public function test_los_importes_llevan_dos_decimales_sin_separadores(): void
    {
        // Con separador de miles el importe del QR deja de coincidir con
        // el de la factura y la comparación visual falla.
        $qr = (new QrContent())->construir(
            'SETP990000001', '2019-01-16', '10:53:10-05:00',
            '700085371', '800199436',
            1500000.0, 285000.0, 0.0, 1785000.0,
            self::CUFE, true,
        );

        $this->assertStringContainsString('ValFac: 1500000.00', $qr);
        $this->assertStringNotContainsString('1,500,000', $qr);
        $this->assertStringNotContainsString('$', $qr);
    }

    public function test_el_impuesto_ausente_va_en_cero_y_no_vacio(): void
    {
        $this->assertStringContainsString('ValOtroIm: 0.00', $this->construir());
    }
}
