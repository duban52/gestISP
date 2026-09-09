<?php

namespace App\Billing\Dian;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Firma XAdES-EPES de un documento electrónico de la DIAN.
 *
 * DE DÓNDE SALE ESTA ESTRUCTURA
 * -----------------------------
 * No de la teoría de XAdES, que admite muchas formas, sino de un XML
 * **firmado por la propia DIAN** de los que trae el paquete oficial
 * (`Ejemplificaciones/XMLs de ejemplo`). Se reprodujo elemento por
 * elemento: los tres `ds:Reference`, la cadena completa de certificados
 * dentro de `xades:SigningCertificate`, y el `SignerRole` anidado en
 * `ClaimedRoles` —que el texto del anexo, por cierto, dibuja plano—.
 *
 * QUÉ SE FIRMA
 * ------------
 * Tres cosas, y las tres van como referencia dentro de `SignedInfo`:
 *
 *   1. El documento entero, con la propia firma excluida (transformada
 *      «enveloped-signature»).
 *   2. El `KeyInfo`, que es donde va la clave pública.
 *   3. Las `SignedProperties`, donde van la hora de firma, el
 *      certificado y la política.
 *
 * Firmar solo el documento dejaría la puerta abierta a cambiar la hora
 * de firma o el certificado sin invalidar nada.
 *
 * EL ORDEN IMPORTA Y NO ES EL OBVIO
 * ---------------------------------
 * Los resúmenes se calculan sobre los nodos **ya insertados en el
 * documento**, no sobre XML suelto. La canonicalización arrastra las
 * declaraciones de espacios de nombres que el nodo hereda de sus
 * padres, así que un `SignedProperties` calculado por su cuenta da un
 * resumen distinto del que da dentro del documento — y la firma no
 * cuadra, sin ninguna pista de por qué.
 *
 * Por eso aquí se arma primero la estructura entera con los resúmenes
 * en blanco, se inserta, y solo entonces se calculan y se rellenan.
 *
 * LO QUE NO SE PUEDE COMPROBAR SIN UN CERTIFICADO REAL
 * ----------------------------------------------------
 * Que la DIAN la acepte. Lo que sí se comprueba en las pruebas —con un
 * certificado autofirmado generado al vuelo— es toda la mecánica: que
 * cada resumen se recalcula igual, que la firma verifica con su clave
 * pública, y que el documento firmado sigue validando contra el XSD.
 * Un certificado autofirmado la DIAN lo rechaza por política, no por
 * mecánica: eso es lo único que queda por ver el día que haya uno de
 * verdad.
 */
class XadesSigner
{
    /** Canonicalización: C14N sin comentarios. Lo fija el anexo (§10.7). */
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    /**
     * Algoritmos: SHA-256 en TODO, que es como firma la propia DIAN.
     *
     * AQUÍ HUBO SHA-384, Y COSTÓ UN RECHAZO
     * -------------------------------------
     * Se rechazó una factura con ZE02, «Valor de la firma inválido»,
     * teniendo la firma perfecta: se comprobó sobre el XML transmitido
     * que el RSA verificaba y que los tres resúmenes cuadraban. No
     * fallaba ningún valor: fallaba el ALGORITMO declarado.
     *
     * La prueba vino de la propia DIAN. Su respuesta —el
     * ApplicationResponse que devuelve firmado— usa `xmlenc#sha256` en
     * los tres `DigestMethod`, en el `CertDigest` y en el
     * `SigPolicyHash`. Y su `SigPolicyHash` vale
     * `dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=`, que es
     * exactamente el SHA-256 del PDF de la política; se descargó y se
     * comprobó:
     *
     *   curl -s <url de la politica> | openssl dgst -sha256 -binary | openssl base64 -A
     *
     * El SHA-384 que había también era correcto —el mismo PDF, otro
     * algoritmo—, y por eso no lo delataba ninguna comprobación
     * interna. Sólo se ve comparando contra quien sí funciona.
     *
     * NO CONFUNDIR CON EL CUFE. El CUFE y el CUDE siguen siendo
     * SHA-384: son otra cosa, y el propio XML lo declara en
     * `schemeName="CUFE-SHA384"`.
     */
    private const METODO_FIRMA = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const METODO_RESUMEN = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const ALGORITMO_RESUMEN = 'sha256';
    private const ALGORITMO_FIRMA = OPENSSL_ALGO_SHA256;

    /**
     * La política de firma de la DIAN.
     *
     * VERIFICADO CONTRA LA POLÍTICA PUBLICADA (2026-09-08)
     * ----------------------------------------------------
     * Se descargó el PDF vivo y se calcularon SUS DOS resúmenes:
     *
     *   openssl dgst -sha256 -binary politicadefirmav2.pdf | openssl base64 -A
     *     => dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=
     *   openssl dgst -sha384 -binary politicadefirmav2.pdf | openssl base64 -A
     *     => EQC0kiWPaAME6IsEZ7WuaTWJ97Zmf6hIO69rMCVURmQxBB9ebgLrjhL5BArQ0a0l
     *
     * Los dos son correctos. Aquí va el de SHA-256 porque es el que
     * declara la propia DIAN en las firmas que ella emite —el mismo
     * valor, carácter por carácter— y porque es el algoritmo que usa
     * para todo lo demás de la firma. Véase el comentario de los
     * algoritmos: con el de SHA-384 rechazaba con ZE02.
     *
     * OJO CON LA URL: la `v1/` da 404 y la `v2/` es la viva. Poner una
     * URL muerta en la política es una firma que nadie puede validar.
     */
    private const POLITICA_URL = 'https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf';
    private const POLITICA_RESUMEN = 'dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=';

    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * Firma un XML y devuelve el XML firmado.
     *
     * @param  string  $xml    El documento sin firmar
     * @param  string  $p12    Contenido binario del certificado .p12
     * @param  string  $clave  Contraseña del certificado
     */
    public function firmar(string $xml, string $p12, string $clave): string
    {
        $certificado = $this->leerCertificado($p12, $clave);

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        if (!$doc->loadXML($xml)) {
            throw new RuntimeException('El XML a firmar no se pudo leer.');
        }

        // EL ORDEN DE ESTAS TRES LÍNEAS ES LA FIRMA ENTERA.
        //
        // Primero se crea el envoltorio VACÍO donde irá la firma;
        // después se resume el documento; y sólo entonces se mete la
        // firma dentro del envoltorio que ya se resumió.
        //
        // La transformada «enveloped-signature» excluye del cálculo
        // `ds:Signature` — y NADA MÁS. Si el envoltorio
        // `ext:UBLExtension`/`ext:ExtensionContent` se creara junto con
        // la firma, quien verifique quitaría la firma y se encontraría
        // con un envoltorio vacío que no estaba cuando resumimos: el
        // resumen no cuadra y la DIAN contesta ZE02, «Valor de la firma
        // inválido».
        //
        // No es teoría: se comprobó sobre el XML que la DIAN rechazó.
        // Quitando sólo `ds:Signature` el resumen no cuadraba;
        // quitando el `ext:UBLExtension` entero, cuadraba exacto. Eso
        // es la prueba de que se resumió antes de añadir el envoltorio.
        $contenido = $this->prepararExtension($doc);

        $resumenDocumento = $this->resumir($doc->documentElement->C14N());

        $id = 'xmldsig-' . $this->uuid();

        $firma = $this->armarFirma($doc, $id, $certificado, $resumenDocumento);
        $contenido->appendChild($firma);

        // Ya insertada: ahora los resúmenes de KeyInfo y
        // SignedProperties salen con los espacios de nombres que
        // heredan de verdad.
        $this->rellenarResumen($doc, $id . '-keyinfo', 1);
        $this->rellenarResumen($doc, $id . '-signedprops', 2);

        $this->firmarSignedInfo($doc, $firma, $certificado['pkey']);

        return $doc->saveXML();
    }

    // ==================== El certificado ====================

    /**
     * Abre el .p12 y saca la clave, el certificado y su cadena.
     *
     * @return array{cert: string, pkey: string, chain: array<int, string>}
     */
    private function leerCertificado(string $p12, string $clave): array
    {
        $contenido = [];

        if (!openssl_pkcs12_read($p12, $contenido, $clave)) {
            throw new RuntimeException(
                'No se pudo abrir el certificado: la contraseña es incorrecta o el archivo no es un .p12 válido.'
            );
        }

        return [
            'cert' => $contenido['cert'],
            'pkey' => $contenido['pkey'],
            // La cadena completa: la DIAN quiere un xades:Cert por cada
            // certificado, no solo por el del firmante.
            'chain' => array_values($contenido['extracerts'] ?? []),
        ];
    }

    /** El certificado en base64, sin las cabeceras PEM. */
    private function base64Del(string $pem): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';
    }

    // ==================== La estructura ====================

    private function armarFirma(
        \DOMDocument $doc,
        string $id,
        array $certificado,
        string $resumenDocumento,
    ): \DOMElement {
        $firma = $doc->createElementNS(self::NS_DS, 'ds:Signature');
        $firma->setAttribute('Id', $id);

        // `xmlns:ds` EXPLÍCITO AQUÍ, aunque la raíz ya lo declare.
        //
        // Sin esto DOM lo omite por redundante —el `<Invoice>` ya trae
        // `xmlns:ds`— y la firma deja de poder leerse por su cuenta:
        // extraer el `ds:Signature` y parsearlo suelto da «unbound
        // prefix». Se comprobó: pasa con la nuestra y no con la de
        // `lopezsoft/ubl21dian`, que lo declara a mano por esto mismo.
        //
        // Era la ÚNICA diferencia que quedaba entre las dos firmas
        // después de igualar todo lo demás, y la DIAN seguía
        // contestando ZE02 con la firma matemáticamente correcta.
        $firma->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::NS_DS);

        // ---- SignedInfo: lo que se firma ----
        $info = $this->nodo($doc, $firma, 'ds:SignedInfo');

        $this->conAlgoritmo($doc, $info, 'ds:CanonicalizationMethod', self::C14N);
        $this->conAlgoritmo($doc, $info, 'ds:SignatureMethod', self::METODO_FIRMA);

        // 1. El documento entero, sin la firma.
        $ref0 = $this->nodo($doc, $info, 'ds:Reference');
        $ref0->setAttribute('Id', $id . '-ref0');
        $ref0->setAttribute('URI', '');
        $transformadas = $this->nodo($doc, $ref0, 'ds:Transforms');
        $this->conAlgoritmo($doc, $transformadas, 'ds:Transform', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $this->conAlgoritmo($doc, $ref0, 'ds:DigestMethod', self::METODO_RESUMEN);
        $this->nodo($doc, $ref0, 'ds:DigestValue', $resumenDocumento);

        // 2. El KeyInfo (la clave pública).
        $ref1 = $this->nodo($doc, $info, 'ds:Reference');
        $ref1->setAttribute('URI', '#' . $id . '-keyinfo');
        $this->conAlgoritmo($doc, $ref1, 'ds:DigestMethod', self::METODO_RESUMEN);
        $this->nodo($doc, $ref1, 'ds:DigestValue', '');

        // 3. Las SignedProperties (hora, certificado, política).
        $ref2 = $this->nodo($doc, $info, 'ds:Reference');
        $ref2->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $ref2->setAttribute('URI', '#' . $id . '-signedprops');
        $this->conAlgoritmo($doc, $ref2, 'ds:DigestMethod', self::METODO_RESUMEN);
        $this->nodo($doc, $ref2, 'ds:DigestValue', '');

        // ---- El valor de la firma, que se rellena al final ----
        $valor = $this->nodo($doc, $firma, 'ds:SignatureValue', '');
        $valor->setAttribute('Id', $id . '-sigvalue');

        // ---- KeyInfo ----
        $keyInfo = $this->nodo($doc, $firma, 'ds:KeyInfo');
        $keyInfo->setAttribute('Id', $id . '-keyinfo');
        $datos = $this->nodo($doc, $keyInfo, 'ds:X509Data');
        $this->nodo($doc, $datos, 'ds:X509Certificate', $this->base64Del($certificado['cert']));

        // ---- SignedProperties ----
        $this->armarPropiedades($doc, $firma, $id, $certificado);

        return $firma;
    }

    private function armarPropiedades(
        \DOMDocument $doc,
        \DOMElement $firma,
        string $id,
        array $certificado,
    ): void {
        $objeto = $this->nodo($doc, $firma, 'ds:Object');

        $calificadas = $doc->createElementNS(self::NS_XADES, 'xades:QualifyingProperties');
        $calificadas->setAttribute('Target', '#' . $id);
        $objeto->appendChild($calificadas);

        $firmadas = $this->nodo($doc, $calificadas, 'xades:SignedProperties');
        $firmadas->setAttribute('Id', $id . '-signedprops');

        $propiedades = $this->nodo($doc, $firmadas, 'xades:SignedSignatureProperties');

        // La hora que el firmante declara. El anexo pide que el reloj
        // esté sincronizado con la hora legal colombiana.
        $this->nodo($doc, $propiedades, 'xades:SigningTime', Carbon::now('America/Bogota')->format('Y-m-d\TH:i:s.vP'));

        // El certificado del firmante Y toda su cadena: un xades:Cert
        // por cada uno.
        $certificados = $this->nodo($doc, $propiedades, 'xades:SigningCertificate');

        foreach (array_merge([$certificado['cert']], $certificado['chain']) as $pem) {
            $this->certificadoDeclarado($doc, $certificados, $pem);
        }

        // La política de firma de la DIAN.
        $politica = $this->nodo($doc, $propiedades, 'xades:SignaturePolicyIdentifier');
        $politicaId = $this->nodo($doc, $politica, 'xades:SignaturePolicyId');
        $sigPolicyId = $this->nodo($doc, $politicaId, 'xades:SigPolicyId');
        $this->nodo($doc, $sigPolicyId, 'xades:Identifier', self::POLITICA_URL);
        $hash = $this->nodo($doc, $politicaId, 'xades:SigPolicyHash');
        $this->conAlgoritmo($doc, $hash, 'ds:DigestMethod', self::METODO_RESUMEN);
        $this->nodo($doc, $hash, 'ds:DigestValue', self::POLITICA_RESUMEN);

        // «supplier»: firma el propio obligado a facturar. Sería «third
        // party» si firmara un proveedor tecnológico en su nombre.
        $rol = $this->nodo($doc, $propiedades, 'xades:SignerRole');
        $roles = $this->nodo($doc, $rol, 'xades:ClaimedRoles');
        $this->nodo($doc, $roles, 'xades:ClaimedRole', 'supplier');
    }

    /** Un xades:Cert: el resumen del certificado y quién lo emitió. */
    private function certificadoDeclarado(\DOMDocument $doc, \DOMElement $padre, string $pem): void
    {
        $datos = openssl_x509_parse($pem);

        if ($datos === false) {
            throw new RuntimeException('Un certificado de la cadena no se pudo leer.');
        }

        $cert = $this->nodo($doc, $padre, 'xades:Cert');

        $resumen = $this->nodo($doc, $cert, 'xades:CertDigest');
        $this->conAlgoritmo($doc, $resumen, 'ds:DigestMethod', self::METODO_RESUMEN);
        $this->nodo($doc, $resumen, 'ds:DigestValue', $this->resumir(base64_decode($this->base64Del($pem))));

        $emisor = $this->nodo($doc, $cert, 'xades:IssuerSerial');
        $this->nodo($doc, $emisor, 'ds:X509IssuerName', $this->nombreDelEmisor($datos['issuer'] ?? []));
        $this->nodo($doc, $emisor, 'ds:X509SerialNumber', (string) ($datos['serialNumber'] ?? '0'));
    }

    /**
     * El nombre del emisor tal y como lo escribe la DIAN.
     *
     * En orden inverso al que devuelve openssl y separado por comas, que
     * es la forma que traen sus ejemplos: C=CO,L=Bogota D.C.,O=...
     */
    private function nombreDelEmisor(array $emisor): string
    {
        $partes = [];

        foreach (array_reverse($emisor, true) as $clave => $valor) {
            // Un mismo componente puede venir repetido (varias OU).
            foreach ((array) $valor as $uno) {
                $partes[] = $clave . '=' . $uno;
            }
        }

        return implode(',', $partes);
    }

    // ==================== Dónde va y cómo se cierra ====================

    /**
     * Mete la firma en su propia UBLExtension.
     *
     * En la primera va el bloque de la DIAN; la firma va en una
     * SEGUNDA, que es donde el anexo la ubica (§10.8).
     */
    /**
     * Crea el `ext:UBLExtension`/`ext:ExtensionContent` donde irá la
     * firma, y devuelve el `ExtensionContent` vacío.
     *
     * Se llama ANTES de resumir el documento, y ahí está todo el
     * asunto: lo que se resume tiene que ser exactamente lo que quedará
     * cuando alguien quite la firma para verificarla. Véase el
     * comentario de `firmar()`.
     */
    private function prepararExtension(\DOMDocument $doc): \DOMElement
    {
        $extensiones = $doc->getElementsByTagNameNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'UBLExtensions',
        )->item(0);

        if (!$extensiones) {
            throw new RuntimeException('El documento no tiene ext:UBLExtensions: no es un UBL de la DIAN.');
        }

        $extension = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'ext:UBLExtension',
        );
        $contenido = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'ext:ExtensionContent',
        );

        $extension->appendChild($contenido);
        $extensiones->appendChild($extension);

        return $contenido;
    }

    /**
     * Calcula el resumen de un nodo por su Id y lo escribe en su
     * referencia.
     *
     * El nodo tiene que estar YA en el documento: la canonicalización
     * arrastra los espacios de nombres heredados, y calcularlo suelto da
     * otro resultado.
     */
    private function rellenarResumen(\DOMDocument $doc, string $id, int $indiceReferencia): void
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);

        $nodo = $xpath->query('//*[@Id="' . $id . '"]')->item(0);

        if (!$nodo) {
            throw new RuntimeException("No se encontró el nodo {$id} para calcular su resumen.");
        }

        $referencias = $xpath->query('//ds:SignedInfo/ds:Reference');
        $referencia = $referencias->item($indiceReferencia);

        $destino = $xpath->query('ds:DigestValue', $referencia)->item(0);
        $destino->nodeValue = $this->resumir($nodo->C14N());
    }

    /** Canoniza SignedInfo, lo firma y escribe el SignatureValue. */
    private function firmarSignedInfo(\DOMDocument $doc, \DOMElement $firma, string $clave): void
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);

        $info = $xpath->query('ds:SignedInfo', $firma)->item(0);

        $valorFirma = '';

        if (!openssl_sign($info->C14N(), $valorFirma, $clave, self::ALGORITMO_FIRMA)) {
            throw new RuntimeException('No se pudo firmar el documento con la clave del certificado.');
        }

        $xpath->query('ds:SignatureValue', $firma)->item(0)->nodeValue = base64_encode($valorFirma);
    }

    // ==================== Apoyo ====================

    private function resumir(string $contenido): string
    {
        return base64_encode(hash(self::ALGORITMO_RESUMEN, $contenido, true));
    }

    private function nodo(\DOMDocument $doc, \DOMElement $padre, string $nombre, ?string $valor = null): \DOMElement
    {
        $espacio = str_starts_with($nombre, 'xades:') ? self::NS_XADES : self::NS_DS;

        $nodo = $doc->createElementNS($espacio, $nombre);

        if ($valor !== null && $valor !== '') {
            $nodo->appendChild($doc->createTextNode($valor));
        }

        $padre->appendChild($nodo);

        return $nodo;
    }

    private function conAlgoritmo(\DOMDocument $doc, \DOMElement $padre, string $nombre, string $algoritmo): \DOMElement
    {
        $nodo = $this->nodo($doc, $padre, $nombre);
        $nodo->setAttribute('Algorithm', $algoritmo);

        return $nodo;
    }

    private function uuid(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }
}
