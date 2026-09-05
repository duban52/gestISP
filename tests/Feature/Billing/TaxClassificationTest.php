<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\InvoiceXmlBuilder;
use App\Billing\Enums\TaxClassification;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\TaxClassificationAdvisor;
use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\FiscalCatalog;
use App\Models\Invoice;
use App\Models\NumberingRange;
use App\Models\Service;

/**
 * Clasificación fiscal: gravado, excluido y exento.
 *
 * POR QUÉ NO BASTABA CON LA TARIFA
 * --------------------------------
 * Antes la distinción vivía implícita en `tax_percentage = 0`, y ese
 * cero significaba tres cosas que el sistema no podía diferenciar:
 * excluido, exento, o que a alguien se le olvidó la tarifa.
 *
 * Y en el XML de la DIAN **las dos primeras no se escriben igual**:
 *
 *   · Excluido → el documento NO lleva `cac:TaxTotal`.
 *   · Exento   → SÍ lo lleva, con `TaxAmount` 0.00 y `Percent` 0.00.
 *
 * Decir «cero impuesto» y «no hay impuesto» son cosas distintas. Eso es
 * lo que se defiende aquí, y se comprobó contra los ejemplos oficiales
 * `Exento de IVA.xml` y `Excluido de IVA.xml`.
 *
 * EL AVISO NO DECIDE
 * ------------------
 * El internet residencial de estratos 1, 2 y 3 está excluido de IVA;
 * para los demás se grava a la tarifa general. Pero esa regla **avisa**,
 * no bloquea: meterla dentro del código que factura sería enterrar
 * derecho tributario donde nadie lo ve.
 */
class TaxClassificationTest extends BillingTestCase
{
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

        $this->empresa()->update([
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

        DianConfiguration::create([
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
            'technical_key' => 'clave',
        ]);

        NumberingRange::withoutGlobalScopes()->create([
            'dian_resolution_id' => $resolucion->id,
            'branch_id' => $this->branch->id,
            'prefix' => 'SETP',
            'range_start' => 990000000,
            'range_end' => 995000000,
            'current_number' => 0,
        ]);
    }

    // ==================== Lo que de verdad se defiende ====================

    public function test_un_servicio_excluido_no_lleva_bloque_de_impuestos(): void
    {
        // Es el caso del internet residencial de estratos 1, 2 y 3: la
        // ley no lo sujeta a IVA, así que el XML no lleva TaxTotal.
        $xml = $this->xmlDeFacturaCon(TaxClassification::Excluido, tarifa: 0);

        $this->assertStringNotContainsString('<cac:TaxTotal>', $xml);
        $this->assertSame([], app(InvoiceXmlBuilder::class)->erroresDeEsquema($xml));
    }

    public function test_un_servicio_exento_si_lo_lleva_en_ceros(): void
    {
        // Sujeto a IVA pero a tarifa 0%: el bloque va, con ceros. Decir
        // «cero impuesto» y «no hay impuesto» son cosas distintas.
        $xml = $this->xmlDeFacturaCon(TaxClassification::Exento, tarifa: 0);

        $this->assertStringContainsString('<cac:TaxTotal>', $xml);
        $this->assertStringContainsString('<cbc:Percent>0.00</cbc:Percent>', $xml);
        $this->assertSame([], app(InvoiceXmlBuilder::class)->erroresDeEsquema($xml));
    }

    public function test_excluido_y_exento_producen_xml_distinto(): void
    {
        // Si salieran iguales, la clasificación no serviría para nada.
        $excluido = $this->xmlDeFacturaCon(TaxClassification::Excluido, tarifa: 0);
        $exento = $this->xmlDeFacturaCon(TaxClassification::Exento, tarifa: 0);

        $this->assertNotSame(
            substr_count($excluido, 'TaxTotal'),
            substr_count($exento, 'TaxTotal'),
        );
    }

    public function test_un_servicio_gravado_lleva_su_tarifa(): void
    {
        $xml = $this->xmlDeFacturaCon(TaxClassification::Gravado, tarifa: 19);

        $this->assertStringContainsString('<cbc:Percent>19.00</cbc:Percent>', $xml);
        $this->assertSame([], app(InvoiceXmlBuilder::class)->erroresDeEsquema($xml));
    }

    public function test_la_clasificacion_se_congela_en_la_linea(): void
    {
        // La factura emitida tiene que seguir diciendo cómo se trató el
        // IVA aunque mañana se reclasifique el servicio: su XML ya se
        // transmitió.
        $factura = $this->facturaCon(TaxClassification::Excluido, tarifa: 0);

        Service::query()->update(['tax_classification' => TaxClassification::Gravado->value]);

        $this->assertSame(
            TaxClassification::Excluido->value,
            $factura->fresh()->invoice_items->first()->tax_classification,
        );
    }

    public function test_manda_la_clasificacion_y_no_la_tarifa(): void
    {
        // Un excluido con tarifa mal puesta sigue saliendo sin bloque de
        // impuestos: lo que decide es la clasificación.
        $xml = $this->xmlDeFacturaCon(TaxClassification::Excluido, tarifa: 0);

        $this->assertStringNotContainsString('<cac:TaxTotal>', $xml);
    }

    // ==================== El aviso por estrato ====================

    public function test_avisa_si_un_estrato_bajo_paga_iva(): void
    {
        // El internet residencial de estratos 1, 2 y 3 está excluido.
        $contrato = $this->contratoCon(estrato: '2', clasificacion: TaxClassification::Gravado, tarifa: 19);

        $avisos = app(TaxClassificationAdvisor::class)->avisos($contrato);

        $this->assertNotEmpty($avisos);
        $this->assertStringContainsString('excluido de IVA', $avisos[0]);
    }

    public function test_avisa_si_un_estrato_alto_va_como_excluido(): void
    {
        $contrato = $this->contratoCon(estrato: '5', clasificacion: TaxClassification::Excluido, tarifa: 0);

        $avisos = app(TaxClassificationAdvisor::class)->avisos($contrato);

        $this->assertNotEmpty($avisos);
        $this->assertStringContainsString('tarifa general', $avisos[0]);
    }

    public function test_no_avisa_cuando_cuadra(): void
    {
        $bajo = $this->contratoCon(estrato: '1', clasificacion: TaxClassification::Excluido, tarifa: 0);
        $alto = $this->contratoCon(estrato: '6', clasificacion: TaxClassification::Gravado, tarifa: 19);

        $this->assertSame([], app(TaxClassificationAdvisor::class)->avisos($bajo));
        $this->assertSame([], app(TaxClassificationAdvisor::class)->avisos($alto));
    }

    public function test_el_aviso_no_bloquea_nada(): void
    {
        // Puede haber razones legítimas: un contrato empresarial en una
        // dirección de estrato bajo. Por eso avisa y ya.
        $contrato = $this->contratoCon(estrato: '2', clasificacion: TaxClassification::Gravado, tarifa: 19);

        $resultado = app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        $this->assertTrue($resultado['generated'], 'El aviso no debe impedir facturar.');
    }

    public function test_sin_estrato_no_hay_nada_que_avisar(): void
    {
        $contrato = $this->contratoCon(estrato: '3', clasificacion: TaxClassification::Gravado, tarifa: 19);
        $contrato->update(['social_stratum' => null]);

        $this->assertSame([], app(TaxClassificationAdvisor::class)->avisos($contrato->fresh()));
    }

    // ==================== Apoyo ====================

    private function empresa(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->branch->company_id);
    }

    private function contratoCon(string $estrato, TaxClassification $clasificacion, float $tarifa): Contract
    {
        $grupo = AffinityGroup::factory()->electronico()->create(['company_id' => $this->branch->company_id]);

        $contrato = $this->createBillableContract(price: 50000, taxPercent: $tarifa);
        $contrato->update(['affinity_group_id' => $grupo->id, 'social_stratum' => $estrato]);

        $contrato->plan->services()->update(['tax_classification' => $clasificacion->value]);

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

    private function facturaCon(TaxClassification $clasificacion, float $tarifa): Invoice
    {
        $contrato = $this->contratoCon('1', $clasificacion, $tarifa);

        $resultado = app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id);

        $this->assertTrue($resultado['generated'], 'No se generó la factura.');

        return $resultado['invoice'];
    }

    private function xmlDeFacturaCon(TaxClassification $clasificacion, float $tarifa): string
    {
        $factura = $this->facturaCon($clasificacion, $tarifa);

        return \App\Models\ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail()
            ->signed_xml;
    }
}
