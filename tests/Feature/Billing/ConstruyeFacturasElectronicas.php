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

/**
 * Todo lo que hace falta para que una factura salga ELECTRONICA.
 *
 * Son tres condiciones que exige `ElectronicInvoicingDecider` y que no
 * se cumplen solas: empresa con datos fiscales completos, configuracion
 * DIAN habilitada y un rango de numeracion autorizado. Sin las tres la
 * factura sale como documento INTERNO — y entonces la prueba no esta
 * midiendo lo que cree.
 *
 * Vive en un rasgo y no en una clase base porque quien lo usa ya
 * extiende `BillingTestCase`; y porque heredar de otra PRUEBA haria que
 * PHPUnit volviera a ejecutar todos sus casos.
 */
trait ConstruyeFacturasElectronicas
{
    protected NumberingRange $rango;
    protected DianConfiguration $configuracion;

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

    // ==================== Apoyo ====================

    protected function empresa(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->branch->company_id);
    }

    /** Los códigos DANE que usa el XML. Se siembran los que hagan falta. */
    protected function sembrarUbicaciones(): void
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

    protected function completarEmpresa(): void
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
    protected function contratoElectronico(float $precio = 100000, float $iva = 19): Contract
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

    protected function facturaElectronica(): Invoice
    {
        return $this->emitir($this->contratoElectronico());
    }

    protected function emitir(Contract $contrato): Invoice
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

    protected function xpath(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xpath;
    }

    protected function nodo(string $xml, string $consulta): ?\DOMElement
    {
        $encontrado = $this->xpath($xml)->query($consulta)->item(0);

        return $encontrado instanceof \DOMElement ? $encontrado : null;
    }

    /** @return array{xml: string, cufe: string, qr: string} */
    protected function construirXml(Invoice $factura): array
    {
        return app(InvoiceXmlBuilder::class)->construir(
            $factura->load('invoice_items', 'contract.client.taxResponsibilities'),
            $this->rango->fresh(),
            $this->configuracion->fresh(),
        );
    }
}
