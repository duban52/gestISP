<?php

namespace App\Billing\Dian\Transport;

use App\Models\DianCertificate;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Firma WS-Security del sobre SOAP que se le manda a la DIAN.
 *
 * NO ES LA FIRMA DEL DOCUMENTO
 * ----------------------------
 * El documento va firmado con XAdES y eso lo protege para siempre.
 * Esto autentica la LLAMADA, y por eso lleva un `Timestamp` con
 * vencimiento: sin él, una petición capturada podría reenviarse.
 *
 * DE DÓNDE SALE ESTA ESTRUCTURA, Y POR QUÉ IMPORTA
 * ------------------------------------------------
 * De `lopezsoft/ubl21dian`, la librería PHP que usa buena parte de las
 * integraciones colombianas y que **funciona contra el servicio real**.
 * No de la guía de la DIAN, que muestra estos valores en una imagen que
 * no se puede leer del PDF.
 *
 * Antes de llegar aquí se intentó deducirla, y la DIAN contestó cuatro
 * veces `wsse:InvalidSecurity` —«An error occurred when verifying
 * security for the message»— que no dice absolutamente nada de qué
 * falla. Se probó firmando `To`; añadiendo `Action`; cambiando la suite
 * a SHA-1; y referenciando el certificado por huella según la política
 * que el propio WSDL publica. Ninguna pasó.
 *
 * Las cinco diferencias con lo que hacíamos, y todas cuentan:
 *
 *   1. Se firma **solo `wsa:To`**. Ni el Timestamp, ni el cuerpo.
 *   2. `ec:InclusiveNamespaces` con su `PrefixList` en la
 *      canonicalización y en la transformada. Sin eso los resúmenes no
 *      cuadran, porque el otro lado incluye espacios de nombres que
 *      nosotros no incluíamos.
 *   3. El resumen se calcula sobre el nodo **reserializado con sus
 *      espacios de nombres inyectados** y canonicalización INCLUSIVA —
 *      que es lo que produce los mismos bytes que el exc-c14n con esa
 *      lista de prefijos.
 *   4. El certificado se referencia con `wsse:Reference`, no por
 *      huella. (La política del WSDL dice `RequireThumbprintReference`;
 *      la implementación que funciona usa `Reference`. Manda lo que
 *      funciona.)
 *   5. El orden en la cabecera es `Security`, `Action`, `To` — y sin
 *      `mustUnderstand` en ninguno.
 *
 * SI HAY QUE TOCAR ESTO
 * ---------------------
 * Compárelo primero contra esa librería. Cada detalle de aquí está
 * porque allí está, y la diferencia entre que la DIAN acepte o conteste
 * `InvalidSecurity` puede ser un atributo.
 */
class XmlSecuritySigner
{
    private const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_SOAP = 'http://www.w3.org/2003/05/soap-envelope';
    private const NS_WSA = 'http://www.w3.org/2005/08/addressing';
    private const NS_WCF = 'http://wcf.dian.colombia';

    private const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    private const METODO_FIRMA = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const METODO_RESUMEN = 'http://www.w3.org/2001/04/xmlenc#sha256';

    private const TIPO_X509 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    private const CODIFICACION = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    /**
     * Espacios de nombres que se inyectan al canonicalizar.
     *
     * Son los del `PrefixList`: al calcular el resumen sobre el nodo
     * suelto hay que declararlos a mano, porque fuera del sobre ya no
     * los hereda de ningún sitio.
     */
    private const NS_DE_TO = [
        'xmlns:wsa' => self::NS_WSA,
        'xmlns:soap' => self::NS_SOAP,
        'xmlns:wcf' => self::NS_WCF,
    ];

    private const NS_DE_SIGNEDINFO = [
        'xmlns:ds' => self::NS_DS,
        'xmlns:wsa' => self::NS_WSA,
        'xmlns:soap' => self::NS_SOAP,
        'xmlns:wcf' => self::NS_WCF,
    ];

    /**
     * Firma el sobre y lo devuelve como XML.
     *
     * Rehace la cabecera entera: el orden y los espacios de nombres son
     * parte de lo que se firma, así que no puede quedar a merced de
     * cómo la haya armado quien llame.
     *
     * @param  int  $vigenciaSegundos  Cuánto vale la petición.
     */
    public function firmar(\DOMDocument $doc, DianCertificate $certificado, int $vigenciaSegundos = 60): string
    {
        [$pem, $clave] = $this->abrir($certificado);

        [$accion, $destino] = $this->datosDeDireccionamiento($doc);

        $cabecera = $this->rehacerCabecera($doc);

        // ---- Security, y dentro el Timestamp y el token ----
        $seguridad = $doc->createElementNS(self::NS_WSSE, 'wsse:Security');
        $seguridad->setAttribute('xmlns:wsu', self::NS_WSU);
        $cabecera->appendChild($seguridad);

        $ahora = Carbon::now('UTC');
        $marca = $doc->createElement('wsu:Timestamp');
        $marca->setAttribute('wsu:Id', 'TS-' . bin2hex(random_bytes(6)));
        $seguridad->appendChild($marca);
        $marca->appendChild($doc->createElement('wsu:Created', $ahora->format('Y-m-d\TH:i:s\Z')));
        $marca->appendChild($doc->createElement('wsu:Expires', $ahora->copy()->addSeconds($vigenciaSegundos)->format('Y-m-d\TH:i:s\Z')));

        $idToken = 'X509-' . bin2hex(random_bytes(8));
        $token = $doc->createElement('wsse:BinarySecurityToken', $this->base64Del($pem));
        $token->setAttribute('EncodingType', self::CODIFICACION);
        $token->setAttribute('ValueType', self::TIPO_X509);
        $token->setAttribute('wsu:Id', $idToken);
        $seguridad->appendChild($token);

        // ---- Action y To, DESPUES de Security ----
        $cabecera->appendChild($doc->createElement('wsa:Action', $accion));

        $idDestino = 'ID-' . bin2hex(random_bytes(6));
        $to = $doc->createElement('wsa:To', $destino);
        $to->setAttribute('wsu:Id', $idDestino);
        $to->setAttribute('xmlns:wsu', self::NS_WSU);
        $cabecera->appendChild($to);

        $this->firma($doc, $seguridad, $to, $idDestino, $idToken, $clave);

        return $doc->saveXML();
    }

    // ==================== La firma ====================

    private function firma(
        \DOMDocument $doc,
        \DOMElement $seguridad,
        \DOMElement $to,
        string $idDestino,
        string $idToken,
        string $clave,
    ): void {
        $firma = $doc->createElement('ds:Signature');
        $firma->setAttribute('Id', 'SIG-' . bin2hex(random_bytes(6)));
        $firma->setAttribute('xmlns:ds', self::NS_DS);
        $seguridad->appendChild($firma);

        $info = $doc->createElement('ds:SignedInfo');
        $firma->appendChild($info);

        // Canonicalizacion, con su lista de prefijos.
        $c14n = $doc->createElement('ds:CanonicalizationMethod');
        $c14n->setAttribute('Algorithm', self::EXC_C14N);
        $info->appendChild($c14n);
        $c14n->appendChild($this->prefijos($doc, 'wsa soap wcf'));

        $metodo = $doc->createElement('ds:SignatureMethod');
        $metodo->setAttribute('Algorithm', self::METODO_FIRMA);
        $info->appendChild($metodo);

        // ---- La UNICA referencia: wsa:To ----
        $referencia = $doc->createElement('ds:Reference');
        $referencia->setAttribute('URI', '#' . $idDestino);
        $info->appendChild($referencia);

        $transformadas = $doc->createElement('ds:Transforms');
        $referencia->appendChild($transformadas);

        $transformada = $doc->createElement('ds:Transform');
        $transformada->setAttribute('Algorithm', self::EXC_C14N);
        $transformadas->appendChild($transformada);
        $transformada->appendChild($this->prefijos($doc, 'soap wcf'));

        $resumen = $doc->createElement('ds:DigestMethod');
        $resumen->setAttribute('Algorithm', self::METODO_RESUMEN);
        $referencia->appendChild($resumen);

        $referencia->appendChild($doc->createElement(
            'ds:DigestValue',
            base64_encode(hash('sha256', $this->canonizar($doc->saveXML($to), '<wsa:To ', self::NS_DE_TO), true)),
        ));

        // ---- La firma del SignedInfo ----
        $firmado = '';

        $canonico = $this->canonizar($doc->saveXML($info), '<ds:SignedInfo', self::NS_DE_SIGNEDINFO);

        if (!openssl_sign($canonico, $firmado, $clave, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar la petición a la DIAN.');
        }

        $firma->appendChild($doc->createElement('ds:SignatureValue', base64_encode($firmado)));

        // ---- KeyInfo, DESPUES del SignatureValue ----
        $keyInfo = $doc->createElement('ds:KeyInfo');
        $keyInfo->setAttribute('Id', 'KI-' . bin2hex(random_bytes(6)));
        $firma->appendChild($keyInfo);

        $referenciaToken = $doc->createElement('wsse:SecurityTokenReference');
        $referenciaToken->setAttribute('wsu:Id', 'STR-' . bin2hex(random_bytes(6)));
        $keyInfo->appendChild($referenciaToken);

        $apunta = $doc->createElement('wsse:Reference');
        $apunta->setAttribute('URI', '#' . $idToken);
        $apunta->setAttribute('ValueType', self::TIPO_X509);
        $referenciaToken->appendChild($apunta);
    }

    /**
     * El nodo canonicalizado, con sus espacios de nombres inyectados.
     *
     * ESTE ES EL TRUCO, Y NO ES OBVIO
     * -------------------------------
     * El resumen NO se calcula con `C14N(true)` sobre el nodo dentro del
     * documento. Se serializa el nodo suelto, se le meten a mano las
     * declaraciones del `PrefixList`, y se canonicaliza de forma
     * INCLUSIVA.
     *
     * Da los mismos bytes que un exc-c14n con esa lista de prefijos —que
     * es lo que el otro lado calcula— y es como lo hace la
     * implementación que funciona. Con exc-c14n a secas los espacios de
     * nombres que no se «utilizan visiblemente» quedan fuera, el resumen
     * sale distinto, y la DIAN responde `InvalidSecurity` sin decir por
     * qué.
     *
     * @param  array<string, string>  $espacios
     */
    private function canonizar(string $xml, string $etiqueta, array $espacios): string
    {
        $declaraciones = [];

        foreach ($espacios as $prefijo => $uri) {
            $declaraciones[] = sprintf('%s="%s"', $prefijo, $uri);
        }

        $con = str_replace(
            $etiqueta,
            $etiqueta . ' ' . implode(' ', $declaraciones) . ' ',
            $xml,
        );

        $suelto = new \DOMDocument('1.0', 'UTF-8');

        if (!$suelto->loadXML($con)) {
            throw new RuntimeException('No se pudo preparar el nodo para firmarlo.');
        }

        return $suelto->C14N();
    }

    /** El `ec:InclusiveNamespaces` con su lista de prefijos. */
    private function prefijos(\DOMDocument $doc, string $lista): \DOMElement
    {
        $nodo = $doc->createElement('ec:InclusiveNamespaces');
        $nodo->setAttribute('PrefixList', $lista);
        $nodo->setAttribute('xmlns:ec', self::EXC_C14N);

        return $nodo;
    }

    // ==================== Apoyo ====================

    /**
     * La acción y el destino que traía el sobre.
     *
     * Se leen antes de rehacer la cabecera: los pone quien arma el
     * sobre —cada método del servicio tiene su acción— y aquí solo se
     * recolocan.
     *
     * @return array{0: string, 1: string}
     */
    private function datosDeDireccionamiento(\DOMDocument $doc): array
    {
        $xpath = new \DOMXPath($doc);

        $accion = $xpath->query("//*[local-name()='Action']")->item(0)?->nodeValue;
        $destino = $xpath->query("//*[local-name()='To']")->item(0)?->nodeValue;

        if (blank($accion) || blank($destino)) {
            throw new RuntimeException('El sobre no trae acción o destino: no se puede firmar.');
        }

        return [$accion, $destino];
    }

    /**
     * Deja una cabecera vacía, con `xmlns:wsa`, la primera del sobre.
     *
     * Se rehace en vez de reutilizar la que venga porque el orden de
     * sus hijos y sus espacios de nombres forman parte de lo que se
     * firma.
     */
    private function rehacerCabecera(\DOMDocument $doc): \DOMElement
    {
        $sobre = $doc->documentElement;

        foreach (iterator_to_array($sobre->childNodes) as $hijo) {
            if ($hijo instanceof \DOMElement && $hijo->localName === 'Header') {
                $sobre->removeChild($hijo);
            }
        }

        $cabecera = $doc->createElement('soap:Header');
        $cabecera->setAttribute('xmlns:wsa', self::NS_WSA);
        $sobre->insertBefore($cabecera, $sobre->firstChild);

        return $cabecera;
    }

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

    /** El certificado en base64, sin las cabeceras PEM. */
    private function base64Del(string $pem): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';
    }
}
