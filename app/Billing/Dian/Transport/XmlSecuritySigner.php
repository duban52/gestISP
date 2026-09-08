<?php

namespace App\Billing\Dian\Transport;

use App\Models\DianCertificate;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Firma WS-Security de un sobre SOAP.
 *
 * NO ES LA MISMA FIRMA QUE LA DE LA FACTURA
 * -----------------------------------------
 * Se parecen y no lo son, y confundirlas cuesta días:
 *
 * | | Factura (XAdES) | Sobre SOAP (WS-Security) |
 * |---|---|---|
 * | Canonicalización | **inclusiva** (C14N) | **exclusiva** (exc-c14n) |
 * | Qué se firma | documento, KeyInfo, SignedProperties | Timestamp, wsa:To, Body |
 * | La clave pública | dentro de `ds:X509Certificate` | en un `BinarySecurityToken` referenciado |
 * | Para qué | que el documento sea auténtico y perdure | autenticar ESTA petición, y caduca |
 *
 * El certificado es el mismo. Lo que cambia es para qué se usa: la
 * XAdES protege el documento para siempre; esta solo autentica la
 * llamada, y por eso lleva un `Timestamp` con vencimiento — sin él, una
 * petición capturada podría reenviarse indefinidamente.
 *
 * ⚠️ NO VERIFICADA CONTRA EL SERVICIO REAL
 * ----------------------------------------
 * La mecánica está tomada de la guía de consumo de servicios web de la
 * DIAN, pero los valores exactos que esa guía muestra —en una imagen—
 * para el tipo de identificador de clave y los algoritmos no se pueden
 * leer del PDF. Aquí van los estándar para este perfil. Es lo primero
 * que hay que revisar si la DIAN devuelve un error de autenticación.
 */
class XmlSecuritySigner
{
    private const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_SOAP = 'http://www.w3.org/2003/05/soap-envelope';
    private const NS_WSA = 'http://www.w3.org/2005/08/addressing';

    /** Canonicalización EXCLUSIVA: es la de WS-Security, no la de XAdES. */
    private const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    /**
     * Los algoritmos, segun la suite del binding de WCF.
     *
     * OJO: NO son los del documento. El documento va firmado con XAdES
     * y SHA-256 porque lo dice el anexo. Esto es la firma que autentica
     * la LLAMADA, y quien la decide es el binding de WCF que corre la
     * DIAN.
     *
     * La suite por defecto de WCF (Basic256) usa SHA-1. Si el mensaje
     * no encaja con la suite del binding, WCF contesta
     * `wsse:InvalidSecurity` sin decir que el problema sea el
     * algoritmo.
     *
     * Se elige por configuracion porque no se pudo confirmar: la guia
     * de consumo de la DIAN muestra estos valores en una imagen. Ver
     * `config/dian.php`.
     */
    private const ALGORITMOS = [
        'sha1' => [
            'firma' => 'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
            'resumen' => 'http://www.w3.org/2000/09/xmldsig#sha1',
            'php' => 'sha1',
            'openssl' => OPENSSL_ALGO_SHA1,
        ],
        'sha256' => [
            'firma' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'resumen' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            'php' => 'sha256',
            'openssl' => OPENSSL_ALGO_SHA256,
        ],
    ];

    private const TIPO_X509 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    /** Referencia al certificado por su huella. Lo exige `RequireThumbprintReference`. */
    private const TIPO_HUELLA = 'http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#ThumbprintSHA1';

    private const CODIFICACION = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    /**
     * La suite de algoritmos que se va a usar.
     *
     * @return array{firma: string, resumen: string, php: string, openssl: int}
     */
    private function suite(): array
    {
        $elegida = (string) config('dian.ws_security_hash', 'sha1');

        return self::ALGORITMOS[$elegida] ?? self::ALGORITMOS['sha1'];
    }

    /**
     * Firma el sobre y lo devuelve como XML.
     *
     * @param  int  $vigenciaSegundos  Cuánto vale la petición. La guía
     *   de la DIAN lo llama «tiempo de vigencia del token de seguridad».
     */
    public function firmar(\DOMDocument $doc, DianCertificate $certificado, int $vigenciaSegundos = 60): string
    {
        [$pem, $clave] = $this->abrir($certificado);

        $cabecera = $this->cabecera($doc);

        $id = 'X509-' . bin2hex(random_bytes(8));

        // ---- El token con el certificado ----
        $seguridad = $doc->createElementNS(self::NS_WSSE, 'wsse:Security');
        $seguridad->setAttributeNS(self::NS_SOAP, 'soap:mustUnderstand', 'true');
        $cabecera->appendChild($seguridad);

        // ---- Timestamp: hace que la peticion caduque ----
        $ahora = Carbon::now('UTC');
        $marca = $this->nodo($doc, $seguridad, self::NS_WSU, 'wsu:Timestamp');
        $this->identificar($marca, 'TS-' . bin2hex(random_bytes(6)));
        $this->nodo($doc, $marca, self::NS_WSU, 'wsu:Created', $ahora->format('Y-m-d\TH:i:s\Z'));
        $this->nodo($doc, $marca, self::NS_WSU, 'wsu:Expires', $ahora->copy()->addSeconds($vigenciaSegundos)->format('Y-m-d\TH:i:s\Z'));

        $token = $this->nodo($doc, $seguridad, self::NS_WSSE, 'wsse:BinarySecurityToken', $this->base64Del($pem));
        $token->setAttribute('EncodingType', self::CODIFICACION);
        $token->setAttribute('ValueType', self::TIPO_X509);
        $this->identificar($token, $id);

        // ---- Lo que se firma ----
        //
        // El Timestamp, TODOS los encabezados de direccionamiento y el
        // cuerpo.
        //
        // POR QUE TODOS LOS DE DIRECCIONAMIENTO, Y NO SOLO `To`
        // -----------------------------------------------------
        // Antes se firmaban solo `To` y el cuerpo, y la DIAN contestaba
        // 500 con `wsse:InvalidSecurity` — «An error occurred when
        // verifying security for the message».
        //
        // El servicio de la DIAN es WCF (su URL termina en `.svc`), y
        // WCF exige que vayan firmados TODOS los encabezados de
        // direccionamiento presentes, no solo el destino. Con
        // `wsa:Action` sin firmar, la verificacion falla antes de mirar
        // el documento — y el error no dice cual falta, solo que la
        // seguridad no cuadra.
        //
        // Se recogen por espacio de nombres y no por una lista de
        // nombres: si manana se anade `MessageID` o `ReplyTo` al sobre,
        // entra firmado solo. Una lista escrita a mano es como se vuelve
        // a caer en esto.
        // ---- Lo que se firma: EL TIMESTAMP Y `To` ----
        //
        // Lo dice la politica del servicio, que se puede leer en su
        // propio WSDL (`?wsdl=wsdl0`):
        //
        //   <sp:EndorsingSupportingTokens>
        //     <sp:SignedParts>
        //       <sp:Header Name="To" Namespace=".../addressing"/>
        //
        // Y el Timestamp porque el enlace lleva `<sp:IncludeTimestamp/>`
        // y un token «endorsing» sobre transporte firma precisamente la
        // marca de tiempo.
        //
        // NO va el cuerpo: el enlace es `sp:TransportBinding` con
        // HTTPS, asi que la confidencialidad e integridad del cuerpo
        // las da TLS, no la firma.
        //
        // Se llego aqui despues de probar con `To` solo, con `To` mas
        // `Action`, y con los dos mas el cuerpo — todos rechazados
        // igual. Adivinar no servia: la respuesta estaba publicada en
        // el WSDL del propio servicio.
        $firmados = array_filter([
            $marca,
            $this->porNombre($doc, 'To'),
        ]);

        foreach ($firmados as $nodo) {
            if (!$nodo->hasAttributeNS(self::NS_WSU, 'Id')) {
                $this->identificar($nodo, 'id-' . bin2hex(random_bytes(6)));
            }
        }

        $this->firma($doc, $seguridad, $firmados, $id, $clave, $pem);

        return $doc->saveXML();
    }

    // ==================== La firma ====================

    /**
     * @param  array<int, \DOMElement>  $firmados
     */
    private function firma(
        \DOMDocument $doc,
        \DOMElement $seguridad,
        array $firmados,
        string $idToken,
        string $clave,
        string $pem,
    ): void {
        $firma = $doc->createElementNS(self::NS_DS, 'ds:Signature');
        $seguridad->appendChild($firma);

        $info = $this->nodo($doc, $firma, self::NS_DS, 'ds:SignedInfo');

        $this->conAlgoritmo($doc, $info, 'ds:CanonicalizationMethod', self::EXC_C14N);
        $suite = $this->suite();

        $this->conAlgoritmo($doc, $info, 'ds:SignatureMethod', $suite['firma']);

        foreach ($firmados as $nodo) {
            $referencia = $this->nodo($doc, $info, self::NS_DS, 'ds:Reference');
            $referencia->setAttribute('URI', '#' . $nodo->getAttributeNS(self::NS_WSU, 'Id'));

            $transformadas = $this->nodo($doc, $referencia, self::NS_DS, 'ds:Transforms');
            $this->conAlgoritmo($doc, $transformadas, 'ds:Transform', self::EXC_C14N);

            $this->conAlgoritmo($doc, $referencia, 'ds:DigestMethod', $suite['resumen']);

            // Exclusiva: C14N(true). Con la inclusiva el resumen sale
            // distinto y la DIAN devuelve un fallo de firma que no dice
            // por que.
            $this->nodo($doc, $referencia, self::NS_DS, 'ds:DigestValue', base64_encode(
                hash($suite['php'], $nodo->C14N(true), true),
            ));
        }

        $valor = $this->nodo($doc, $firma, self::NS_DS, 'ds:SignatureValue', '');

        // ---- Como se encuentra la clave publica ----
        // ---- Como se identifica el certificado: POR HUELLA ----
        //
        // La politica del servicio lo exige literalmente:
        //
        //   <sp:X509Token ...>
        //     <sp:RequireThumbprintReference/>
        //   <sp:Wss11><sp:MustSupportRefThumbprint/>
        //
        // Antes se apuntaba con `wsse:Reference URI="#X509-..."`, que
        // es la forma directa y la mas comun — y la DIAN contestaba
        // `wsse:InvalidSecurity` sin decir por que. WCF valida el
        // mensaje contra su politica ANTES de verificar nada, y una
        // referencia que no es por huella no encaja.
        //
        // La huella es el SHA-1 del certificado en DER. Es SHA-1
        // aunque la suite sea SHA-256: no es un resumen criptografico
        // del mensaje, es el identificador estandar de un certificado
        // (el mismo que enseña Windows en «Huella digital»).
        $keyInfo = $this->nodo($doc, $firma, self::NS_DS, 'ds:KeyInfo');
        $referenciaToken = $this->nodo($doc, $keyInfo, self::NS_WSSE, 'wsse:SecurityTokenReference');

        $huella = $this->nodo(
            $doc,
            $referenciaToken,
            self::NS_WSSE,
            'wsse:KeyIdentifier',
            base64_encode(sha1(base64_decode($this->base64Del($pem)), true)),
        );
        $huella->setAttribute('EncodingType', self::CODIFICACION);
        $huella->setAttribute('ValueType', self::TIPO_HUELLA);

        // El SignedInfo se firma ya insertado, por lo mismo que en la
        // XAdES: la canonicalizacion arrastra los espacios de nombres
        // heredados.
        $firmado = '';

        if (!openssl_sign($info->C14N(true), $firmado, $clave, $suite['openssl'])) {
            throw new RuntimeException('No se pudo firmar la petición a la DIAN.');
        }

        $valor->appendChild($doc->createTextNode(base64_encode($firmado)));
    }

    // ==================== Apoyo ====================

    /** @return array{0: string, 1: string} certificado PEM y clave privada */
    private function abrir(DianCertificate $certificado): array
    {
        $ruta = is_file($certificado->path)
            ? $certificado->path
            : storage_path('app/' . ltrim($certificado->path, '/'));

        if (!is_file($ruta)) {
            throw new RuntimeException('El certificado no está en su ruta: no se puede autenticar contra la DIAN.');
        }

        $contenido = [];

        if (!openssl_pkcs12_read((string) file_get_contents($ruta), $contenido, (string) $certificado->password)) {
            throw new RuntimeException('No se pudo abrir el certificado para autenticar contra la DIAN.');
        }

        return [$contenido['cert'], $contenido['pkey']];
    }

    private function cabecera(\DOMDocument $doc): \DOMElement
    {
        $cabecera = $doc->getElementsByTagNameNS(self::NS_SOAP, 'Header')->item(0);

        if (!$cabecera) {
            throw new RuntimeException('El sobre SOAP no tiene cabecera.');
        }

        return $cabecera;
    }

    /**
     * Los encabezados de WS-Addressing que lleve el sobre.
     *
     * `wsa:Action`, `wsa:To`, y lo que se anada en el futuro. WCF los
     * quiere todos firmados; dejarse uno da `InvalidSecurity` sin decir
     * cual.
     *
     * @return array<int, \DOMElement>
     */
    private function encabezadosDeDireccionamiento(\DOMDocument $doc): array
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('wsa', self::NS_WSA);

        $nodos = [];

        foreach ($xpath->query("//*[local-name()='Header']/wsa:*") as $nodo) {
            if ($nodo instanceof \DOMElement) {
                $nodos[] = $nodo;
            }
        }

        return $nodos;
    }

    private function porNombre(\DOMDocument $doc, string $nombre): ?\DOMElement
    {
        $xpath = new \DOMXPath($doc);

        $nodo = $xpath->query("//*[local-name()='{$nombre}']")->item(0);

        return $nodo instanceof \DOMElement ? $nodo : null;
    }

    private function identificar(\DOMElement $nodo, string $id): void
    {
        $nodo->setAttributeNS(self::NS_WSU, 'wsu:Id', $id);
    }

    private function nodo(
        \DOMDocument $doc,
        \DOMElement $padre,
        string $espacio,
        string $nombre,
        ?string $valor = null,
    ): \DOMElement {
        $nodo = $doc->createElementNS($espacio, $nombre);

        if ($valor !== null && $valor !== '') {
            $nodo->appendChild($doc->createTextNode($valor));
        }

        $padre->appendChild($nodo);

        return $nodo;
    }

    private function conAlgoritmo(\DOMDocument $doc, \DOMElement $padre, string $nombre, string $algoritmo): void
    {
        $nodo = $this->nodo($doc, $padre, self::NS_DS, $nombre);
        $nodo->setAttribute('Algorithm', $algoritmo);
    }

    private function base64Del(string $pem): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';
    }
}
