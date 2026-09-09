<?php

namespace App\Billing\Dian;

use App\Billing\Enums\NoteType;
use App\Models\CreditDebitNote;
use App\Models\DianConfiguration;
use RuntimeException;

/**
 * Construye el XML UBL 2.1 de una nota crédito o débito.
 *
 * EN QUÉ SE PARECE Y EN QUÉ NO A UNA FACTURA
 * ------------------------------------------
 * Las partes son las mismas y se arman igual (de ahí `UblBuilder`). Lo
 * demás cambia más de lo que parece:
 *
 * | | Factura | Nota |
 * |---|---|---|
 * | Raíz | `Invoice` | `CreditNote` / `DebitNote` |
 * | Identificador | CUFE (con la clave técnica) | **CUDE** (con el PIN) |
 * | Totales | `LegalMonetaryTotal` | ídem en crédito, **`RequestedMonetaryTotal`** en débito |
 * | Líneas | `InvoiceLine` / `InvoicedQuantity` | `CreditNoteLine` / `CreditedQuantity` (o Debit…) |
 * | `CustomizationID` | 10 | 11 |
 *
 * UNA NOTA NO LLEVA NUMERACIÓN AUTORIZADA
 * ---------------------------------------
 * Y esto contradice lo que el plan daba por hecho. El XML de una nota
 * **no tiene `sts:InvoiceControl`** —el bloque de la resolución y el
 * rango—: se comprobó en los ejemplos oficiales `CreditNote.xml` y
 * `DebitNote.xml`. Y el propio anexo lo dice (§12.1): para estos
 * documentos vale «la numeración establecida por el facturador».
 *
 * Así que la numeración propia que ya hacía `NoteIssuer` era la
 * correcta desde el principio. Lo que faltaba era esto.
 *
 * LA NOTA APUNTA A SU FACTURA, DOS VECES
 * --------------------------------------
 * · `cac:DiscrepancyResponse` — el motivo: qué documento se corrige y
 *   con qué código de concepto de la DIAN.
 * · `cac:BillingReference` — la referencia formal, **con el CUFE de la
 *   factura corregida**. Es lo que permite a la DIAN atar la nota al
 *   documento que ajusta; sin él la nota queda huérfana.
 */
class NoteXmlBuilder extends UblBuilder
{
    private const NS_CREDITO = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    private const NS_DEBITO = 'urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2';

    public function __construct(
        private readonly CudeCalculator $cude = new CudeCalculator(),
        private readonly CufeCalculator $formato = new CufeCalculator(),
        private readonly SoftwareSecurityCode $codigoSoftware = new SoftwareSecurityCode(),
        private readonly QrContent $qr = new QrContent(),
    ) {
    }

    /**
     * Arma el XML de la nota.
     *
     * @param  string  $cufeDeLaFactura  El de la factura que corrige
     * @return array{xml: string, cude: string, qr: string}
     */
    public function construir(
        CreditDebitNote $nota,
        DianConfiguration $configuracion,
        string $cufeDeLaFactura,
    ): array {
        $factura = $nota->invoice;
        $empresa = $configuracion->company;
        $cliente = $factura?->contract?->client;

        if (!$factura) {
            throw new RuntimeException('La nota no tiene factura: no se puede decir qué documento corrige.');
        }

        $this->exigirDatos($empresa, $cliente);

        $esCredito = $nota->tipo() === NoteType::Credito;
        $momento = $this->momentoDe($nota);
        $ambiente = (string) $configuracion->environment_code;
        $produccion = $ambiente === DianConfiguration::PRODUCCION;

        // El CUDE lleva el PIN del software donde el CUFE lleva la clave
        // tecnica. Es la unica diferencia entre los dos, y confundirlas
        // da un hash valido que la DIAN rechaza.
        $cude = $this->cude->calcular(
            numeroNota: $nota->full_number,
            fecha: $momento->format('Y-m-d'),
            hora: $this->formato->horaDe($momento),
            valorSinImpuestos: (float) $nota->subtotal,
            iva: (float) $nota->tax,
            inc: 0.0,
            ica: 0.0,
            valorTotal: (float) $nota->total,
            nitEmisor: (string) $empresa->document_number,
            documentoAdquiriente: (string) $cliente->identity_number,
            pinDelSoftware: (string) $configuracion->software_pin,
            ambiente: $ambiente,
        );

        $qr = $this->qr->construir(
            numeroFactura: $nota->full_number,
            fecha: $momento->format('Y-m-d'),
            hora: $this->formato->horaDe($momento),
            nitFacturador: (string) $empresa->document_number,
            documentoAdquiriente: (string) $cliente->identity_number,
            valorSinImpuestos: (float) $nota->subtotal,
            iva: (float) $nota->tax,
            otrosImpuestos: 0.0,
            valorTotal: (float) $nota->total,
            cufe: $cude,
            produccion: $produccion,
        );

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $raiz = $doc->createElementNS(
            $esCredito ? self::NS_CREDITO : self::NS_DEBITO,
            $esCredito ? 'CreditNote' : 'DebitNote',
        );
        $doc->appendChild($raiz);

        foreach ([
            'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'sts' => 'dian:gov:co:facturaelectronica:Structures-2-1',
            'ds' => 'http://www.w3.org/2000/09/xmldsig#',
        ] as $prefijo => $uri) {
            $raiz->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefijo, $uri);
        }

        $this->extensiones($doc, $raiz, $nota, $empresa, $configuracion, $cude, $produccion);
        $this->cabecera($doc, $raiz, $nota, $momento, $cude, $ambiente, $esCredito);
        $this->referencias($doc, $raiz, $nota, $factura, $cufeDeLaFactura);
        $this->emisor($doc, $raiz, $empresa, prefijoAutorizado: null);
        $this->adquiriente($doc, $raiz, $cliente);
        $this->formaDePago($doc, $raiz, $factura);
        $this->impuestos($doc, $raiz, $nota);
        $this->totales($doc, $raiz, $nota, $esCredito);
        $this->linea($doc, $raiz, $nota, $esCredito);

        return ['xml' => $doc->saveXML(), 'cude' => $cude, 'qr' => $qr];
    }

    /** @return array<int, string> vacío si es válido */
    public function erroresDeEsquema(string $xml, bool $esCredito): array
    {
        return $this->validarContra(
            $xml,
            $esCredito ? 'UBL-CreditNote-2.1.xsd' : 'UBL-DebitNote-2.1.xsd',
        );
    }

    // ==================== Los bloques ====================

    /**
     * El bloque de la DIAN.
     *
     * SIN `InvoiceControl`, y es deliberado: una nota no sale de un
     * rango autorizado. Ponérselo sería declarar una autorización de
     * numeración que no le corresponde.
     */
    private function extensiones(
        \DOMDocument $doc,
        \DOMElement $raiz,
        CreditDebitNote $nota,
        $empresa,
        DianConfiguration $configuracion,
        string $cude,
        bool $produccion,
    ): void {
        $extensiones = $this->hijo($doc, $raiz, 'ext:UBLExtensions');
        $extension = $this->hijo($doc, $extensiones, 'ext:UBLExtension');
        $contenido = $this->hijo($doc, $extension, 'ext:ExtensionContent');
        $dian = $this->hijo($doc, $contenido, 'sts:DianExtensions');

        $origen = $this->hijo($doc, $dian, 'sts:InvoiceSource');
        $codigoPais = $this->hijo($doc, $origen, 'cbc:IdentificationCode', self::PAIS);
        $codigoPais->setAttribute('listAgencyID', '6');
        $codigoPais->setAttribute('listAgencyName', 'United Nations Economic Commission for Europe');
        $codigoPais->setAttribute(
            'listSchemeURI',
            'urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1',
        );

        $proveedor = $this->hijo($doc, $dian, 'sts:SoftwareProvider');

        $proveedorId = $this->hijo($doc, $proveedor, 'sts:ProviderID', (string) $empresa->document_number);
        $proveedorId->setAttribute('schemeID', (string) ($empresa->verification_digit ?? '0'));
        // NIT: mismo motivo que en la factura (CAB23).
        $proveedorId->setAttribute('schemeName', self::TIPO_NIT);
        $proveedorId->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $proveedorId->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        $softwareId = $this->hijo($doc, $proveedor, 'sts:SoftwareID', (string) $configuracion->software_id);
        $softwareId->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $softwareId->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        $codigo = $this->hijo($doc, $dian, 'sts:SoftwareSecurityCode', $this->codigoSoftware->calcular(
            (string) $configuracion->software_id,
            (string) $configuracion->software_pin,
            (string) $nota->full_number,
        ));
        $codigo->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $codigo->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        $autorizador = $this->hijo($doc, $dian, 'sts:AuthorizationProvider');
        $autorizadorId = $this->hijo($doc, $autorizador, 'sts:AuthorizationProviderID', self::NIT_DIAN);
        $autorizadorId->setAttribute('schemeID', '4');
        $autorizadorId->setAttribute('schemeName', '31');
        $autorizadorId->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $autorizadorId->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        $this->hijo($doc, $dian, 'sts:QRCode', $this->qr->url($cude, $produccion));
    }

    private function cabecera(
        \DOMDocument $doc,
        \DOMElement $raiz,
        CreditDebitNote $nota,
        \Illuminate\Support\Carbon $momento,
        string $cude,
        string $ambiente,
        bool $esCredito,
    ): void {
        $this->hijo($doc, $raiz, 'cbc:UBLVersionID', 'UBL 2.1');

        // 20 para la nota CRÉDITO y 30 para la DÉBITO — las dos «que
        // referencian una factura electrónica», que es el único caso
        // que emite este sistema (siempre se emiten contra una factura,
        // y por eso llevan `cac:BillingReference`).
        //
        // Aquí decía 11, y la DIAN rechazó la nota dos veces por lo
        // mismo: CAD02 «CustomizationID no indica un valor válido para
        // el tipo de operación» y CAD02a «CustomizationID debe ser
        // igual a 20». El 11 no salía de ninguna parte.
        $this->hijo($doc, $raiz, 'cbc:CustomizationID', $esCredito ? '20' : '30');

        // El ProfileID lleva el nombre COMPLETO del tipo de documento.
        // Con «DIAN 2.1» a secas la DIAN avisa (CAD03) de que no
        // contiene el literal que espera.
        $this->hijo($doc, $raiz, 'cbc:ProfileID', $esCredito
            ? 'DIAN 2.1: Nota Crédito de Factura Electrónica de Venta'
            : 'DIAN 2.1: Nota Débito de Factura Electrónica de Venta');
        $this->hijo($doc, $raiz, 'cbc:ProfileExecutionID', $ambiente);
        $this->hijo($doc, $raiz, 'cbc:ID', (string) $nota->full_number);

        $uuid = $this->hijo($doc, $raiz, 'cbc:UUID', $cude);
        $uuid->setAttribute('schemeID', $ambiente);
        $uuid->setAttribute('schemeName', 'CUDE-SHA384');

        $this->hijo($doc, $raiz, 'cbc:IssueDate', $momento->format('Y-m-d'));
        $this->hijo($doc, $raiz, 'cbc:IssueTime', $this->formato->horaDe($momento));

        // Solo la nota CREDITO lleva codigo de tipo: en UBL la nota
        // debito no tiene ese elemento.
        if ($esCredito) {
            $tipo = $this->hijo($doc, $raiz, 'cbc:CreditNoteTypeCode', '91');

            // ESTO ES UNA HIPÓTESIS, y se marca como tal.
            //
            // La DIAN rechazó la nota con «CBA06: No informado el
            // literal "195"» sin decir en qué elemento. El literal 195
            // es su código de agencia, y este es el único elemento de
            // la nota que existe SOLO en ella —lo que explicaría que la
            // factura nunca recibiera esa queja— y que en los ejemplos
            // publicados por la DIAN lo lleva. El nuestro iba pelado.
            //
            // Se añade porque no puede hacer daño: son atributos que el
            // esquema admite y que sus propios ejemplos traen. Si CBA06
            // vuelve a salir, NO era esto y hay que seguir buscando.
            $tipo->setAttribute('listAgencyID', self::AGENCIA_ID);
            $tipo->setAttribute('listAgencyName', self::AGENCIA_NOMBRE);
            $tipo->setAttribute('listURI', 'http://reference.dian.gov.co/resolucion000042/CreditNoteType.gc');
        }

        $this->hijo($doc, $raiz, 'cbc:Note', (string) $nota->reason);

        $moneda = $this->hijo($doc, $raiz, 'cbc:DocumentCurrencyCode', self::MONEDA);
        $moneda->setAttribute('listID', 'ISO 4217 Alpha');
        $moneda->setAttribute('listAgencyID', '6');
        $moneda->setAttribute('listAgencyName', 'United Nations Economic Commission for Europe');

        $this->hijo($doc, $raiz, 'cbc:LineCountNumeric', '1');
    }

    /**
     * A qué factura corrige, y por qué.
     *
     * El `ResponseCode` es el código de concepto oficial de la DIAN —el
     * mismo que ya guardaba la nota—, y el `BillingReference` lleva el
     * **CUFE** de la factura corregida: es lo que ata la nota a su
     * documento. Sin él la nota queda huérfana.
     */
    private function referencias(
        \DOMDocument $doc,
        \DOMElement $raiz,
        CreditDebitNote $nota,
        $factura,
        string $cufeDeLaFactura,
    ): void {
        $discrepancia = $this->hijo($doc, $raiz, 'cac:DiscrepancyResponse');
        $this->hijo($doc, $discrepancia, 'cbc:ReferenceID', (string) $factura->full_number);
        $this->hijo($doc, $discrepancia, 'cbc:ResponseCode', (string) $nota->concept_code);
        $this->hijo($doc, $discrepancia, 'cbc:Description', (string) $nota->concept_label);

        $referencia = $this->hijo($doc, $raiz, 'cac:BillingReference');
        $documento = $this->hijo($doc, $referencia, 'cac:InvoiceDocumentReference');
        $this->hijo($doc, $documento, 'cbc:ID', (string) $factura->full_number);

        $uuid = $this->hijo($doc, $documento, 'cbc:UUID', $cufeDeLaFactura);
        $uuid->setAttribute('schemeName', 'CUFE-SHA384');

        $this->hijo($doc, $documento, 'cbc:IssueDate', \Illuminate\Support\Carbon::parse($factura->issue_date)->format('Y-m-d'));
    }

    /**
     * La forma de pago, heredada de la factura que la nota corrige.
     *
     * POR QUÉ LA LLEVA UNA NOTA
     * -------------------------
     * Porque la DIAN la exige, y no lo dice más claro que con
     * «CAN01, Rechazo: Rechazo si grupo no informado» —sin nombrar el
     * grupo—. Se supo comparando: la factura emite `cac:PaymentMeans`
     * y no recibió esa queja; la nota no lo emitía y sí la recibió.
     *
     * Los valores salen de la factura referenciada, que es de donde
     * tienen sentido: una nota no acuerda condiciones de pago propias,
     * ajusta las de un documento que ya las tenía.
     */
    private function formaDePago(\DOMDocument $doc, \DOMElement $raiz, $factura): void
    {
        $nodo = $this->hijo($doc, $raiz, 'cac:PaymentMeans');

        $aCredito = $factura->due_date
            && $factura->issue_date
            && \Illuminate\Support\Carbon::parse($factura->due_date)->gt(\Illuminate\Support\Carbon::parse($factura->issue_date));

        $this->hijo($doc, $nodo, 'cbc:ID', $aCredito ? '2' : '1');
        $this->hijo($doc, $nodo, 'cbc:PaymentMeansCode', (string) ($factura->payment_means_code ?: '10'));

        if ($factura->due_date) {
            $this->hijo($doc, $nodo, 'cbc:PaymentDueDate', \Illuminate\Support\Carbon::parse($factura->due_date)->format('Y-m-d'));
        }
    }

    private function impuestos(\DOMDocument $doc, \DOMElement $raiz, CreditDebitNote $nota): void
    {
        if ((float) $nota->tax <= 0) {
            return;
        }

        $nodo = $this->hijo($doc, $raiz, 'cac:TaxTotal');
        $this->importe($doc, $nodo, 'cbc:TaxAmount', (float) $nota->tax);

        $subtotal = $this->hijo($doc, $nodo, 'cac:TaxSubtotal');
        $this->importe($doc, $subtotal, 'cbc:TaxableAmount', (float) $nota->subtotal);
        $this->importe($doc, $subtotal, 'cbc:TaxAmount', (float) $nota->tax);

        $categoria = $this->hijo($doc, $subtotal, 'cac:TaxCategory');

        // El porcentaje se deduce del propio importe: una nota no
        // guarda la tarifa, guarda el impuesto en pesos.
        $porcentaje = (float) $nota->subtotal > 0
            ? round((float) $nota->tax * 100 / (float) $nota->subtotal, 2)
            : 0.0;

        $this->hijo($doc, $categoria, 'cbc:Percent', number_format($porcentaje, 2, '.', ''));
        $this->esquemaIva($doc, $categoria);
    }

    /**
     * Los totales.
     *
     * La nota CRÉDITO usa `LegalMonetaryTotal` y la DÉBITO
     * `RequestedMonetaryTotal`. No es un capricho de UBL: se comprobó en
     * los ejemplos oficiales, y usar el que no toca invalida el
     * documento contra su propio esquema.
     */
    private function totales(\DOMDocument $doc, \DOMElement $raiz, CreditDebitNote $nota, bool $esCredito): void
    {
        $nodo = $this->hijo($doc, $raiz, $esCredito ? 'cac:LegalMonetaryTotal' : 'cac:RequestedMonetaryTotal');

        $this->importe($doc, $nodo, 'cbc:LineExtensionAmount', (float) $nota->subtotal);

        // La base imponible, no el subtotal. Si la nota no lleva
        // impuesto tampoco declara base, y decir aquí el subtotal la
        // descuadraría contra sus propias líneas: es el rechazo FAU04,
        // el mismo que nos devolvió la DIAN en una factura.
        $this->importe($doc, $nodo, 'cbc:TaxExclusiveAmount', (float) $nota->tax > 0 ? (float) $nota->subtotal : 0.0);

        $this->importe($doc, $nodo, 'cbc:TaxInclusiveAmount', (float) $nota->total);
        $this->importe($doc, $nodo, 'cbc:PayableAmount', (float) $nota->total);
    }

    /**
     * La única línea de la nota.
     *
     * Una nota en este sistema no tiene renglones: tiene un concepto, un
     * motivo y un importe. Se emite como una sola línea que dice el
     * concepto — que es exactamente lo que la nota ajusta.
     */
    private function linea(\DOMDocument $doc, \DOMElement $raiz, CreditDebitNote $nota, bool $esCredito): void
    {
        $nodo = $this->hijo($doc, $raiz, $esCredito ? 'cac:CreditNoteLine' : 'cac:DebitNoteLine');

        $this->hijo($doc, $nodo, 'cbc:ID', '1');

        $cantidad = $this->hijo(
            $doc,
            $nodo,
            $esCredito ? 'cbc:CreditedQuantity' : 'cbc:DebitedQuantity',
            '1.000000',
        );
        $cantidad->setAttribute('unitCode', '94');

        $this->importe($doc, $nodo, 'cbc:LineExtensionAmount', (float) $nota->subtotal);

        if ((float) $nota->tax > 0) {
            $impuesto = $this->hijo($doc, $nodo, 'cac:TaxTotal');
            $this->importe($doc, $impuesto, 'cbc:TaxAmount', (float) $nota->tax);

            $subtotal = $this->hijo($doc, $impuesto, 'cac:TaxSubtotal');
            $this->importe($doc, $subtotal, 'cbc:TaxableAmount', (float) $nota->subtotal);
            $this->importe($doc, $subtotal, 'cbc:TaxAmount', (float) $nota->tax);

            $categoria = $this->hijo($doc, $subtotal, 'cac:TaxCategory');
            $porcentaje = (float) $nota->subtotal > 0
                ? round((float) $nota->tax * 100 / (float) $nota->subtotal, 2)
                : 0.0;
            $this->hijo($doc, $categoria, 'cbc:Percent', number_format($porcentaje, 2, '.', ''));
            $this->esquemaIva($doc, $categoria);
        }

        $articulo = $this->hijo($doc, $nodo, 'cac:Item');
        $this->hijo($doc, $articulo, 'cbc:Description', (string) ($nota->concept_label ?: $nota->reason));

        $identificacion = $this->hijo($doc, $articulo, 'cac:StandardItemIdentification');
        $id = $this->hijo($doc, $identificacion, 'cbc:ID', (string) $nota->concept_code);
        $id->setAttribute('schemeID', '999');

        $precio = $this->hijo($doc, $nodo, 'cac:Price');
        $this->importe($doc, $precio, 'cbc:PriceAmount', (float) $nota->subtotal);
        $base = $this->hijo($doc, $precio, 'cbc:BaseQuantity', '1.000000');
        $base->setAttribute('unitCode', '94');
    }
}
