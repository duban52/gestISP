<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\InvoiceXmlBuilder;
use App\Billing\Dian\XadesSigner;
use App\Billing\Services\InvoiceGenerator;
use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\FiscalCatalog;
use App\Models\NumberingRange;

/**
 * La firma XAdES del documento electrónico.
 *
 * QUÉ SE PUEDE COMPROBAR SIN UN CERTIFICADO REAL
 * ----------------------------------------------
 * Toda la mecánica, que es donde están los errores de verdad:
 *
 *   · que la firma verifique con su clave pública,
 *   · que los tres resúmenes se recalculen exactamente igual —que es
 *     donde falla el 90% de las implementaciones, por canonicalizar el
 *     nodo fuera del documento—,
 *   · que tocar el documento después de firmar rompa la firma,
 *   · y que el documento firmado siga validando contra el XSD.
 *
 * Se firma con un certificado autofirmado generado al vuelo. La DIAN lo
 * rechazaría, pero por POLÍTICA —no está emitido por una entidad
 * acreditada—, no por mecánica. Eso es lo único que queda por ver el
 * día que haya un .p12 de verdad.
 *
 * Y se firma un XML de VERDAD, generado por InvoiceXmlBuilder a partir
 * de una factura emitida, no un esqueleto de laboratorio.
 */
class XadesSignatureTest extends BillingTestCase
{
    private NumberingRange $rango;
    private DianConfiguration $configuracion;

    protected function setUp(): void
    {
        parent::setUp();

        FiscalCatalog::insert([
            ['catalog' => FiscalCatalog::DEPARTAMENTO, 'code' => '05', 'name' => 'Antioquia',
             'parent_code' => null, 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        Company::withoutGlobalScopes()->findOrFail($this->branch->company_id)->update([
            'legal_name' => 'Fibra Andina S.A.S.',
            'document_type_code' => '31',
            'document_number' => '900374637',
            'verification_digit' => '9',
            'organization_type_code' => '1',
            'address' => 'Calle 50 # 40-30',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'facturacion@fibraandina.co',
            'electronic_invoicing_enabled' => true,
        ]);

        $this->configuracion = DianConfiguration::create([
            'company_id' => $this->branch->company_id,
            'environment_code' => DianConfiguration::PRODUCCION,
            'enabled_at' => now(),
            'software_id' => 'fa326ca7-c1f8-40d3-a6fc-24d7c1040607',
            'software_pin' => '12345',
        ]);

        $resolucion = DianResolution::withoutGlobalScopes()->create([
            'company_id' => $this->branch->company_id,
            'resolution_number' => '18760000001',
            'document_type_code' => DianResolution::FACTURA,
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addYear(),
            'technical_key' => '693ff6f2a553c3646a063436fd4dd9ded0311471',
        ]);

        $this->rango = NumberingRange::withoutGlobalScopes()->create([
            'dian_resolution_id' => $resolucion->id,
            'branch_id' => $this->branch->id,
            'prefix' => 'SETP',
            'range_start' => 990000000,
            'range_end' => 995000000,
            'current_number' => 0,
        ]);
    }

    // ==================== La mecánica de la firma ====================

    public function test_la_firma_verifica_con_su_clave_publica(): void
    {
        // Es lo que hace que la firma sirva para algo: que se pueda
        // comprobar con la clave pública que viaja en el propio
        // documento.
        [$firmado] = $this->firmar();

        $xpath = $this->xpath($firmado);

        $info = $xpath->query('//ds:SignedInfo')->item(0);
        $valor = base64_decode($xpath->query('//ds:SignatureValue')->item(0)->nodeValue);
        $certificado = $xpath->query('//ds:X509Certificate')->item(0)->nodeValue;

        $publica = openssl_pkey_get_public($this->aPem($certificado));

        $this->assertSame(
            1,
            openssl_verify($info->C14N(), $valor, $publica, OPENSSL_ALGO_SHA256),
            'La firma no verifica con la clave pública del certificado que lleva dentro.',
        );
    }

    public function test_los_resumenes_de_las_referencias_se_recalculan_igual(): void
    {
        // Es donde falla casi todo el mundo: canonicalizar el nodo por
        // su cuenta, fuera del documento, da otro resumen porque se
        // pierden los espacios de nombres heredados.
        [$firmado] = $this->firmar();

        $xpath = $this->xpath($firmado);

        foreach (['keyinfo' => 1, 'signedprops' => 2] as $sufijo => $indice) {
            $nodo = $xpath->query('//*[substring(@Id, string-length(@Id) - ' . (strlen($sufijo) - 1) . ') = "' . $sufijo . '"]')->item(0);
            $this->assertNotNull($nodo, "No se encontró el nodo {$sufijo}.");

            $referencia = $xpath->query('//ds:SignedInfo/ds:Reference')->item($indice);
            $declarado = $xpath->query('ds:DigestValue', $referencia)->item(0)->nodeValue;

            $this->assertSame(
                base64_encode(hash($this->algoritmoDeclarado($xpath), $nodo->C14N(), true)),
                $declarado,
                "El resumen declarado de {$sufijo} no coincide con el que se recalcula.",
            );
        }
    }

    public function test_el_resumen_del_documento_cuadra_al_quitar_solo_la_firma(): void
    {
        // ESTA ES LA PRUEBA QUE FALTABA, y su ausencia costó un rechazo
        // de la DIAN con el código ZE02, «Valor de la firma inválido».
        //
        // Había una prueba de que alterar el documento cambia el
        // resumen, pero ninguna que comparase el resumen RECALCULADO
        // con el DECLARADO. Con eso, dos valores igual de equivocados
        // seguían siendo distintos entre sí y la prueba pasaba.
        //
        // Lo que se comprueba aquí es lo que hace el verificador del
        // otro lado: la transformada «enveloped-signature» quita
        // `ds:Signature` —y nada más—, canonicaliza lo que queda y
        // resume. Si el firmador añadiera al documento cualquier otra
        // cosa después de resumir —el envoltorio `ext:UBLExtension`,
        // por ejemplo—, aquí saltaría.
        [$firmado] = $this->firmar();

        $xpath = $this->xpath($firmado);
        $declarado = $xpath->query('//ds:SignedInfo/ds:Reference[@URI=""]/ds:DigestValue')->item(0);

        $this->assertNotNull($declarado, 'No hay referencia al documento (URI="").');

        $this->assertSame(
            $declarado->nodeValue,
            $this->resumenDelDocumento($firmado),
            'El resumen del documento no cuadra al quitar solo la firma: la DIAN lo rechazaría con ZE02.',
        );
    }

    public function test_la_firma_va_dentro_de_una_extension_que_ya_existia_al_resumir(): void
    {
        // El envoltorio de la firma tiene que estar en el documento
        // ANTES de resumirlo. Se comprueba por su efecto: al quitar
        // solo `ds:Signature` queda un `ext:ExtensionContent` vacío, y
        // el resumen —el de la prueba de arriba— sigue cuadrando.
        //
        // Se deja explícito porque es una condición de orden dentro del
        // firmador, y el orden no se ve leyendo el XML resultante.
        [$firmado] = $this->firmar();

        $doc = new \DOMDocument();
        $doc->loadXML($firmado);

        $firma = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
        $envoltorio = $firma->parentNode;

        $this->assertSame('ExtensionContent', $envoltorio->localName);

        $firma->parentNode->removeChild($firma);

        $this->assertSame(
            0,
            $envoltorio->childNodes->length,
            'Al quitar la firma su ExtensionContent tiene que quedar vacío, no desaparecer.',
        );
    }

    public function test_tocar_el_documento_despues_de_firmar_rompe_la_firma(): void
    {
        // Es la propiedad que hace que la firma proteja algo. Si se
        // pudiera cambiar el total y que la firma siguiera cuadrando,
        // no serviría para nada.
        [$firmado] = $this->firmar();

        $original = $this->resumenDelDocumento($firmado);

        // Se le baja el total a un peso, que es la alteracion que
        // alguien intentaria de verdad.
        $trucado = preg_replace(
            '/(<cbc:PayableAmount[^>]*>)[^<]+(<)/',
            '${1}1.00${2}',
            $firmado,
            1,
        );

        $this->assertNotSame($firmado, $trucado, 'No se pudo alterar el documento para la prueba.');

        $alterado = $this->resumenDelDocumento($trucado);

        $this->assertNotSame($original, $alterado, 'Cambiar el total no alteró el resumen del documento.');
    }

    // ==================== Lo que exige el anexo ====================

    public function test_lleva_las_tres_referencias(): void
    {
        // El documento, el KeyInfo y las SignedProperties. Firmar solo
        // el documento dejaría cambiar la hora de firma o el
        // certificado sin invalidar nada.
        $xpath = $this->xpath($this->firmar()[0]);

        $referencias = $xpath->query('//ds:SignedInfo/ds:Reference');

        $this->assertSame(3, $referencias->length);
        $this->assertSame('', $referencias->item(0)->getAttribute('URI'));
        $this->assertStringContainsString('keyinfo', $referencias->item(1)->getAttribute('URI'));
        $this->assertSame(
            'http://uri.etsi.org/01903#SignedProperties',
            $referencias->item(2)->getAttribute('Type'),
        );
    }

    public function test_declara_la_politica_de_firma_de_la_dian(): void
    {
        $firmado = $this->firmar()[0];

        // La RUTA, no solo el dominio. La que traen los XML de ejemplo
        // de la DIAN (`v1/`) responde 404; la viva es `v2/`, que es la
        // que dice el texto del anexo. Se fija aqui porque un
        // `SigPolicyId` que apunta a un documento inexistente es
        // exactamente lo que nadie mira hasta que la DIAN rechaza.
        $this->assertStringContainsString(
            'https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf',
            $firmado,
        );

        // El resumen SHA-256, verificado contra el PDF publicado
        // descargandolo y calculandolo (2026-09-08). Es el mismo valor,
        // caracter por caracter, que declara la DIAN en sus propias
        // firmas.
        $this->assertStringContainsString(
            'dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=',
            $firmado,
        );
        $this->assertStringContainsString('<xades:ClaimedRole>supplier</xades:ClaimedRole>', $firmado);
        $this->assertStringContainsString('<xades:SigningTime>', $firmado);
    }

    public function test_los_resumenes_van_en_sha256(): void
    {
        // AQUI HUBO SHA-384 Y LA DIAN RECHAZO CON ZE02.
        //
        // La firma era perfecta: verificaba y sus tres resumenes
        // cuadraban —se comprobo sobre el XML que la DIAN devolvio—.
        // Lo que no aceptaba era el ALGORITMO. Su propia firma usa
        // `xmlenc#sha256` en las tres referencias, en el CertDigest y
        // en el SigPolicyHash.
        //
        // El CUFE sigue siendo SHA-384: es otra cosa. Por eso esta
        // prueba mira dentro de la firma y no en todo el documento.
        $firmado = $this->firmar()[0];
        $xpath = $this->xpath($firmado);

        $algoritmos = [];

        foreach ($xpath->query('//ds:Signature//ds:DigestMethod/@Algorithm') as $atributo) {
            $algoritmos[] = $atributo->value;
        }

        $this->assertNotEmpty($algoritmos);
        $this->assertSame(
            ['http://www.w3.org/2001/04/xmlenc#sha256'],
            array_values(array_unique($algoritmos)),
            'Todos los resumenes de la firma van en SHA-256.',
        );

        $this->assertStringContainsString(
            'Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"',
            $firmado,
        );
    }

    public function test_los_espacios_de_nombres_de_xades_van_en_la_raiz(): void
    {
        // Y NO DENTRO DE LA FIRMA. Es la diferencia que quedaba con
        // `lopezsoft/ubl21dian` despues de igualar todo lo demas, y la
        // que explica un ZE02 con la firma matematicamente correcta.
        //
        // Si `xmlns:xades` se declara dentro de `ds:Signature`, lo que
        // hereda `xades:SignedProperties` al canonicalizarlo cambia, y
        // su resumen tambien. Declarandolo en la raiz, el conjunto es
        // el mismo que ve quien valida.
        $firmado = $this->firmar()[0];

        $doc = new \DOMDocument();
        $doc->loadXML($firmado);

        $this->assertSame(
            'http://uri.etsi.org/01903/v1.3.2#',
            $doc->documentElement->getAttribute('xmlns:xades'),
            'xmlns:xades tiene que estar declarado en la raiz del documento.',
        );

        $firma = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);

        $this->assertSame(
            '',
            $firma->getAttribute('xmlns:xades'),
            'Si la firma lo redeclara, la canonicalizacion vuelve a divergir.',
        );
    }

    public function test_el_certificado_viaja_dentro_del_documento(): void
    {
        // Sin él, nadie puede verificar la firma.
        $xpath = $this->xpath($this->firmar()[0]);

        $certificado = $xpath->query('//ds:X509Certificate')->item(0)->nodeValue;

        $this->assertNotEmpty($certificado);
        $this->assertNotFalse(openssl_x509_parse($this->aPem($certificado)));
    }

    public function test_la_firma_va_en_su_propia_extension(): void
    {
        // En la primera UBLExtension va el bloque de la DIAN; la firma
        // va en una segunda, que es donde la ubica el anexo.
        $xpath = $this->xpath($this->firmar()[0]);

        $extensiones = $xpath->query('//ext:UBLExtension');

        $this->assertSame(2, $extensiones->length);
        $this->assertSame(1, $xpath->query('.//ds:Signature', $extensiones->item(1))->length);
        $this->assertSame(1, $xpath->query('.//sts:DianExtensions', $extensiones->item(0))->length);
    }

    public function test_el_documento_firmado_sigue_validando_contra_el_xsd(): void
    {
        // Meter la firma no puede romper el UBL.
        $this->assertSame(
            [],
            app(InvoiceXmlBuilder::class)->erroresDeEsquema($this->firmar()[0]),
        );
    }

    // ==================== Cuando el certificado no sirve ====================

    public function test_una_contrasena_incorrecta_falla_diciendolo(): void
    {
        [$xml] = $this->facturaYxml();
        $p12 = $this->certificadoDePrueba('la-buena');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/contraseña|\.p12/');

        app(XadesSigner::class)->firmar($xml, $p12, 'la-mala');
    }

    // ==================== Apoyo ====================

    /** @return array{0: string, 1: string} XML firmado y XML original */
    private function firmar(): array
    {
        [$xml] = $this->facturaYxml();

        return [
            app(XadesSigner::class)->firmar($xml, $this->certificadoDePrueba(), 'prueba'),
            $xml,
        ];
    }

    /** @return array{0: string} */
    private function facturaYxml(): array
    {
        $grupo = AffinityGroup::factory()->electronico()->create([
            'company_id' => $this->branch->company_id,
        ]);

        $contrato = $this->createBillableContract(price: 100000, taxPercent: 19);
        $contrato->update(['affinity_group_id' => $grupo->id]);
        $contrato->client->update([
            'document_type_code' => '13',
            'organization_type_code' => '2',
            'fiscal_address' => 'Carrera 70 # 30-20',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'cliente@ejemplo.com',
        ]);

        $resultado = app(InvoiceGenerator::class)->generateForContract($contrato->fresh(), now(), $this->admin->id);
        $this->assertTrue($resultado['generated'], 'No se generó la factura.');

        $construido = app(InvoiceXmlBuilder::class)->construir(
            $resultado['invoice']->load('invoice_items', 'contract.client.taxResponsibilities'),
            $this->rango->fresh(),
            $this->configuracion->fresh(),
        );

        return [$construido['xml']];
    }

    /**
     * Un certificado autofirmado, generado al vuelo.
     *
     * La DIAN lo rechazaría —no lo emite una entidad acreditada—, pero
     * para comprobar la MECÁNICA de la firma sirve exactamente igual
     * que uno real.
     */
    private function certificadoDePrueba(string $clave = 'prueba'): string
    {
        // En Linux openssl encuentra su configuracion solo; en Windows
        // hay que decirle donde esta o no genera nada. Se prueba primero
        // sin decir nada, que es lo que funciona en el servidor.
        $opciones = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $llave = @openssl_pkey_new($opciones);

        if ($llave === false && ($configuracion = $this->configuracionDeOpenssl()) !== null) {
            $opciones['config'] = $configuracion;
            $llave = @openssl_pkey_new($opciones);
        }

        if ($llave === false) {
            $this->markTestSkipped('OpenSSL no puede generar claves en esta máquina (no se encontró openssl.cnf).');
        }

        $extra = isset($opciones['config']) ? ['config' => $opciones['config']] : [];

        $solicitud = openssl_csr_new([
            'countryName' => 'CO',
            'stateOrProvinceName' => 'Antioquia',
            'localityName' => 'Medellin',
            'organizationName' => 'Fibra Andina S.A.S.',
            'commonName' => 'Fibra Andina S.A.S.',
        ], $llave, $extra + ['digest_alg' => 'sha256']);

        $certificado = openssl_csr_sign($solicitud, null, $llave, 365, $extra + ['digest_alg' => 'sha256']);

        $p12 = '';
        openssl_pkcs12_export($certificado, $p12, $llave, $clave, $extra);

        return $p12;
    }

    /** Donde esta openssl.cnf, si hace falta decirlo. */
    private function configuracionDeOpenssl(): ?string
    {
        $candidatos = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ];

        foreach (array_filter($candidatos) as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    private function aPem(string $base64): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(trim($base64), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    private function xpath(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $xpath->registerNamespace('sts', 'dian:gov:co:facturaelectronica:Structures-2-1');

        return $xpath;
    }

    /** El resumen del documento con la firma excluida, como manda la transformada. */
    private function resumenDelDocumento(string $xml): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $algoritmo = $this->algoritmoDeclarado($this->xpath($xml));

        $firma = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
        $firma?->parentNode->removeChild($firma);

        return base64_encode(hash($algoritmo, $doc->documentElement->C14N(), true));
    }

    /**
     * El algoritmo de resumen que el propio documento declara.
     *
     * Se lee en vez de fijarlo aqui para que estas comprobaciones sigan
     * midiendo lo que dicen medir —que el resumen CUADRA— y no se
     * conviertan en una segunda copia del algoritmo que hay que
     * acordarse de cambiar. Cual debe ser lo fija
     * `test_los_resumenes_van_en_sha256`, y solo esa.
     */
    private function algoritmoDeclarado(\DOMXPath $xpath): string
    {
        $uri = $xpath->query('//ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm')->item(0)->value;

        return strtolower(substr($uri, strrpos($uri, '#') + 1));
    }
}
