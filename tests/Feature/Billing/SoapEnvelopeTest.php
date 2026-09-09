<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\Transport\SoapDianTransport;
use App\Billing\Dian\Transport\TransmissionResult;
use App\Models\Branch;
use App\Models\DianCertificate;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El sobre SOAP que se le manda a la DIAN.
 *
 * QUÉ SE PUEDE COMPROBAR Y QUÉ NO
 * -------------------------------
 * No se puede saber si la DIAN lo acepta: su URL no está publicada —la
 * expone dentro de la cuenta del catálogo de cada facturador—, así que
 * no hay contra qué probar.
 *
 * Lo que sí se puede, interceptando la petición antes de que salga:
 *
 *   · que el sobre sea XML bien formado,
 *   · que lleve la firma WS-Security con el certificado dentro,
 *   · que esa firma **verifique** con su propia clave pública,
 *   · que el documento viaje comprimido y en base64, como pide
 *     `SendBillSync`,
 *   · y que la respuesta se interprete bien: que un 200 con
 *     `IsValid=false` sea un RECHAZO y no un éxito.
 *
 * Ese último es el que más importa de todos: tratar cualquier 200 como
 * aceptado deja documentos marcados como validados que no lo están.
 */
class SoapEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc';

    protected function setUp(): void
    {
        parent::setUp();

        config(['dian.endpoint' => self::URL, 'dian.transport' => 'auto']);
    }

    // ==================== El sobre ====================

    public function test_el_sobre_es_xml_bien_formado_y_va_firmado(): void
    {
        $sobre = $this->capturarSobre();

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($sobre), 'El sobre no es XML válido.');

        $this->assertStringContainsString('wsse:Security', $sobre);
        $this->assertStringContainsString('wsse:BinarySecurityToken', $sobre);
        $this->assertStringContainsString('wsu:Timestamp', $sobre);
        $this->assertStringContainsString('SendBillSync', $sobre);
    }

    /**
     * El sobre, contra la politica que el servicio publica.
     *
     * DE DONDE SALEN ESTAS TRES REGLAS
     * --------------------------------
     * Del WSDL del propio servicio, que declara su WS-Policy:
     *
     *   curl -s "https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc?wsdl=wsdl0"
     *
     *   <sp:AlgorithmSuite><sp:Basic256Sha256Rsa15/>
     *   <sp:X509Token><sp:RequireThumbprintReference/>
     *   <sp:SignedParts><sp:Header Name="To" .../>
     *
     * Se llego ahi despues de tres intentos a ciegas —firmar solo To,
     * anadir Action, cambiar a SHA-1— todos rechazados con el mismo
     * `wsse:InvalidSecurity`, que no dice nada. La respuesta llevaba
     * todo el tiempo publicada en el WSDL.
     *
     * Por eso estas pruebas citan la politica: si alguien las cambia,
     * que sepa contra que las esta cambiando.
     */
    /**
     * El sobre, comparado con la implementacion que SI funciona.
     *
     * DE DONDE SALEN ESTAS REGLAS
     * ---------------------------
     * De `lopezsoft/ubl21dian`, la libreria PHP que usan buena parte de
     * las integraciones colombianas contra el servicio real.
     *
     * NO de deducciones. Se intento deducirlas y la DIAN contesto
     * cuatro veces `wsse:InvalidSecurity` —que no dice nada—: se probo
     * firmando solo `To`, anadiendo `Action`, cambiando la suite a
     * SHA-1, y referenciando el certificado por huella segun la
     * politica que el propio WSDL publica. Ninguna paso.
     *
     * Por eso estas pruebas fijan la estructura al detalle: la
     * diferencia entre que la DIAN acepte o conteste `InvalidSecurity`
     * puede ser un atributo, y sin esto nadie sabria contra que
     * comparar.
     */
    public function test_se_firma_solo_el_destino(): void
    {
        // Una sola referencia, a `wsa:To`. Ni el Timestamp, ni el
        // cuerpo: firmar de mas tambien es no cuadrar.
        $sobre = $this->capturarSobre();

        $doc = new \DOMDocument();
        $doc->loadXML($sobre);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('wsu', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');

        $referencias = $xpath->query('//ds:Reference');

        $this->assertSame(1, $referencias->length, 'Deberia haber UNA referencia: la de wsa:To.');

        $uri = ltrim($referencias->item(0)->getAttribute('URI'), '#');
        $apuntado = $xpath->query(sprintf('//*[@wsu:Id="%s"]', $uri))->item(0);

        $this->assertSame('To', $apuntado?->localName);
    }

    public function test_lleva_las_listas_de_prefijos(): void
    {
        // `ec:InclusiveNamespaces` en la canonicalizacion y en la
        // transformada. Sin ellas el otro lado incluye espacios de
        // nombres que nosotros no, los resumenes no cuadran, y la DIAN
        // contesta `InvalidSecurity` sin decir por que.
        $sobre = $this->capturarSobre();

        $this->assertStringContainsString('PrefixList="wsa soap wcf"', $sobre);
        $this->assertStringContainsString('PrefixList="soap wcf"', $sobre);
    }

    public function test_el_certificado_se_referencia_por_uri(): void
    {
        // `wsse:Reference`, no `KeyIdentifier` con huella.
        //
        // La politica del WSDL pide `RequireThumbprintReference` y se
        // probo asi: la DIAN lo rechazo igual. La implementacion que
        // funciona usa `Reference`, y manda lo que funciona.
        $sobre = $this->capturarSobre();

        $this->assertStringContainsString('<wsse:Reference URI="#X509-', $sobre);
        $this->assertStringNotContainsString('KeyIdentifier', $sobre);
    }

    public function test_el_orden_de_la_cabecera_es_security_action_to(): void
    {
        // El orden forma parte de lo que se firma.
        $sobre = $this->capturarSobre();

        $doc = new \DOMDocument();
        $doc->loadXML($sobre);

        $xpath = new \DOMXPath($doc);
        $hijos = [];

        foreach ($xpath->query("//*[local-name()='Header']/*") as $nodo) {
            $hijos[] = $nodo->localName;
        }

        $this->assertSame(['Security', 'Action', 'To'], $hijos);
    }

    public function test_la_firma_del_sobre_verifica(): void
    {
        // Es la mecanica que la DIAN comprueba del otro lado: si no
        // verifica aqui, alli tampoco.
        //
        // OJO CON LA CANONICALIZACION. El SignedInfo NO se verifica con
        // `C14N(true)` sobre el nodo en su sitio: se reserializa suelto
        // con sus espacios de nombres inyectados y se canonicaliza de
        // forma INCLUSIVA, que es lo que hace el firmador y lo que
        // produce los mismos bytes que un exc-c14n con esa lista de
        // prefijos. Verificarlo de otra forma daria un fallo que no
        // existe.
        $sobre = $this->capturarSobre();

        $doc = new \DOMDocument();
        $doc->loadXML($sobre);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $info = $xpath->query('//ds:SignedInfo')->item(0);
        $valor = base64_decode($xpath->query('//ds:SignatureValue')->item(0)->nodeValue);
        $certificado = $xpath->query("//*[local-name()='BinarySecurityToken']")->item(0)->nodeValue;

        $publica = openssl_pkey_get_public(
            "-----BEGIN CERTIFICATE-----\n" . chunk_split(trim($certificado), 64, "\n") . "-----END CERTIFICATE-----\n"
        );

        $this->assertNotFalse($publica, 'El certificado del sobre no se pudo leer.');

        $canonico = $this->canonizarComoElFirmador(
            $doc->saveXML($info),
            '<ds:SignedInfo',
            [
                'xmlns:ds' => 'http://www.w3.org/2000/09/xmldsig#',
                'xmlns:wsa' => 'http://www.w3.org/2005/08/addressing',
                'xmlns:soap' => 'http://www.w3.org/2003/05/soap-envelope',
                'xmlns:wcf' => 'http://wcf.dian.colombia',
            ],
        );

        $this->assertSame(
            1,
            openssl_verify($canonico, $valor, $publica, OPENSSL_ALGO_SHA256),
            'La firma del sobre SOAP no verifica.',
        );
    }

    /** @param array<string, string> $espacios */
    private function canonizarComoElFirmador(string $xml, string $etiqueta, array $espacios): string
    {
        $declaraciones = [];

        foreach ($espacios as $prefijo => $uri) {
            $declaraciones[] = sprintf('%s="%s"', $prefijo, $uri);
        }

        $suelto = new \DOMDocument('1.0', 'UTF-8');
        $suelto->loadXML(str_replace($etiqueta, $etiqueta . ' ' . implode(' ', $declaraciones) . ' ', $xml));

        return $suelto->C14N();
    }

    public function test_la_accion_va_en_el_content_type(): void
    {
        // Es SOAP 1.2: la acción va como parámetro del Content-Type, no
        // en una cabecera SOAPAction. La guía de la DIAN lo dice
        // explícitamente para implementaciones propias.
        $capturada = null;

        Http::fake(function (Request $peticion) use (&$capturada) {
            $capturada = $peticion;

            return Http::response($this->respuestaAceptada(), 200);
        });

        $this->transmitir();

        $this->assertStringContainsString('application/soap+xml', $capturada->header('Content-Type')[0]);
        $this->assertStringContainsString(
            'action="http://wcf.dian.colombia/IWcfDianCustomerServices/SendBillSync"',
            $capturada->header('Content-Type')[0],
        );
    }

    public function test_el_documento_viaja_comprimido(): void
    {
        // SendBillSync recibe el XML dentro de un ZIP en base64, no el
        // XML suelto.
        $sobre = $this->capturarSobre();

        $doc = new \DOMDocument();
        $doc->loadXML($sobre);

        $xpath = new \DOMXPath($doc);
        $contenido = $xpath->query("//*[local-name()='contentFile']")->item(0)->nodeValue;

        $zip = base64_decode($contenido, true);

        $this->assertNotFalse($zip, 'El contenido no es base64.');
        // Todo ZIP empieza por «PK».
        $this->assertSame('PK', substr($zip, 0, 2), 'El contenido no es un ZIP.');
    }

    // ==================== La respuesta ====================

    public function test_un_doscientos_con_isvalid_false_es_un_rechazo(): void
    {
        // ES EL MÁS IMPORTANTE. La DIAN contesta 200 aunque haya
        // rechazado el documento: tratar cualquier 200 como aceptado
        // dejaría documentos marcados como validados que no lo están.
        Http::fake([self::URL => Http::response($this->respuestaRechazada(), 200)]);

        $resultado = $this->transmitir();

        $this->assertSame(TransmissionResult::RECHAZADO, $resultado->resultado);
        $this->assertContains('Regla: FAJ40, el NIT no existe', $resultado->errores);
        $this->assertTrue($resultado->esDefinitivo(), 'Un rechazo no debe reintentarse.');
    }

    public function test_un_doscientos_con_isvalid_true_es_aceptado(): void
    {
        Http::fake([self::URL => Http::response($this->respuestaAceptada(), 200)]);

        $resultado = $this->transmitir();

        $this->assertSame(TransmissionResult::ACEPTADO, $resultado->resultado);
        $this->assertSame('abc123', $resultado->trackId);
    }

    public function test_un_error_del_servidor_se_reintenta(): void
    {
        Http::fake([self::URL => Http::response('cayose', 503)]);

        $resultado = $this->transmitir();

        $this->assertSame(TransmissionResult::ERROR, $resultado->resultado);
        $this->assertSame(503, $resultado->httpStatus);
        $this->assertTrue($resultado->sePuedeReintentar());
    }

    public function test_sin_endpoint_no_intenta_salir(): void
    {
        config(['dian.endpoint' => '', 'dian.endpoints.habilitacion' => '', 'dian.endpoints.produccion' => '']);
        Http::fake();

        $resultado = $this->transmitir();

        $this->assertSame(TransmissionResult::ERROR, $resultado->resultado);
        Http::assertNothingSent();
    }

    public function test_sin_certificado_no_intenta_salir(): void
    {
        Http::fake();

        $resultado = (new SoapDianTransport())->enviar($this->documento(conCertificado: false));

        $this->assertSame(TransmissionResult::ERROR, $resultado->resultado);
        $this->assertStringContainsString('certificado', $resultado->errores[0]);
        Http::assertNothingSent();
    }

    // ==================== La URL, por ambiente ====================

    public function test_habilitacion_y_produccion_son_servicios_distintos(): void
    {
        // ES LO QUE HACE QUE ESTO FUNCIONE EN MULTIEMPRESA: la empresa A
        // puede estar pasando su set de pruebas mientras la B ya factura
        // de verdad. Con una sola URL global, encender la produccion de
        // una habria mandado a produccion los documentos de prueba de la
        // otra.
        config(['dian.endpoint' => '']);

        $endpoints = new \App\Billing\Dian\Transport\DianEndpoints();

        $habilitacion = $endpoints->para(\App\Models\DianConfiguration::PRUEBAS);
        $produccion = $endpoints->para(\App\Models\DianConfiguration::PRODUCCION);

        $this->assertNotSame($habilitacion, $produccion);
        $this->assertStringContainsString('vpfe-hab', $habilitacion);
        $this->assertStringNotContainsString('vpfe-hab', $produccion);
    }

    public function test_el_wsdl_se_quita_de_la_url(): void
    {
        // Es la direccion que publica la DIAN y la que uno copia sin
        // pensarlo, pero apunta a la DEFINICION del servicio. Mandar el
        // documento ahi no falla de forma evidente: contesta con el
        // WSDL, y el error resultante no dice nada de esto.
        config(['dian.endpoint' => 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc?wsdl']);

        $url = (new \App\Billing\Dian\Transport\DianEndpoints())->para('2');

        $this->assertSame('https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc', $url);
    }

    public function test_viene_puesta_de_fabrica(): void
    {
        // Son URL publicas, iguales para todos los contribuyentes: nadie
        // tiene que copiarlas de ningun sitio para empezar.
        config(['dian.endpoint' => '']);

        $this->assertTrue(
            (new \App\Billing\Dian\Transport\DianEndpoints())->hayPara('2'),
        );
    }

    public function test_el_override_global_gana(): void
    {
        // El escape para apuntar a un intermediario o a un entorno
        // propio de pruebas.
        config(['dian.endpoint' => 'https://mi-intermediario.example/servicio']);

        $endpoints = new \App\Billing\Dian\Transport\DianEndpoints();

        $this->assertSame('https://mi-intermediario.example/servicio', $endpoints->para('1'));
        $this->assertSame('https://mi-intermediario.example/servicio', $endpoints->para('2'));
    }

    public function test_el_documento_sale_a_la_url_de_su_ambiente(): void
    {
        // No a la del ambiente actual de la empresa: al del DOCUMENTO,
        // que quedo congelado al emitirlo.
        config(['dian.endpoint' => '']);

        $capturada = null;
        Http::fake(function (Request $peticion) use (&$capturada) {
            $capturada = $peticion->url();

            return Http::response($this->respuestaAceptada(), 200);
        });

        (new SoapDianTransport())->enviar($this->documento());

        // El documento de $this->documento() es de ambiente '2'.
        $this->assertStringContainsString('vpfe-hab', $capturada);
    }

    // ==================== Apoyo ====================

    private function transmitir(): TransmissionResult
    {
        return (new SoapDianTransport())->enviar($this->documento());
    }

    private function capturarSobre(): string
    {
        $sobre = null;

        Http::fake(function (Request $peticion) use (&$sobre) {
            $sobre = $peticion->body();

            return Http::response($this->respuestaAceptada(), 200);
        });

        $this->transmitir();

        $this->assertNotNull($sobre, 'No se capturó ninguna petición.');

        return $sobre;
    }

    private function documento(bool $conCertificado = true): ElectronicDocument
    {
        $factura = Invoice::factory()->create();

        $documento = ElectronicDocument::withoutGlobalScopes()->create([
            'company_id' => $factura->company_id,
            'invoice_id' => $factura->id,
            'environment_code' => '2',
            'cufe' => str_repeat('a', 96),
            'signed_xml' => '<?xml version="1.0"?><Invoice><cbc:ID xmlns:cbc="urn:x">SETP1</cbc:ID></Invoice>',
            'status' => ElectronicDocument::FIRMADO,
            'dian_certificate_id' => $conCertificado ? $this->certificado($factura->company_id)->id : null,
            'signed_at' => now(),
        ]);

        return $documento->fresh();
    }

    /**
     * El .p12 de las pruebas, generado UNA vez por corrida.
     *
     * Generar una pareja de claves RSA cuesta segundos en esta maquina,
     * y esta clase arma un sobre en casi cada prueba. Con uno nuevo por
     * prueba la clase pasaba de minutos y PHPUnit moria por tiempo — y
     * las pruebas que quedaban sin correr salen como fallidas, que
     * parece un fallo del codigo y no lo es.
     *
     * Se guarda en una propiedad estatica: el certificado no forma
     * parte de lo que se comprueba, solo hace falta que sea valido.
     */
    private static ?string $p12Compartido = null;

    private function certificado(int $empresaId): DianCertificate
    {
        if (self::$p12Compartido !== null) {
            return $this->guardarCertificado($empresaId, self::$p12Compartido);
        }

        $opciones = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $llave = @openssl_pkey_new($opciones);

        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $ruta) {
            if ($llave === false && $ruta && is_file($ruta)) {
                $opciones['config'] = $ruta;
                $llave = @openssl_pkey_new($opciones);
            }
        }

        if ($llave === false) {
            $this->markTestSkipped('OpenSSL no puede generar claves en esta máquina.');
        }

        $extra = isset($opciones['config']) ? ['config' => $opciones['config']] : [];

        $solicitud = openssl_csr_new(['countryName' => 'CO', 'commonName' => 'Prueba'], $llave, $extra + ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($solicitud, null, $llave, 365, $extra + ['digest_alg' => 'sha256']);

        $p12 = '';
        openssl_pkcs12_export($cert, $p12, $llave, 'prueba', $extra);

        self::$p12Compartido = $p12;

        return $this->guardarCertificado($empresaId, $p12);
    }

    /** Escribe el .p12 y lo registra para la empresa. */
    private function guardarCertificado(int $empresaId, string $p12): DianCertificate
    {
        $carpeta = storage_path('app/certificados-prueba');
        @mkdir($carpeta, 0755, true);
        $archivo = $carpeta . '/soap-' . uniqid() . '.p12';
        file_put_contents($archivo, $p12);

        return DianCertificate::create([
            'company_id' => $empresaId,
            'name' => 'Certificado de prueba',
            'path' => $archivo,
            'password' => 'prueba',
            'valid_from' => now()->subMonth(),
            'valid_until' => now()->addYear(),
            'active' => true,
        ]);
    }

    private function respuestaAceptada(): string
    {
        return <<<'XML'
        <?xml version="1.0"?>
        <s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">
          <s:Body>
            <SendBillSyncResponse xmlns="http://wcf.dian.colombia">
              <SendBillSyncResult>
                <IsValid>true</IsValid>
                <XmlDocumentKey>abc123</XmlDocumentKey>
                <StatusDescription>Procesado Correctamente.</StatusDescription>
              </SendBillSyncResult>
            </SendBillSyncResponse>
          </s:Body>
        </s:Envelope>
        XML;
    }

    private function respuestaRechazada(): string
    {
        return <<<'XML'
        <?xml version="1.0"?>
        <s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">
          <s:Body>
            <SendBillSyncResponse xmlns="http://wcf.dian.colombia">
              <SendBillSyncResult>
                <IsValid>false</IsValid>
                <XmlDocumentKey>def456</XmlDocumentKey>
                <ErrorMessage xmlns:a="http://schemas.microsoft.com/2003/10/Serialization/Arrays">
                  <a:string>Regla: FAJ40, el NIT no existe</a:string>
                </ErrorMessage>
              </SendBillSyncResult>
            </SendBillSyncResponse>
          </s:Body>
        </s:Envelope>
        XML;
    }
}
