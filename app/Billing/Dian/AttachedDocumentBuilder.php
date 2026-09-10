<?php

namespace App\Billing\Dian;

use App\Models\Company;
use App\Models\ElectronicDocument;
use RuntimeException;

/**
 * El `AttachedDocument`: el contenedor que se le entrega al adquiriente.
 *
 * QUÉ ES
 * ------
 * Un XML que lleva DENTRO otros dos, en sendos bloques CDATA: la factura
 * firmada y el acuse con el que la DIAN la validó. Es lo que mandan los
 * proveedores tecnológicos, y lo que los sistemas contables saben cargar
 * de un tirón.
 *
 * NO SE LE ENVÍA A LA DIAN. Se le entrega al cliente. Por eso su firma
 * no la valida nadie oficialmente: la comprueba el software del
 * adquiriente.
 *
 * DE DÓNDE SALE ESTA ESTRUCTURA
 * -----------------------------
 * De un AttachedDocument REAL, emitido por otra empresa colombiana y
 * recibido por correo. No de deducirla.
 *
 * Esto importa: **la DIAN no publica ningún ejemplo**. Se revisaron su
 * anexo técnico 1.9, su Caja de Herramientas, y tres librerías
 * colombianas (`lopezsoft/ubl21dian`, `dazza-dev/laravel-feco`,
 * `bit4bit/facho`) — ninguna lo construye; la de facho es un esqueleto
 * que solo pone el ID. Y en este proyecto deducir estructuras de la DIAN
 * ya costó cuatro despliegues.
 *
 * El ejemplar de referencia NO se guarda en el repositorio: es un
 * documento fiscal de un tercero. Lo que queda es esta estructura y las
 * pruebas que la fijan.
 *
 * LO QUE LA REFERENCIA DEJÓ CLARO
 * -------------------------------
 * · Los dos CDATA llevan el XML COMPLETO, con su declaración `<?xml`.
 * · `CustomizationID` es el literal «Documentos adjuntos», no un código.
 * · `DocumentType` del contenedor es «Contenedor de Factura Electrónica»;
 *   el de la referencia interna es «ApplicationResponse».
 * · `cac:ResultOfVerification` es lo que dice que la DIAN la validó, con
 *   su nombre completo como `ValidatorID`.
 * · La firma es la MISMA que la de la factura: misma canonicalización,
 *   tres referencias, sin `KeyValue` ni `SignedDataObjectProperties`.
 *   Aquel emisor usa SHA-512 y nosotros SHA-256; las dos valen, y la
 *   nuestra ya está aceptada por la DIAN.
 */
class AttachedDocumentBuilder extends UblBuilder
{
    /** El nombre completo con el que la DIAN se identifica como validador. */
    private const VALIDADOR = 'Unidad Especial Dirección de Impuestos y Aduanas Nacionales';

    /** «Documento validado por la DIAN». */
    private const VALIDADO = '02';

    public function __construct(
        private readonly XadesSigner $firmador,
    ) {
    }

    /** ¿Se puede armar el contenedor de este documento? */
    public function disponiblePara(ElectronicDocument $documento): bool
    {
        return $documento->status === ElectronicDocument::ACEPTADO
            && filled($documento->signed_xml)
            && filled($documento->dian_response_xml);
    }

    /**
     * El contenedor, firmado.
     *
     * @param  string  $p12    Contenido binario del certificado
     * @param  string  $clave  Contraseña del certificado
     */
    public function construir(ElectronicDocument $documento, string $p12, string $clave): string
    {
        if (!$this->disponiblePara($documento)) {
            throw new RuntimeException(
                'El documento ' . $documento->id . ' no tiene con qué armar el contenedor: '
                . 'hace falta que la DIAN lo haya aceptado y que estén el XML firmado y el acuse.',
            );
        }

        $factura = $documento->invoice;

        if (!$factura) {
            throw new RuntimeException('El documento ' . $documento->id . ' no apunta a ninguna factura.');
        }

        $empresa = Company::withoutGlobalScopes()->find($documento->company_id);
        $cliente = $factura->contract?->client;

        if (!$empresa || !$cliente) {
            throw new RuntimeException('Falta la empresa o el cliente para armar el contenedor.');
        }

        $doc = $this->documento($documento, $factura, $empresa, $cliente);

        // SE FIRMA Y LUEGO SE VALIDA, en ese orden.
        //
        // Sin firma el documento no valida: el esquema exige que
        // `ext:UBLExtension` lleve contenido, y el contenido es
        // justamente la firma. Validar antes obligaria a inventarse un
        // relleno, y ademas se estaria comprobando algo que no es lo que
        // se entrega.
        $firmado = $this->firmador->firmar($doc->saveXML(), $p12, $clave);

        $errores = $this->erroresDeEsquema($firmado);

        if ($errores !== []) {
            throw new RuntimeException(
                'El contenedor no valida contra su esquema: ' . implode(' | ', $errores),
            );
        }

        return $firmado;
    }

    /** @return array<int, string> vacío si es válido */
    public function erroresDeEsquema(string $xml): array
    {
        return $this->validarContra($xml, 'UBL-AttachedDocument-2.1.xsd');
    }

    // ==================== La estructura ====================

    private function documento(
        ElectronicDocument $documento,
        $factura,
        Company $empresa,
        $cliente,
    ): \DOMDocument {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $raiz = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:AttachedDocument-2',
            'AttachedDocument',
        );
        $doc->appendChild($raiz);

        // `xades` y `xades141` se declaran aquí y no dentro de la firma:
        // si DOM los declarara en el `ds:Signature`, cambiaría lo que
        // hereda `xades:SignedProperties` al canonicalizarlo y su
        // resumen dejaría de cuadrar. Es la misma razón —y el mismo
        // rechazo ZE02— que en la factura.
        foreach ([
            'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'sts' => 'dian:gov:co:facturaelectronica:Structures-2-1',
            'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xades141' => 'http://uri.etsi.org/01903/v1.4.1#',
            'xades' => 'http://uri.etsi.org/01903/v1.3.2#',
            'ds' => 'http://www.w3.org/2000/09/xmldsig#',
        ] as $prefijo => $uri) {
            $raiz->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefijo, $uri);
        }

        // Solo el contenedor: el `ext:UBLExtension` con la firma lo
        // crea `XadesSigner`, y tiene que crearlo el antes de resumir
        // —lo explica alli—. A diferencia de la factura, aqui NO hay un
        // segundo bloque con `sts:DianExtensions`: el documento de
        // referencia trae una sola extension, la de la firma.
        $this->hijo($doc, $raiz, 'ext:UBLExtensions');

        $this->cabecera($doc, $raiz, $documento, $factura);
        $this->emisorDelContenedor($doc, $raiz, $empresa);
        $this->receptorDelContenedor($doc, $raiz, $cliente);
        $this->adjunto($doc, $raiz, 'cac:Attachment', (string) $documento->signed_xml);
        $this->referenciaAlPadre($doc, $raiz, $documento, $factura);

        return $doc;
    }

    private function cabecera(\DOMDocument $doc, \DOMElement $raiz, ElectronicDocument $documento, $factura): void
    {
        $momento = $this->momentoDe($factura);

        $this->hijo($doc, $raiz, 'cbc:UBLVersionID', 'UBL 2.1');

        // Literales, no códigos. Así vienen en el documento de
        // referencia y así los espera quien lo lee.
        $this->hijo($doc, $raiz, 'cbc:CustomizationID', 'Documentos adjuntos');
        $this->hijo($doc, $raiz, 'cbc:ProfileID', 'Factura Electrónica de Venta');

        $this->hijo($doc, $raiz, 'cbc:ProfileExecutionID', (string) $documento->environment_code);

        // El consecutivo del CONTENEDOR, que es cosa nuestra y no de la
        // DIAN: el id del documento electrónico sirve y es único.
        $this->hijo($doc, $raiz, 'cbc:ID', (string) $documento->id);

        $this->hijo($doc, $raiz, 'cbc:IssueDate', $momento->format('Y-m-d'));
        $this->hijo($doc, $raiz, 'cbc:IssueTime', $this->formatoHora($momento));

        $this->hijo($doc, $raiz, 'cbc:DocumentType', 'Contenedor de Factura Electrónica');
        $this->hijo($doc, $raiz, 'cbc:ParentDocumentID', (string) $factura->full_number);
    }

    private function emisorDelContenedor(\DOMDocument $doc, \DOMElement $raiz, Company $empresa): void
    {
        $nodo = $this->hijo($doc, $raiz, 'cac:SenderParty');
        $tributario = $this->hijo($doc, $nodo, 'cac:PartyTaxScheme');

        $this->hijo($doc, $tributario, 'cbc:RegistrationName', (string) $empresa->legal_name);
        $this->identificacion($doc, $tributario, $empresa->document_number, $empresa->verification_digit, self::TIPO_NIT);
        $this->hijo($doc, $tributario, 'cbc:TaxLevelCode', $this->responsabilidades($empresa));

        $esquema = $this->hijo($doc, $tributario, 'cac:TaxScheme');
        $this->hijo($doc, $esquema, 'cbc:ID', '01');
        $this->hijo($doc, $esquema, 'cbc:Name', 'IVA');
    }

    private function receptorDelContenedor(\DOMDocument $doc, \DOMElement $raiz, $cliente): void
    {
        $nodo = $this->hijo($doc, $raiz, 'cac:ReceiverParty');
        $tributario = $this->hijo($doc, $nodo, 'cac:PartyTaxScheme');

        $this->hijo($doc, $tributario, 'cbc:RegistrationName', $cliente->fullName());
        $this->identificacion($doc, $tributario, $cliente->identity_number, $cliente->verification_digit, self::TIPO_NIT);
        $this->hijo($doc, $tributario, 'cbc:TaxLevelCode', $this->responsabilidades($cliente));

        // El adquiriente que no es responsable de IVA declara «no
        // aplica», que es lo que trae el documento de referencia.
        $esquema = $this->hijo($doc, $tributario, 'cac:TaxScheme');
        $this->hijo($doc, $esquema, 'cbc:ID', 'ZZ');
        $this->hijo($doc, $esquema, 'cbc:Name', 'No aplica');

        $persona = $this->hijo($doc, $nodo, 'cac:Person');
        $this->hijo($doc, $persona, 'cbc:FirstName', (string) $cliente->name);
        $this->hijo($doc, $persona, 'cbc:FamilyName', (string) $cliente->last_name);
    }

    /**
     * Un documento embebido, en CDATA.
     *
     * Va el XML COMPLETO, con su declaración `<?xml`: así viene en el
     * documento de referencia, y es lo que permite al adquiriente
     * sacarlo y usarlo tal cual.
     */
    private function adjunto(\DOMDocument $doc, \DOMElement $padre, string $etiqueta, string $contenido): void
    {
        $adjunto = $this->hijo($doc, $padre, $etiqueta);
        $referencia = $this->hijo($doc, $adjunto, 'cac:ExternalReference');

        $this->hijo($doc, $referencia, 'cbc:MimeCode', 'text/xml');
        $this->hijo($doc, $referencia, 'cbc:EncodingCode', 'UTF-8');

        $descripcion = $this->hijo($doc, $referencia, 'cbc:Description');
        $descripcion->appendChild($doc->createCDATASection($contenido));
    }

    /**
     * La referencia al documento padre: qué factura es y qué dijo la DIAN.
     *
     * Aquí va el segundo CDATA —el acuse— y el bloque que acredita la
     * validación.
     */
    private function referenciaAlPadre(\DOMDocument $doc, \DOMElement $raiz, ElectronicDocument $documento, $factura): void
    {
        $linea = $this->hijo($doc, $raiz, 'cac:ParentDocumentLineReference');
        $this->hijo($doc, $linea, 'cbc:LineID', '1');

        $referencia = $this->hijo($doc, $linea, 'cac:DocumentReference');
        $this->hijo($doc, $referencia, 'cbc:ID', (string) $factura->full_number);

        $uuid = $this->hijo($doc, $referencia, 'cbc:UUID', (string) $documento->cufe);
        $uuid->setAttribute('schemeID', (string) $documento->environment_code);
        $uuid->setAttribute('schemeName', 'CUFE-SHA384');

        $this->hijo($doc, $referencia, 'cbc:IssueDate', $this->momentoDe($factura)->format('Y-m-d'));
        $this->hijo($doc, $referencia, 'cbc:DocumentType', 'ApplicationResponse');

        $this->adjunto($doc, $referencia, 'cac:Attachment', (string) $documento->dian_response_xml);

        $validacion = $this->hijo($doc, $referencia, 'cac:ResultOfVerification');
        $this->hijo($doc, $validacion, 'cbc:ValidatorID', self::VALIDADOR);
        $this->hijo($doc, $validacion, 'cbc:ValidationResultCode', $this->codigoDeValidacion($documento));

        $aceptado = $documento->accepted_at
            ? \Illuminate\Support\Carbon::parse($documento->accepted_at)->setTimezone('America/Bogota')
            : \Illuminate\Support\Carbon::now('America/Bogota');

        $this->hijo($doc, $validacion, 'cbc:ValidationDate', $aceptado->format('Y-m-d'));
        $this->hijo($doc, $validacion, 'cbc:ValidationTime', $this->formatoHora($aceptado));
    }

    /**
     * El código con el que la DIAN calificó el documento.
     *
     * Se lee del propio acuse en vez de darlo por hecho: si algún día se
     * armara un contenedor de algo que la DIAN no validó, el contenedor
     * lo diría en vez de afirmar lo contrario.
     */
    private function codigoDeValidacion(ElectronicDocument $documento): string
    {
        $anterior = libxml_use_internal_errors(true);

        $acuse = new \DOMDocument();
        $leido = $acuse->loadXML((string) $documento->dian_response_xml);

        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (!$leido) {
            return self::VALIDADO;
        }

        $xpath = new \DOMXPath($acuse);
        $codigo = $xpath->query("//*[local-name()='ResponseCode']")->item(0)?->nodeValue;

        return trim((string) $codigo) ?: self::VALIDADO;
    }

    /** La hora con el huso de Colombia, como la escribe el resto del sistema. */
    private function formatoHora(\Illuminate\Support\Carbon $momento): string
    {
        return $momento->format('H:i:sP');
    }
}
