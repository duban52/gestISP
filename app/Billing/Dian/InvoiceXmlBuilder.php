<?php

namespace App\Billing\Dian;

use App\Billing\Services\ElectronicInvoicingDecider;
use App\Models\DianConfiguration;
use App\Models\Invoice;
use App\Models\NumberingRange;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Construye el XML UBL 2.1 de una factura electrónica de venta.
 *
 * QUÉ PRODUCE
 * -----------
 * El documento tal y como lo define el anexo técnico 1.9: un `Invoice`
 * de UBL 2.1 con el bloque `sts:DianExtensions` dentro de la primera
 * `UBLExtension`. **Sin firmar** — la firma va en una segunda extensión
 * y la pone otro servicio, después.
 *
 * SE VALIDA CONTRA EL XSD DE LA PROPIA DIAN
 * -----------------------------------------
 * Los esquemas están versionados en `resources/dian/xsd`. No es adorno:
 * en UBL el ORDEN de los elementos es parte del contrato, y un XML con
 * los mismos datos en distinto orden es un XML inválido. Equivocarse es
 * facilísimo y no se nota leyendo. Por eso la prueba valida contra el
 * XSD, y `erroresDeEsquema()` permite volver a validar antes de
 * transmitir.
 *
 * FALLA EN VOZ ALTA
 * -----------------
 * Si a la empresa o al cliente les faltan datos fiscales, esto NO emite
 * un XML incompleto: lanza una excepción diciendo exactamente qué
 * falta. Un XML sin el municipio del adquiriente lo rechaza la DIAN con
 * un código que no explica nada; el informe de completitud fiscal
 * existe justamente para no llegar hasta aquí a ciegas.
 *
 * LO QUE TODAVÍA NO CUBRE
 * -----------------------
 * **IVA exento y excluido.** Un ISP colombiano factura internet
 * residencial de estratos 1, 2 y 3 sin IVA, y eso en el XML no es
 * «porcentaje cero»: son estructuras propias, con su código de motivo
 * de exención. Aquí las líneas sin impuesto simplemente no suman al
 * `TaxTotal` — correcto para lo gravado, pero NO es la representación
 * que la DIAN espera para lo exento. Hace falta antes de facturar
 * electrónicamente a esos estratos.
 */
class InvoiceXmlBuilder extends UblBuilder
{
    /** Factura electrónica de venta. */
    private const TIPO_FACTURA = '01';

    public function __construct(
        private readonly CufeCalculator $cufe = new CufeCalculator(),
        private readonly SoftwareSecurityCode $codigoSoftware = new SoftwareSecurityCode(),
        private readonly QrContent $qr = new QrContent(),
    ) {
    }

    /**
     * Arma el XML de la factura.
     *
     * Devuelve el XML y los dos valores calculados que hay que guardar
     * en el documento electrónico: el CUFE y el contenido del QR.
     *
     * @return array{xml: string, cufe: string, qr: string}
     */
    public function construir(
        Invoice $factura,
        NumberingRange $rango,
        DianConfiguration $configuracion,
    ): array {
        // Un documento INTERNO no puede producir un XML de la DIAN.
        //
        // Sin esta comprobacion se puede armar un UBL perfectamente
        // valido para un documento que el sistema decidio que NO era
        // electronico: llevaria un numero de la serie interna metido en
        // un bloque de numeracion autorizada, que es justo la mezcla
        // que la separacion de series existe para impedir.
        //
        // La decision ya esta congelada en la factura desde que se
        // emitio: aqui solo se lee.
        if ($factura->document_kind !== ElectronicInvoicingDecider::ELECTRONICO) {
            throw new RuntimeException(sprintf(
                'La factura %s es un documento interno: no se le puede armar un XML para la DIAN.',
                $factura->full_number,
            ));
        }

        $empresa = $rango->resolution->company;
        $cliente = $factura->contract?->client;

        $this->exigirDatos($empresa, $cliente);

        $momento = $this->momentoDe($factura);
        $ambiente = (string) $configuracion->environment_code;
        $produccion = $ambiente === DianConfiguration::PRODUCCION;

        $cufe = $this->cufe->calcular(
            numeroFactura: $factura->full_number,
            fecha: $momento->format('Y-m-d'),
            hora: $this->cufe->horaDe($momento),
            valorSinImpuestos: (float) $factura->subtotal,
            iva: (float) $factura->tax,
            inc: 0.0,
            ica: 0.0,
            valorTotal: (float) $factura->total,
            nitEmisor: (string) $empresa->document_number,
            documentoAdquiriente: (string) $cliente->identity_number,
            claveTecnica: (string) $rango->resolution->technical_key,
            ambiente: $ambiente,
        );

        $qr = $this->qr->construir(
            numeroFactura: $factura->full_number,
            fecha: $momento->format('Y-m-d'),
            hora: $this->cufe->horaDe($momento),
            nitFacturador: (string) $empresa->document_number,
            documentoAdquiriente: (string) $cliente->identity_number,
            valorSinImpuestos: (float) $factura->subtotal,
            iva: (float) $factura->tax,
            otrosImpuestos: 0.0,
            valorTotal: (float) $factura->total,
            cufe: $cufe,
            produccion: $produccion,
        );

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $raiz = $doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            'Invoice',
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

        $this->extensiones($doc, $raiz, $factura, $rango, $configuracion, $cufe, $produccion);
        $this->cabecera($doc, $raiz, $factura, $momento, $cufe, $ambiente);
        $this->emisor($doc, $raiz, $empresa, (string) $rango->prefix);
        $this->adquiriente($doc, $raiz, $cliente);
        $this->formaDePago($doc, $raiz, $factura);
        $this->impuestos($doc, $raiz, $factura);
        $this->totales($doc, $raiz, $factura);
        $this->lineas($doc, $raiz, $factura);

        return ['xml' => $doc->saveXML(), 'cufe' => $cufe, 'qr' => $qr];
    }

    /**
     * Valida un XML contra el esquema de la DIAN.
     *
     * Devuelve la lista de errores; vacía si es válido. Se separa de la
     * construcción para poder llamarla también justo antes de
     * transmitir, que es cuando de verdad importa.
     *
     * @return array<int, string>
     */
    public function erroresDeEsquema(string $xml): array
    {
        return $this->validarContra($xml, 'UBL-Invoice-2.1.xsd');
    }

    // ==================== Los bloques ====================

    private function extensiones(
        \DOMDocument $doc,
        \DOMElement $raiz,
        Invoice $factura,
        NumberingRange $rango,
        DianConfiguration $configuracion,
        string $cufe,
        bool $produccion,
    ): void {
        $extensiones = $this->hijo($doc, $raiz, 'ext:UBLExtensions');
        $extension = $this->hijo($doc, $extensiones, 'ext:UBLExtension');
        $contenido = $this->hijo($doc, $extension, 'ext:ExtensionContent');
        $dian = $this->hijo($doc, $contenido, 'sts:DianExtensions');

        // ---- Control de la numeración autorizada ----
        $control = $this->hijo($doc, $dian, 'sts:InvoiceControl');
        $this->hijo($doc, $control, 'sts:InvoiceAuthorization', (string) $rango->resolution->resolution_number);

        $periodo = $this->hijo($doc, $control, 'sts:AuthorizationPeriod');
        $this->hijo($doc, $periodo, 'cbc:StartDate', Carbon::parse($rango->resolution->valid_from)->format('Y-m-d'));
        $this->hijo($doc, $periodo, 'cbc:EndDate', Carbon::parse($rango->resolution->valid_until)->format('Y-m-d'));

        $autorizadas = $this->hijo($doc, $control, 'sts:AuthorizedInvoices');
        $this->hijo($doc, $autorizadas, 'sts:Prefix', (string) $rango->prefix);
        $this->hijo($doc, $autorizadas, 'sts:From', (string) $rango->range_start);
        $this->hijo($doc, $autorizadas, 'sts:To', (string) $rango->range_end);

        // ---- País de origen ----
        $origen = $this->hijo($doc, $dian, 'sts:InvoiceSource');
        $codigoPais = $this->hijo($doc, $origen, 'cbc:IdentificationCode', self::PAIS);
        $codigoPais->setAttribute('listAgencyID', '6');
        $codigoPais->setAttribute('listAgencyName', 'United Nations Economic Commission for Europe');
        $codigoPais->setAttribute(
            'listSchemeURI',
            'urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1',
        );

        // ---- El software que emitió ----
        $empresa = $rango->resolution->company;
        $proveedor = $this->hijo($doc, $dian, 'sts:SoftwareProvider');

        // Emisión DIRECTA: el proveedor del software es la propia
        // empresa. Si algún día se pasa por un proveedor tecnológico,
        // aquí va el NIT del PT y no el del contribuyente.
        $proveedorId = $this->hijo($doc, $proveedor, 'sts:ProviderID', (string) $empresa->document_number);
        $proveedorId->setAttribute('schemeID', (string) ($empresa->verification_digit ?? '0'));
        $proveedorId->setAttribute('schemeName', (string) $empresa->document_type_code);
        $proveedorId->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $proveedorId->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        $softwareId = $this->hijo($doc, $proveedor, 'sts:SoftwareID', (string) $configuracion->software_id);
        $softwareId->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $softwareId->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        // El PIN no viaja nunca: viaja su hash junto al número del
        // documento. Por eso software_pin está cifrado en la base.
        $codigo = $this->hijo($doc, $dian, 'sts:SoftwareSecurityCode', $this->codigoSoftware->calcular(
            (string) $configuracion->software_id,
            (string) $configuracion->software_pin,
            (string) $factura->full_number,
        ));
        $codigo->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $codigo->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        // ---- Quien autoriza: la DIAN ----
        $autorizador = $this->hijo($doc, $dian, 'sts:AuthorizationProvider');
        $autorizadorId = $this->hijo($doc, $autorizador, 'sts:AuthorizationProviderID', self::NIT_DIAN);
        $autorizadorId->setAttribute('schemeID', '4');
        $autorizadorId->setAttribute('schemeName', '31');
        $autorizadorId->setAttribute('schemeAgencyID', self::AGENCIA_ID);
        $autorizadorId->setAttribute('schemeAgencyName', self::AGENCIA_NOMBRE);

        // En el XML va la URL. El texto completo con las etiquetas
        // (NumFac, FecFac…) es para la representación gráfica.
        $this->hijo($doc, $dian, 'sts:QRCode', $this->qr->url($cufe, $produccion));
    }

    private function cabecera(
        \DOMDocument $doc,
        \DOMElement $raiz,
        Invoice $factura,
        Carbon $momento,
        string $cufe,
        string $ambiente,
    ): void {
        $this->hijo($doc, $raiz, 'cbc:UBLVersionID', 'UBL 2.1');
        $this->hijo($doc, $raiz, 'cbc:CustomizationID', '10');
        $this->hijo($doc, $raiz, 'cbc:ProfileID', 'DIAN 2.1: Factura Electrónica de Venta');

        // 1 producción, 2 pruebas. Es el mismo código del ambiente de la
        // configuración, y va también como schemeID del CUFE.
        $this->hijo($doc, $raiz, 'cbc:ProfileExecutionID', $ambiente);
        $this->hijo($doc, $raiz, 'cbc:ID', (string) $factura->full_number);

        $uuid = $this->hijo($doc, $raiz, 'cbc:UUID', $cufe);
        $uuid->setAttribute('schemeID', $ambiente);
        $uuid->setAttribute('schemeName', 'CUFE-SHA384');

        $this->hijo($doc, $raiz, 'cbc:IssueDate', $momento->format('Y-m-d'));
        $this->hijo($doc, $raiz, 'cbc:IssueTime', $this->cufe->horaDe($momento));
        $this->hijo($doc, $raiz, 'cbc:InvoiceTypeCode', self::TIPO_FACTURA);

        $moneda = $this->hijo($doc, $raiz, 'cbc:DocumentCurrencyCode', self::MONEDA);
        $moneda->setAttribute('listID', 'ISO 4217 Alpha');
        $moneda->setAttribute('listAgencyID', '6');
        $moneda->setAttribute('listAgencyName', 'United Nations Economic Commission for Europe');

        $this->hijo($doc, $raiz, 'cbc:LineCountNumeric', (string) $factura->invoice_items->count());
    }





    private function formaDePago(\DOMDocument $doc, \DOMElement $raiz, Invoice $factura): void
    {
        $nodo = $this->hijo($doc, $raiz, 'cac:PaymentMeans');

        // 1 contado, 2 crédito. Una factura cuyo vencimiento es
        // posterior a su emisión es a crédito.
        $aCredito = $factura->due_date
            && $factura->issue_date
            && Carbon::parse($factura->due_date)->gt(Carbon::parse($factura->issue_date));

        $this->hijo($doc, $nodo, 'cbc:ID', $aCredito ? '2' : '1');
        $this->hijo($doc, $nodo, 'cbc:PaymentMeansCode', (string) ($factura->payment_means_code ?: '10'));

        if ($factura->due_date) {
            $this->hijo($doc, $nodo, 'cbc:PaymentDueDate', Carbon::parse($factura->due_date)->format('Y-m-d'));
        }
    }

    /**
     * El bloque de impuestos del documento.
     *
     * Lo que decide si va o no es la CLASIFICACION de cada linea, no su
     * tarifa. Un servicio EXCLUIDO no lleva bloque de impuestos —la ley
     * no lo sujeta a IVA—, mientras que uno EXENTO si lo lleva, en
     * ceros. Decir «cero impuesto» y «no hay impuesto» son cosas
     * distintas para la DIAN, y se comprobo en sus propios ejemplos.
     */
    private function impuestos(\DOMDocument $doc, \DOMElement $raiz, Invoice $factura): void
    {
        // Entran las gravadas y las exentas; las excluidas no.
        $conImpuesto = $factura->invoice_items->filter(
            fn ($item) => $this->clasificacionDe($item)->llevaBloqueDeImpuestos(),
        );

        if ($conImpuesto->isEmpty()) {
            return;
        }

        $gravadas = $conImpuesto;

        $nodo = $this->hijo($doc, $raiz, 'cac:TaxTotal');
        $this->importe($doc, $nodo, 'cbc:TaxAmount', (float) $factura->tax);

        // Un subtotal por cada tarifa distinta: la DIAN los quiere
        // agrupados por porcentaje, no línea a línea. Las exentas
        // forman su propio grupo al 0,00.
        foreach ($gravadas->groupBy('percentage_tax') as $porcentaje => $lineas) {
            $subtotal = $this->hijo($doc, $nodo, 'cac:TaxSubtotal');

            $this->importe($doc, $subtotal, 'cbc:TaxableAmount', (float) $lineas->sum(
                fn ($linea) => (float) $linea->unit_price * (float) $linea->quantity,
            ));
            $this->importe($doc, $subtotal, 'cbc:TaxAmount', (float) $lineas->sum('tax'));

            $categoria = $this->hijo($doc, $subtotal, 'cac:TaxCategory');
            $this->hijo($doc, $categoria, 'cbc:Percent', number_format((float) $porcentaje, 2, '.', ''));
            $this->esquemaIva($doc, $categoria);
        }
    }

    private function totales(\DOMDocument $doc, \DOMElement $raiz, Invoice $factura): void
    {
        $nodo = $this->hijo($doc, $raiz, 'cac:LegalMonetaryTotal');

        $this->importe($doc, $nodo, 'cbc:LineExtensionAmount', (float) $factura->subtotal);
        $this->importe($doc, $nodo, 'cbc:TaxExclusiveAmount', (float) $factura->subtotal);
        $this->importe($doc, $nodo, 'cbc:TaxInclusiveAmount', (float) $factura->subtotal + (float) $factura->tax);

        if ((float) $factura->discount > 0) {
            $this->importe($doc, $nodo, 'cbc:AllowanceTotalAmount', (float) $factura->discount);
        }

        $this->importe($doc, $nodo, 'cbc:PayableAmount', (float) $factura->total);
    }

    private function lineas(\DOMDocument $doc, \DOMElement $raiz, Invoice $factura): void
    {
        foreach ($factura->invoice_items as $indice => $item) {
            $nodo = $this->hijo($doc, $raiz, 'cac:InvoiceLine');

            $this->hijo($doc, $nodo, 'cbc:ID', (string) ($indice + 1));

            $unidad = $item->unit_measure_code ?: '94';

            $cantidad = $this->hijo($doc, $nodo, 'cbc:InvoicedQuantity', number_format((float) $item->quantity, 6, '.', ''));
            $cantidad->setAttribute('unitCode', $unidad);

            $this->importe($doc, $nodo, 'cbc:LineExtensionAmount', (float) $item->unit_price * (float) $item->quantity);
            $this->hijo($doc, $nodo, 'cbc:FreeOfChargeIndicator', 'false');

            // Igual que arriba: manda la clasificacion, no la tarifa.
            if ($this->clasificacionDe($item)->llevaBloqueDeImpuestos()) {
                $impuesto = $this->hijo($doc, $nodo, 'cac:TaxTotal');
                $this->importe($doc, $impuesto, 'cbc:TaxAmount', (float) $item->tax);

                $subtotal = $this->hijo($doc, $impuesto, 'cac:TaxSubtotal');
                $this->importe($doc, $subtotal, 'cbc:TaxableAmount', (float) $item->unit_price * (float) $item->quantity);
                $this->importe($doc, $subtotal, 'cbc:TaxAmount', (float) $item->tax);

                $categoria = $this->hijo($doc, $subtotal, 'cac:TaxCategory');
                $this->hijo($doc, $categoria, 'cbc:Percent', number_format((float) $item->percentage_tax, 2, '.', ''));
                $this->esquemaIva($doc, $categoria);
            }

            $articulo = $this->hijo($doc, $nodo, 'cac:Item');
            $this->hijo($doc, $articulo, 'cbc:Description', (string) $item->description);

            // El código de producto es el que se congeló en la línea al
            // emitir. Las líneas que no vienen de un servicio —los
            // cargos adicionales— no lo tienen: se declaran con el
            // esquema 999, que es el del código interno del vendedor.
            $identificacion = $this->hijo($doc, $articulo, 'cac:StandardItemIdentification');
            $id = $this->hijo($doc, $identificacion, 'cbc:ID', (string) ($item->product_code ?: $item->description));
            $id->setAttribute('schemeID', (string) ($item->product_code_type ?: '999'));

            $precio = $this->hijo($doc, $nodo, 'cac:Price');
            $this->importe($doc, $precio, 'cbc:PriceAmount', (float) $item->unit_price);
            $base = $this->hijo($doc, $precio, 'cbc:BaseQuantity', number_format((float) $item->quantity, 6, '.', ''));
            $base->setAttribute('unitCode', $unidad);
        }
    }

    /**
     * Como se trato el IVA en una linea.
     *
     * Se lee de lo que se CONGELO al emitir. Las lineas anteriores a la
     * clasificacion no la tienen: se deduce de la tarifa, que es lo que
     * habia entonces.
     */
    private function clasificacionDe($item): \App\Billing\Enums\TaxClassification
    {
        if ($item->tax_classification) {
            return \App\Billing\Enums\TaxClassification::from($item->tax_classification);
        }

        return (float) $item->percentage_tax > 0
            ? \App\Billing\Enums\TaxClassification::Gravado
            : \App\Billing\Enums\TaxClassification::Excluido;
    }

    // ==================== Apoyo ====================












}
