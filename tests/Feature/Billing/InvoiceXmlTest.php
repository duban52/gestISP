<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\InvoiceXmlBuilder;
use App\Billing\Services\InvoiceGenerator;
use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\FiscalCatalog;
use App\Models\Invoice;
use App\Models\NumberingRange;
use RuntimeException;

/**
 * El XML de la factura electrónica.
 *
 * LA PRUEBA QUE VALE ES LA DEL ESQUEMA
 * ------------------------------------
 * En UBL el ORDEN de los elementos es parte del contrato. Un XML con
 * todos los datos correctos pero en distinto orden es un XML inválido,
 * y eso no se ve leyéndolo: se ve cuando la DIAN lo rechaza.
 *
 * Por eso la prueba central de este archivo no comprueba valores sino
 * que valida el documento generado contra el **XSD de la propia DIAN**,
 * versionado en `resources/dian/xsd`. Es la única forma de saber que lo
 * que se produce es un UBL 2.1 legítimo sin tener una habilitación.
 *
 * Lo demás que se comprueba aquí son los datos que la DIAN no puede
 * validar por esquema pero rechaza igual: que el bloque de numeración
 * diga la resolución de verdad, que el CUFE sea el de esta factura y no
 * otro, y que faltar un dato fiscal falle nombrándolo en vez de
 * producir un XML que se rechaza sin explicación.
 */
class InvoiceXmlTest extends BillingTestCase
{
    private NumberingRange $rango;
    private DianConfiguration $configuracion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrarUbicaciones();
        $this->completarEmpresa();

        // PRODUCCION y habilitada a proposito: son dos de las tres
        // condiciones que exige ElectronicInvoicingDecider. Sin ellas
        // la factura sale como documento INTERNO y se numera de la
        // serie interna — y entonces esto no estaria probando una
        // factura electronica sino otra cosa.
        $this->configuracion = DianConfiguration::create([
            'company_id' => $this->branch->company_id,
            'environment_code' => DianConfiguration::PRODUCCION,
            'enabled_at' => now(),
            'software_id' => 'fa326ca7-c1f8-40d3-a6fc-24d7c1040607',
            'software_pin' => 'PIN-QUE-NO-PUEDE-SALIR-POR-AZAR',
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

    // ==================== Lo que de verdad se defiende ====================

    public function test_el_xml_generado_valida_contra_el_esquema_de_la_dian(): void
    {
        // Es la prueba central: el orden de los elementos en UBL es
        // parte del contrato y equivocarse no se nota leyendo.
        $resultado = $this->construirXml($this->facturaElectronica());

        $this->assertSame(
            [],
            app(InvoiceXmlBuilder::class)->erroresDeEsquema($resultado['xml']),
            'El XML no valida contra el XSD de la DIAN.',
        );
    }

    public function test_valida_tambien_con_varias_lineas_y_sin_impuesto(): void
    {
        // Un contrato con dos servicios, uno gravado y otro no: es el
        // caso donde se rompen los totales y los agrupamientos.
        $contrato = $this->contratoElectronico(precio: 50000, iva: 19);

        $servicio = \App\Models\Service::factory()->create([
            'base_price' => 30000,
            'tax_percentage' => 0,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'product_code' => '81161700',
            'product_code_type' => '001',
            'unit_measure_code' => '94',
        ]);
        $contrato->plan->services()->attach($servicio->id);

        $resultado = $this->construirXml($this->emitir($contrato->fresh()));

        $this->assertSame([], app(InvoiceXmlBuilder::class)->erroresDeEsquema($resultado['xml']));
    }

    public function test_el_numero_del_xml_sale_del_rango_autorizado(): void
    {
        // Parece obvio y no lo es: si la empresa no cumple las tres
        // condiciones, la factura sale como documento INTERNO con su
        // numeracion propia, y aun asi se le podria armar un XML —con
        // un numero que la DIAN nunca autorizo dentro de un bloque de
        // numeracion autorizada—. Esta prueba es la que lo fija.
        $factura = $this->facturaElectronica();

        $this->assertSame('SETP', $factura->prefix);
        $this->assertSame($this->rango->range_start, (int) $factura->number);
        $this->assertStringContainsString(
            '<cbc:ID>SETP' . $this->rango->range_start . '</cbc:ID>',
            $this->construirXml($factura)['xml'],
        );
    }

    public function test_un_documento_interno_no_produce_xml_de_la_dian(): void
    {
        // Un documento que el sistema decidio que NO es electronico no
        // puede acabar convertido en uno: llevaria un consecutivo de la
        // serie interna presentado como numeracion autorizada.
        $interno = $this->emitir($this->contratoElectronico());
        $interno->update(['document_kind' => \App\Billing\Services\ElectronicInvoicingDecider::INTERNO]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/documento interno/');

        $this->construirXml($interno->fresh());
    }

    // ==================== El bloque de la DIAN ====================

    public function test_el_bloque_de_numeracion_dice_la_resolucion_de_verdad(): void
    {
        // Si aquí va otra resolución o otro rango, la DIAN rechaza el
        // documento aunque el número sea correcto.
        $xml = $this->construirXml($this->facturaElectronica())['xml'];

        $this->assertStringContainsString('<sts:InvoiceAuthorization>18760000001</sts:InvoiceAuthorization>', $xml);
        $this->assertStringContainsString('<sts:Prefix>SETP</sts:Prefix>', $xml);
        $this->assertStringContainsString('<sts:From>990000000</sts:From>', $xml);
        $this->assertStringContainsString('<sts:To>995000000</sts:To>', $xml);
    }

    public function test_el_cufe_del_xml_es_el_de_esta_factura(): void
    {
        $factura = $this->facturaElectronica();
        $resultado = $this->construirXml($factura);

        // El mismo CUFE en el cbc:UUID, en el QR y en lo que se
        // devuelve para guardar: si divergen, el documento impreso
        // apunta a un CUFE distinto del transmitido.
        $this->assertStringContainsString('>' . $resultado['cufe'] . '<', $resultado['xml']);
        $this->assertStringContainsString('CUFE: ' . $resultado['cufe'], $resultado['qr']);
        $this->assertSame(96, strlen($resultado['cufe']));
    }

    public function test_el_pin_del_software_no_viaja_en_el_xml(): void
    {
        // Viaja su hash, nunca el PIN. Es lo que impide que quien vea
        // un XML pueda hacerse pasar por este software.
        $xml = $this->construirXml($this->facturaElectronica())['xml'];

        $this->assertStringNotContainsString('PIN-QUE-NO-PUEDE-SALIR-POR-AZAR', $xml);
        $this->assertStringContainsString('<sts:SoftwareSecurityCode', $xml);
    }

    public function test_el_ambiente_del_documento_es_el_de_la_configuracion(): void
    {
        // El ambiente no se deduce: sale de la configuracion, y va en
        // tres sitios que tienen que decir lo mismo — el
        // ProfileExecutionID, el schemeID del CUFE y la URL del QR.
        // (El caso de pruebas se cubre en QrContentTest, porque una
        // empresa en pruebas no llega a emitir electronicamente.)
        $xml = $this->construirXml($this->facturaElectronica())['xml'];

        $this->assertStringContainsString('<cbc:ProfileExecutionID>1</cbc:ProfileExecutionID>', $xml);
        $this->assertStringContainsString('schemeID="1" schemeName="CUFE-SHA384"', $xml);
        $this->assertStringContainsString('catalogo-vpfe.dian.gov.co', $xml);
        $this->assertStringNotContainsString('catalogo-vpfe-hab', $xml);
    }

    // ==================== Las líneas ====================

    public function test_la_linea_lleva_el_codigo_de_producto_que_se_congelo(): void
    {
        // No se lee del servicio al vuelo: se copió a la línea al
        // emitir, para que corregir el servicio mañana no cambie una
        // factura ya transmitida.
        $factura = $this->facturaElectronica();

        $factura->invoice_items()->update([
            'product_code' => '81161700',
            'product_code_type' => '001',
            'unit_measure_code' => '94',
        ]);

        $xml = $this->construirXml($factura->fresh())['xml'];

        $this->assertStringContainsString('schemeID="001">81161700</cbc:ID>', $xml);
        $this->assertStringContainsString('unitCode="94"', $xml);
    }

    public function test_una_linea_sin_codigo_se_declara_como_codigo_interno(): void
    {
        // Los cargos adicionales no vienen de ningún servicio. El
        // esquema 999 es el del código interno del vendedor: es lo que
        // corresponde, y deja el documento válido.
        $factura = $this->facturaElectronica();
        $factura->invoice_items()->update(['product_code' => null, 'product_code_type' => null]);

        $resultado = $this->construirXml($factura->fresh());

        $this->assertStringContainsString('schemeID="999"', $resultado['xml']);
        $this->assertSame([], app(InvoiceXmlBuilder::class)->erroresDeEsquema($resultado['xml']));
    }

    // ============ Lo que la DIAN rechazó de verdad ============
    //
    // Las cuatro pruebas que siguen no salen del anexo: salen de una
    // factura que la DIAN rechazó el 2026-09-08, con el código de cada
    // regla en el nombre. Antes de esto el XML validaba contra el XSD
    // —y el XSD no comprueba nada de esto—.

    public function test_fab23_el_emisor_se_identifica_siempre_con_nit(): void
    {
        // Quien factura electrónicamente está en el RUT y ante la DIAN
        // es un NIT (31), aunque sea persona natural y su NIT sea su
        // cédula. La empresa tenía guardado el tipo 11 y salía tal cual:
        // «Identificador del tipo de documento de identidad no es igual
        // a 31».
        $this->empresa()->update(['document_type_code' => '11']);

        $xpath = $this->xpath($this->construirXml($this->facturaElectronica())['xml']);

        $emisor = $xpath->query('//cac:AccountingSupplierParty')->item(0);

        foreach (['cac:PartyTaxScheme', 'cac:PartyLegalEntity'] as $grupo) {
            $id = $xpath->query('cac:Party/' . $grupo . '/cbc:CompanyID', $emisor)->item(0);

            $this->assertNotNull($id, "No se encontró el CompanyID de {$grupo}.");
            $this->assertSame('31', $id->getAttribute('schemeName'), "En {$grupo} el emisor no va como NIT.");
        }
    }

    public function test_faj26_una_responsabilidad_que_la_dian_no_acepta_no_viaja(): void
    {
        // `ZZ` («No aplica») VIENE en el catálogo oficial de la DIAN, se
        // sembró, se pudo elegir en el panel… y su propio validador lo
        // rechaza. Se filtra al construir el XML: lo que se elige en el
        // panel no puede tumbar la factura.
        $this->empresa()->taxResponsibilities()->delete();
        $this->empresa()->taxResponsibilities()->create(['responsibility_code' => 'ZZ']);

        $xml = $this->construirXml($this->facturaElectronica())['xml'];

        $this->assertStringNotContainsString('<cbc:TaxLevelCode>ZZ</cbc:TaxLevelCode>', $xml);
        $this->assertStringContainsString('<cbc:TaxLevelCode>R-99-PN</cbc:TaxLevelCode>', $xml);
    }

    public function test_fak61_el_adquiriente_lleva_su_identificacion(): void
    {
        // ESTA PRUEBA ESTUVO MAL, y el error se envió a la DIAN.
        //
        // FAK61 dice «Si el valor de AdditionalAccountID es igual a "2"
        // y el grupo no es informado», sin nombrar el grupo. Se supuso
        // que era `cac:Person`, se emitió con él, y la DIAN volvió a
        // rechazar con FAK61 exactamente igual. El grupo es
        // `cac:PartyIdentification`, y va como PRIMER hijo de
        // `cac:Party` porque así lo ordena el XSD.
        $contrato = $this->contratoElectronico();
        $contrato->client->update(['name' => 'Marina', 'last_name' => 'Quiceno Ospina']);

        $resultado = $this->construirXml($this->emitir($contrato->fresh()));

        $xpath = $this->xpath($resultado['xml']);

        $parte = $xpath->query('//cac:AccountingCustomerParty/cac:Party')->item(0);
        $id = $xpath->query('cac:PartyIdentification/cbc:ID', $parte)->item(0);

        $this->assertNotNull($id, 'El adquiriente tiene que llevar cac:PartyIdentification.');
        $this->assertSame($contrato->client->identity_number, $id->nodeValue);
        $this->assertSame('13', $id->getAttribute('schemeName'), 'schemeName es el tipo de documento.');

        // Primero de todo: el orden dentro de cac:Party lo fija el XSD.
        $this->assertSame('PartyIdentification', $parte->firstElementChild->localName);

        $this->assertSame([], app(InvoiceXmlBuilder::class)->erroresDeEsquema($resultado['xml']));
    }

    public function test_fau04_la_base_imponible_es_la_de_las_lineas_con_impuesto(): void
    {
        // `TaxExclusiveAmount` no es «el total antes de impuestos»: es
        // la base imponible, y la DIAN la compara contra la suma de las
        // bases de las líneas. En una factura de servicios EXCLUIDOS
        // —el caso normal de un ISP: internet residencial de estratos
        // 1 a 3— no hay ninguna base, así que va en cero.
        $factura = $this->emitir($this->contratoElectronico(precio: 80000, iva: 0));

        // EXCLUIDO, no «al 0 %». Son cosas distintas: un servicio al
        // 0 % nace clasificado como gravado y sí declara impuesto —en
        // ceros—; uno excluido no declara ninguno. La factura que la
        // DIAN rechazó era de este segundo tipo.
        $factura->invoice_items()->update(['tax_classification' => 'excluido']);

        $xml = $this->construirXml($factura->fresh())['xml'];

        $this->assertStringNotContainsString('<cac:TaxTotal>', $xml);
        $this->assertStringContainsString('<cbc:LineExtensionAmount currencyID="COP">80000.00<', $xml);
        $this->assertStringContainsString('<cbc:TaxExclusiveAmount currencyID="COP">0.00<', $xml);
    }

    public function test_fau04_con_iva_la_base_imponible_es_la_suma_de_las_lineas(): void
    {
        // El otro lado de lo mismo: cuando sí hay impuesto, la base del
        // documento tiene que ser exactamente la suma de las bases
        // declaradas en las líneas.
        $xml = $this->construirXml($this->emitir($this->contratoElectronico(precio: 100000, iva: 19)))['xml'];

        $xpath = $this->xpath($xml);

        $documento = (float) $xpath->query('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount')->item(0)->nodeValue;

        $lineas = 0.0;
        foreach ($xpath->query('//cac:InvoiceLine/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount') as $base) {
            $lineas += (float) $base->nodeValue;
        }

        $this->assertGreaterThan(0, $lineas, 'La prueba no sirve si ninguna línea declara base.');
        $this->assertSame($lineas, $documento, 'La base del documento no cuadra con la de las líneas (FAU04).');
    }

    // ==================== Cuando faltan datos ====================

    public function test_sin_datos_fiscales_del_cliente_falla_diciendo_cuales(): void
    {
        // Un XML incompleto lo rechaza la DIAN con un código que no
        // explica nada. Es mejor no producirlo y decir qué falta.
        $factura = $this->facturaElectronica();

        $factura->contract->client->update([
            'municipality_dane_code' => null,
            'fiscal_address' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/municipio.*cliente|dirección fiscal del cliente/');

        $this->construirXml($factura->fresh());
    }

    public function test_sin_datos_fiscales_de_la_empresa_tambien_falla(): void
    {
        $this->empresa()->update(['municipality_dane_code' => null]);

        $factura = $this->facturaElectronica();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/municipio.*empresa/');

        $this->construirXml($factura);
    }

    // ==================== Apoyo ====================

    private function empresa(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->branch->company_id);
    }

    /** Los códigos DANE que usa el XML. Se siembran los que hagan falta. */
    private function sembrarUbicaciones(): void
    {
        FiscalCatalog::insert([
            ['catalog' => FiscalCatalog::DEPARTAMENTO, 'code' => '05', 'name' => 'Antioquia',
             'parent_code' => null, 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function completarEmpresa(): void
    {
        $empresa = $this->empresa();

        $empresa->update([
            'legal_name' => 'Fibra Andina S.A.S.',
            'document_type_code' => '31',
            'document_number' => '900374637',
            'verification_digit' => '9',
            'organization_type_code' => '1',
            'address' => 'Calle 50 # 40-30',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'facturacion@fibraandina.co',
            'phone' => '6041234567',
            'electronic_invoicing_enabled' => true,
        ]);

        $empresa->taxResponsibilities()->create(['responsibility_code' => 'O-13']);
    }

    /** Un contrato de grupo electrónico con su cliente completo. */
    private function contratoElectronico(float $precio = 100000, float $iva = 19): Contract
    {
        $grupo = AffinityGroup::factory()->electronico()->create([
            'company_id' => $this->branch->company_id,
        ]);

        $contrato = $this->createBillableContract(price: $precio, taxPercent: $iva);
        $contrato->update(['affinity_group_id' => $grupo->id]);

        $contrato->client->update([
            'document_type_code' => '13',
            'organization_type_code' => '2',
            'fiscal_address' => 'Carrera 70 # 30-20',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'cliente@ejemplo.com',
        ]);

        return $contrato->fresh();
    }

    private function facturaElectronica(): Invoice
    {
        return $this->emitir($this->contratoElectronico());
    }

    private function emitir(Contract $contrato): Invoice
    {
        $resultado = app(InvoiceGenerator::class)->generateForContract(
            $contrato,
            now(),
            $this->admin->id,
        );

        $this->assertTrue(
            $resultado['generated'],
            'No se generó la factura: ' . ($resultado['reason'] ?? 'sin motivo'),
        );

        return $resultado['invoice'];
    }

    private function xpath(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xpath;
    }

    private function nodo(string $xml, string $consulta): ?\DOMElement
    {
        $encontrado = $this->xpath($xml)->query($consulta)->item(0);

        return $encontrado instanceof \DOMElement ? $encontrado : null;
    }

    /** @return array{xml: string, cufe: string, qr: string} */
    private function construirXml(Invoice $factura): array
    {
        return app(InvoiceXmlBuilder::class)->construir(
            $factura->load('invoice_items', 'contract.client.taxResponsibilities'),
            $this->rango->fresh(),
            $this->configuracion->fresh(),
        );
    }
}
