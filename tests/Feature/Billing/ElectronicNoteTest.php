<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\NoteXmlBuilder;
use App\Billing\Enums\NoteType;
use App\Billing\Services\ElectronicInvoicingDecider;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\NoteIssuer;
use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CreditDebitNote;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\FiscalCatalog;
use App\Models\Invoice;
use App\Models\NumberingRange;

/**
 * Notas crédito y débito electrónicas.
 *
 * QUÉ CIERRA
 * ----------
 * Una nota que corrige una factura electrónica tiene que ser también
 * electrónica: si no, se estaría ajustando ante la DIAN un documento
 * que ella validó, sin decírselo. Hasta ahora todas salían internas.
 *
 * LO QUE LA INVESTIGACIÓN CAMBIÓ DEL PLAN
 * ---------------------------------------
 * La decisión D6 daba por hecho que una nota electrónica tendría que
 * numerarse de un rango autorizado. **Es falso**, y se comprobó de dos
 * formas: el XML de una nota no lleva `sts:InvoiceControl` —se ve en
 * los ejemplos oficiales— y el anexo (§12.1) dice que para estos
 * documentos vale «la numeración establecida por el facturador».
 *
 * Así que la numeración propia que ya hacía `NoteIssuer` era la
 * correcta. Lo que faltaba era el CUDE, el XML y su transmisión.
 */
class ElectronicNoteTest extends BillingTestCase
{
    private NumberingRange $rango;

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

    // ==================== La decisión ====================

    public function test_una_nota_sobre_factura_electronica_es_electronica(): void
    {
        // Ajustar ante la DIAN un documento que ella validó, sin
        // decírselo, no es una opción.
        $nota = $this->emitirNota($this->facturaElectronica());

        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $nota->document_kind);
    }

    public function test_una_nota_sobre_factura_interna_es_interna(): void
    {
        $nota = $this->emitirNota($this->facturaInterna());

        $this->assertSame(ElectronicInvoicingDecider::INTERNO, $nota->document_kind);
        $this->assertSame(
            0,
            ElectronicDocument::withoutGlobalScopes()->where('credit_debit_note_id', $nota->id)->count(),
        );
    }

    public function test_el_tipo_de_la_nota_se_congela(): void
    {
        // Se lee de la factura, cuyo tipo ya estaba congelado. Apagar
        // después la facturación electrónica no cambia lo emitido.
        $factura = $this->facturaElectronica();
        $nota = $this->emitirNota($factura);

        $this->empresa()->update(['electronic_invoicing_enabled' => false]);

        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $nota->fresh()->document_kind);
    }

    // ==================== La numeración ====================

    public function test_la_nota_no_gasta_consecutivos_del_rango_autorizado(): void
    {
        // ES EL HALLAZGO QUE CAMBIÓ EL PLAN. Una nota no sale de un
        // rango autorizado: su XML no lleva InvoiceControl y el anexo
        // dice que vale «la numeración establecida por el facturador».
        $factura = $this->facturaElectronica();
        $gastadoAntes = $this->rango->fresh()->current_number;

        $nota = $this->emitirNota($factura);

        $this->assertSame($gastadoAntes, $this->rango->fresh()->current_number);

        // Sin guion: la DIAN marca el numero que lo lleva (CAD05a).
        $this->assertStringStartsWith('NC', $nota->full_number);
        $this->assertStringNotContainsString('-', $nota->full_number);
    }

    // ==================== El documento ====================

    public function test_emitir_una_nota_electronica_crea_su_documento(): void
    {
        $nota = $this->emitirNota($this->facturaElectronica());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)
            ->first();

        $this->assertNotNull($documento, 'No se creó el documento electrónico de la nota.');
        $this->assertSame(ElectronicDocument::GENERADO, $documento->status);
        // El CUDE es un SHA-384, igual que el CUFE.
        $this->assertSame(96, strlen($documento->cufe));
        $this->assertNull($documento->last_error);
    }

    public function test_el_xml_de_la_nota_credito_valida_contra_su_esquema(): void
    {
        // Es la prueba central, igual que en la factura: en UBL el orden
        // de los elementos es parte del contrato.
        $nota = $this->emitirNota($this->facturaElectronica());

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail();

        $this->assertSame(
            [],
            app(NoteXmlBuilder::class)->erroresDeEsquema($documento->signed_xml, esCredito: true),
        );
    }

    public function test_el_xml_de_la_nota_debito_valida_contra_su_esquema(): void
    {
        // La débito usa RequestedMonetaryTotal y DebitNoteLine: si se
        // usara el de la crédito, el documento no valida.
        $nota = $this->emitirNota($this->facturaElectronica(), NoteType::Debito);

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail();

        $this->assertSame(
            [],
            app(NoteXmlBuilder::class)->erroresDeEsquema($documento->signed_xml, esCredito: false),
        );

        $this->assertStringContainsString('<cac:RequestedMonetaryTotal>', $documento->signed_xml);
        $this->assertStringContainsString('<cac:DebitNoteLine>', $documento->signed_xml);
    }

    public function test_la_nota_apunta_al_cufe_de_su_factura(): void
    {
        // Es lo que ata la nota a su documento. Sin el CUFE la nota
        // queda huérfana y la DIAN la rechaza.
        $factura = $this->facturaElectronica();

        $documentoFactura = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $nota = $this->emitirNota($factura);

        $xml = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail()->signed_xml;

        $this->assertStringContainsString($documentoFactura->cufe, $xml);
        $this->assertStringContainsString('<cbc:ID>' . $factura->full_number . '</cbc:ID>', $xml);
        $this->assertStringContainsString('schemeName="CUFE-SHA384"', $xml);
    }

    public function test_el_xml_no_lleva_bloque_de_numeracion_autorizada(): void
    {
        // Ponerselo seria declarar una autorizacion de numeracion que a
        // la nota no le corresponde.
        $nota = $this->emitirNota($this->facturaElectronica());

        $xml = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail()->signed_xml;

        $this->assertStringNotContainsString('InvoiceControl', $xml);
        $this->assertStringContainsString('CUDE-SHA384', $xml);
    }

    public function test_cad02_la_nota_credito_se_personaliza_como_20(): void
    {
        // Aquí decía 11, y esta prueba lo daba por bueno. La DIAN
        // rechazó la nota por partida doble: CAD02 «CustomizationID no
        // indica un valor válido para el tipo de operación» y CAD02a
        // «CustomizationID debe ser igual a 20».
        //
        // 20 es «nota crédito que referencia una factura electrónica»,
        // que es la única clase que emite este sistema.
        $nota = $this->emitirNota($this->facturaElectronica());

        $xml = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail()->signed_xml;

        $this->assertStringContainsString('<cbc:CustomizationID>20</cbc:CustomizationID>', $xml);
        $this->assertStringContainsString('DIAN 2.1: Nota Crédito de Factura Electrónica de Venta', $xml);
    }

    public function test_cad02_la_nota_debito_se_personaliza_como_30(): void
    {
        $nota = $this->emitirNota($this->facturaElectronica(), NoteType::Debito);

        $xml = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail()->signed_xml;

        $this->assertStringContainsString('<cbc:CustomizationID>30</cbc:CustomizationID>', $xml);
        $this->assertStringContainsString('DIAN 2.1: Nota Débito de Factura Electrónica de Venta', $xml);
    }

    public function test_can01_la_nota_lleva_la_forma_de_pago(): void
    {
        // La DIAN rechazó una nota con «CAN01: Rechazo si grupo no
        // informado», sin decir qué grupo. Se supo comparando con la
        // factura, que sí emite `cac:PaymentMeans` y no recibió esa
        // queja.
        //
        // El XSD no lo exige —la nota validaba contra el esquema sin
        // esto—, así que lo único que lo protege es esta prueba.
        $nota = $this->emitirNota($this->facturaElectronica());

        $xml = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail()->signed_xml;

        $this->assertStringContainsString('<cac:PaymentMeans>', $xml);
        $this->assertStringContainsString('<cbc:PaymentMeansCode>', $xml);
        $this->assertSame([], app(NoteXmlBuilder::class)->erroresDeEsquema($xml, esCredito: true));
    }

    public function test_el_concepto_de_la_dian_va_en_el_xml(): void
    {
        // El ResponseCode es el codigo oficial del motivo: es lo que le
        // dice a la DIAN por que se ajusta.
        $nota = $this->emitirNota($this->facturaElectronica());

        $xml = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail()->signed_xml;

        $this->assertStringContainsString('<cbc:ResponseCode>' . $nota->concept_code . '</cbc:ResponseCode>', $xml);
    }

    // ==================== Cuando algo falla ====================

    public function test_un_fallo_no_impide_emitir_la_nota(): void
    {
        // La nota ya ajustó el saldo de la factura: que no se pueda
        // armar su XML no puede deshacer eso.
        $factura = $this->facturaElectronica();

        $factura->contract->client->update(['municipality_dane_code' => null]);

        $nota = $this->emitirNota($factura);

        $this->assertNotNull($nota->full_number);
        $this->assertSame(CreditDebitNote::EMITIDA, $nota->status);
    }

    public function test_sin_documento_de_la_factura_se_anota_el_motivo(): void
    {
        // Sin el CUFE de la factura no hay a qué apuntar.
        $factura = $this->facturaElectronica();

        ElectronicDocument::withoutGlobalScopes()->where('invoice_id', $factura->id)->delete();

        $nota = $this->emitirNota($factura);

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('credit_debit_note_id', $nota->id)->firstOrFail();

        $this->assertSame(ElectronicDocument::BORRADOR, $documento->status);
        $this->assertStringContainsString('CUFE', $documento->last_error);
    }

    // ==================== Apoyo ====================

    private function empresa(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->branch->company_id);
    }

    private function facturaElectronica(): Invoice
    {
        return $this->emitirFactura(electronico: true);
    }

    private function facturaInterna(): Invoice
    {
        return $this->emitirFactura(electronico: false);
    }

    private function emitirFactura(bool $electronico): Invoice
    {
        $grupo = AffinityGroup::factory()
            ->when($electronico, fn ($f) => $f->electronico())
            ->create(['company_id' => $this->branch->company_id]);

        $contrato = $this->createBillableContract(price: 100000, taxPercent: 19);
        $contrato->update(['affinity_group_id' => $grupo->id]);

        $contrato->client->update([
            'document_type_code' => '13',
            'organization_type_code' => '2',
            'fiscal_address' => 'Carrera 70 # 30-20',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'cliente@ejemplo.com',
        ]);

        $resultado = app(InvoiceGenerator::class)->generateForContract($contrato->fresh(), now(), $this->admin->id);

        $this->assertTrue($resultado['generated'], 'No se generó la factura.');

        return $resultado['invoice'];
    }

    private function emitirNota(Invoice $factura, NoteType $tipo = NoteType::Credito): CreditDebitNote
    {
        return app(NoteIssuer::class)->emitir($factura, [
            'type' => $tipo->value,
            'concept_code' => $tipo === NoteType::Credito ? '2' : '1',
            'reason' => 'Corrección por prueba automatizada del sistema',
            'subtotal' => 10000,
            'tax' => 1900,
        ]);
    }
}
