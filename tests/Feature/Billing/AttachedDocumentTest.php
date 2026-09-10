<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\AttachedDocumentBuilder;
use App\Billing\Dian\SelfSignedCertificate;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use RuntimeException;

/**
 * El `AttachedDocument`: el contenedor que se le entrega al adquiriente.
 *
 * DE DÓNDE SALE LA ESTRUCTURA QUE SE COMPRUEBA AQUÍ
 * -------------------------------------------------
 * De un AttachedDocument REAL, emitido por otra empresa colombiana. La
 * DIAN no publica ninguno —se revisaron su anexo 1.9, su Caja de
 * Herramientas y tres librerías colombianas— y en este proyecto deducir
 * estructuras de la DIAN ya costó cuatro despliegues.
 *
 * El ejemplar no está en el repositorio: es un documento fiscal de un
 * tercero. Lo que queda es esta prueba.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que valide contra su esquema, que lleve DENTRO los dos documentos
 * —la factura firmada y el acuse de la DIAN—, y que los literales que la
 * referencia trae escritos tal cual sigan escritos tal cual.
 */
class AttachedDocumentTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    private const ACUSE = '<?xml version="1.0" encoding="UTF-8"?><ApplicationResponse>'
        . '<cac:DocumentResponse xmlns:cac="urn:cac"><cac:Response><cbc:ResponseCode xmlns:cbc="urn:cbc">02</cbc:ResponseCode>'
        . '</cac:Response></cac:DocumentResponse></ApplicationResponse>';

    private function documentoValidado(): ElectronicDocument
    {
        $factura = $this->facturaElectronica();

        ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->update([
                'status' => ElectronicDocument::ACEPTADO,
                'accepted_at' => now(),
                'dian_response_xml' => self::ACUSE,
            ]);

        return ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail();
    }

    /** Un certificado de prueba, generado una vez por corrida. */
    private static ?string $p12 = null;

    private function certificado(): string
    {
        return self::$p12 ??= (new SelfSignedCertificate())->generar(
            ['countryName' => 'CO', 'commonName' => 'Prueba Contenedor'],
            'clave',
        );
    }

    private function contenedor(): string
    {
        return app(AttachedDocumentBuilder::class)->construir(
            $this->documentoValidado(),
            $this->certificado(),
            'clave',
        );
    }

    public function test_el_contenedor_valida_contra_su_esquema(): void
    {
        // Es la prueba central: el orden de los elementos en UBL es
        // rígido, y un contenedor que no valida no lo carga el sistema
        // contable del cliente.
        $xml = $this->contenedor();

        $this->assertSame([], app(AttachedDocumentBuilder::class)->erroresDeEsquema($xml));
    }

    public function test_lleva_dentro_la_factura_y_el_acuse(): void
    {
        // Los dos, en CDATA, con su declaración `<?xml` — así viene en
        // el documento de referencia, y es lo que permite al adquiriente
        // sacarlos y usarlos tal cual.
        $documento = $this->documentoValidado();

        $xml = app(AttachedDocumentBuilder::class)->construir(
            $documento,
            $this->certificado(),
            'clave',
        );

        preg_match_all('/<!\[CDATA\[(.*?)\]\]>/s', $xml, $coincidencias);

        $this->assertCount(2, $coincidencias[1], 'Tienen que ir los DOS documentos embebidos.');
        $this->assertStringContainsString('<Invoice', $coincidencias[1][0]);
        $this->assertStringContainsString($documento->cufe, $coincidencias[1][0]);
        $this->assertStringContainsString('<ApplicationResponse', $coincidencias[1][1]);
    }

    public function test_los_literales_son_los_de_la_referencia(): void
    {
        // No son códigos: son textos que el documento de referencia trae
        // escritos tal cual, y que quien lo lee espera encontrar.
        $xml = $this->contenedor();

        $this->assertStringContainsString('<cbc:CustomizationID>Documentos adjuntos</cbc:CustomizationID>', $xml);
        $this->assertStringContainsString('<cbc:DocumentType>Contenedor de Factura Electrónica</cbc:DocumentType>', $xml);
        $this->assertStringContainsString('<cbc:DocumentType>ApplicationResponse</cbc:DocumentType>', $xml);
        $this->assertStringContainsString('schemeName="CUFE-SHA384"', $xml);
    }

    public function test_acredita_que_la_dian_lo_valido(): void
    {
        // `cac:ResultOfVerification` es lo que le dice al adquiriente
        // que esto pasó por la DIAN, y con el nombre completo con que
        // ella se identifica.
        $xml = $this->contenedor();

        $this->assertStringContainsString(
            '<cbc:ValidatorID>Unidad Especial Dirección de Impuestos y Aduanas Nacionales</cbc:ValidatorID>',
            $xml,
        );
        $this->assertStringContainsString('<cbc:ValidationResultCode>02</cbc:ValidationResultCode>', $xml);
    }

    public function test_va_firmado(): void
    {
        $xml = $this->contenedor();

        $this->assertStringContainsString('<ds:Signature', $xml);
        $this->assertStringContainsString('CUFE-SHA384', $xml);

        // Los espacios de nombres de xades en la RAÍZ, no dentro de la
        // firma: es lo que costó un rechazo ZE02 en la factura.
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $this->assertSame(
            'http://uri.etsi.org/01903/v1.3.2#',
            $doc->documentElement->getAttribute('xmlns:xades'),
        );
    }

    public function test_sin_acuse_no_se_arma(): void
    {
        // Un contenedor sin el acuse de la DIAN no acredita nada: es
        // mejor no entregarlo que entregar uno que parece completo.
        $documento = $this->documentoValidado();
        $documento->forceFill(['dian_response_xml' => null])->save();

        $this->assertFalse(app(AttachedDocumentBuilder::class)->disponiblePara($documento));

        $this->expectException(RuntimeException::class);

        app(AttachedDocumentBuilder::class)->construir($documento, $this->certificado(), 'clave');
    }

    public function test_un_documento_no_aceptado_tampoco(): void
    {
        $factura = $this->facturaElectronica();

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail();

        $this->assertFalse(app(AttachedDocumentBuilder::class)->disponiblePara($documento));
    }
}
