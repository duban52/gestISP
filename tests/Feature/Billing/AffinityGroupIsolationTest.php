<?php

namespace Tests\Feature\Billing;

use App\Billing\Services\ElectronicInvoicingDecider;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\NoteIssuer;
use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\FiscalCatalog;
use App\Models\Invoice;
use App\Models\InvoiceNumberingSequence;
use App\Models\NumberingRange;
use Illuminate\Support\Carbon;

/**
 * Los dos caminos no se tocan: electrónico e interno.
 *
 * POR QUÉ ESTE ARCHIVO EXISTE APARTE
 * ----------------------------------
 * `ElectronicInvoicingDecisionTest` comprueba que la DECISIÓN sea la
 * correcta. Esto comprueba sus CONSECUENCIAS, que es otra cosa y es la
 * que cuesta dinero si falla:
 *
 *   · Que una factura electrónica tome de verdad lo que la DIAN exige
 *     —el rango autorizado, el documento, el CUFE—.
 *   · Que una interna **no toque nada de eso**. Ni documento, ni
 *     transmisión, ni —sobre todo— el consecutivo autorizado.
 *
 * EL CONSECUTIVO ES LO QUE NO SE RECUPERA
 * ---------------------------------------
 * Un consecutivo de un rango autorizado gastado en un documento que la
 * DIAN nunca va a ver deja un hueco en la numeración que hay que
 * justificar ante ella. No se puede devolver ni reutilizar. Por eso la
 * prueba que más importa aquí es la que compara `current_number` del
 * rango antes y después de emitir facturas internas.
 */
class AffinityGroupIsolationTest extends BillingTestCase
{
    private ElectronicInvoicingDecider $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decider = app(ElectronicInvoicingDecider::class);

        FiscalCatalog::insert([
            ['catalog' => FiscalCatalog::DEPARTAMENTO, 'code' => '05', 'name' => 'Antioquia',
             'parent_code' => null, 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ==================== El camino electrónico ====================

    public function test_el_grupo_electronico_toma_todo_lo_que_la_dian_exige(): void
    {
        $this->prepararEmpresa();
        $rango = $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: true));

        // 1. El tipo, congelado.
        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $factura->document_kind);

        // 2. El número sale DEL RANGO AUTORIZADO, no de una serie propia.
        $this->assertSame('SETP', $factura->prefix);
        // El primer numero autorizado ES `range_start`: el rango
        // 990000000-995000000 empieza en 990000000, no en el siguiente.
        $this->assertSame($rango->range_start, (int) $factura->number);
        $this->assertNull(
            $factura->numbering_sequence_id,
            'Una electrónica no debe apuntar a la serie interna.',
        );

        // 3. Existe su documento, con CUFE y con la resolución que lo autorizó.
        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->first();

        $this->assertNotNull($documento, 'No se creó el documento electrónico.');
        $this->assertSame(
            96,
            strlen((string) $documento->cufe),
            'El documento quedó sin CUFE. Motivo anotado: ' . ($documento->last_error ?? 'ninguno'),
        );
        $this->assertSame($rango->dian_resolution_id, $documento->dian_resolution_id);
        $this->assertNotEmpty($documento->qr_content);
    }

    public function test_el_rango_autorizado_avanza_solo_con_las_electronicas(): void
    {
        $this->prepararEmpresa();
        $rango = $this->autorizarRango();

        $this->emitir($this->contratoEn(electronico: true));
        $this->emitir($this->contratoEn(electronico: true));

        // `current_number` guarda el ULTIMO consecutivo usado, no
        // cuantos van: es lo que permite reanudar donde se quedo.
        $this->assertSame($rango->range_start + 1, $rango->fresh()->current_number);
    }

    // ==================== El camino interno ====================

    public function test_el_grupo_interno_no_gasta_consecutivo_autorizado(): void
    {
        // LA PRUEBA QUE MÁS IMPORTA DE TODO EL ARCHIVO.
        //
        // Un consecutivo autorizado gastado en un documento que la DIAN
        // nunca va a ver deja un hueco que hay que justificar ante ella,
        // y no se puede devolver.
        $this->prepararEmpresa();
        $rango = $this->autorizarRango();

        $antes = $rango->fresh()->current_number;

        $this->emitir($this->contratoEn(electronico: false));
        $this->emitir($this->contratoEn(electronico: false));
        $this->emitir($this->contratoEn(electronico: false));

        $this->assertSame(
            $antes,
            $rango->fresh()->current_number,
            'Tres facturas INTERNAS movieron el consecutivo autorizado de la DIAN.',
        );
    }

    public function test_el_grupo_interno_no_crea_documento_electronico(): void
    {
        $this->prepararEmpresa();
        $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: false));

        $this->assertSame(ElectronicInvoicingDecider::INTERNO, $factura->document_kind);
        $this->assertSame(
            0,
            ElectronicDocument::withoutGlobalScopes()->where('invoice_id', $factura->id)->count(),
        );
    }

    public function test_la_interna_numera_por_su_propia_serie(): void
    {
        $this->prepararEmpresa();
        $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: false));

        $this->assertNotNull(
            $factura->numbering_sequence_id,
            'Una interna tiene que salir de invoice_numbering_sequences.',
        );
        $this->assertNotSame('SETP', $factura->prefix);
        $this->assertSame(
            1,
            InvoiceNumberingSequence::whereKey($factura->numbering_sequence_id)->value('current_number'),
        );
    }

    public function test_las_dos_series_avanzan_por_separado(): void
    {
        // Conviven en la misma sucursal y el mismo mes. Que una empuje
        // a la otra sería exactamente el fallo que esto previene.
        $this->prepararEmpresa();
        $rango = $this->autorizarRango();

        $electronica = $this->emitir($this->contratoEn(electronico: true));
        $interna     = $this->emitir($this->contratoEn(electronico: false));
        $electronica2 = $this->emitir($this->contratoEn(electronico: true));

        $this->assertSame($rango->range_start, (int) $electronica->number);
        $this->assertSame($rango->range_start + 1, (int) $electronica2->number);
        $this->assertSame($rango->range_start + 1, $rango->fresh()->current_number);

        $this->assertSame(1, (int) $interna->number);
    }

    public function test_sin_rango_autorizado_la_interna_se_emite_igual(): void
    {
        // Una empresa que todavía no tiene resolución de la DIAN tiene
        // que poder seguir facturando internamente. Si no, encender la
        // facturación electrónica dejaría sin facturar a media empresa.
        $this->prepararEmpresa();

        $factura = $this->emitir($this->contratoEn(electronico: false));

        $this->assertSame(ElectronicInvoicingDecider::INTERNO, $factura->document_kind);
        $this->assertNotNull($factura->full_number);
    }

    public function test_sin_rango_autorizado_la_electronica_NO_se_emite(): void
    {
        // Lo contrario del anterior, y es a propósito: un número que no
        // salga de un rango autorizado es un número que nadie autorizó.
        // Falla en voz alta en vez de inventarlo.
        $this->prepararEmpresa();

        // LANZA, no devuelve false. Es deliberado y conviene tenerlo
        // fijado: la corrida mensual lo atrapa y cuenta la factura como
        // omitida, y el boton de factura individual lo convierte en un
        // mensaje. Si algun dia dejara de lanzar, esos dos sitios
        // dejarian de avisar sin que nadie se entere.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rango de numeracion autorizado');

        app(InvoiceGenerator::class)->generateForContract(
            $this->contratoEn(electronico: true),
            now(),
            $this->admin->id,
        );
    }

    // ============ Una factura vacía no se emite ============

    public function test_un_contrato_sin_plan_no_gasta_consecutivo(): void
    {
        // `contracts.plan_id` es nulo con ON DELETE SET NULL: basta con
        // que alguien borre un plan. La factura salía igual, con cero
        // renglones y total cero — y en electrónica eso gasta un
        // consecutivo autorizado en un XML que el XSD rechaza, porque
        // un `Invoice` sin `InvoiceLine` no es válido. Queda el hueco
        // en la numeración y ningún documento que enseñar.
        $this->prepararEmpresa();
        $rango = $this->autorizarRango();

        $contrato = $this->contratoEn(electronico: true);
        $contrato->update(['plan_id' => null]);

        $resultado = app(InvoiceGenerator::class)->generateForContract(
            $contrato->fresh(),
            now(),
            $this->admin->id,
        );

        $this->assertFalse($resultado['generated'], 'Se emitió una factura sin nada dentro.');
        $this->assertSame(0, Invoice::where('contract_id', $contrato->id)->count());
        $this->assertSame(
            0,
            $rango->fresh()->current_number,
            'Una factura vacía gastó un consecutivo autorizado.',
        );
    }

    public function test_lo_dice_en_pantalla_al_facturar_ese_contrato(): void
    {
        $this->prepararEmpresa();

        $contrato = $this->contratoEn(electronico: false);
        $contrato->update(['plan_id' => null]);

        $this->post(route('contracts.invoice', $contrato))
            ->assertSessionHas('error');

        $this->assertStringContainsString('se quedó sin plan', session('error'));
    }

    // ==================== Lo que se congela ====================

    public function test_cambiar_el_grupo_no_altera_lo_ya_emitido(): void
    {
        // Una factura no cambia de naturaleza después de emitida. Su
        // XML ya se transmitió.
        $this->prepararEmpresa();
        $this->autorizarRango();

        $contrato = $this->contratoEn(electronico: true);
        $factura = $this->emitir($contrato);

        $contrato->update(['affinity_group_id' => $this->grupo(electronico: false)->id]);

        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $factura->fresh()->document_kind);
        $this->assertTrue($this->decider->facturaEsElectronica($factura->fresh()));
    }

    public function test_apagar_el_interruptor_de_la_empresa_no_altera_lo_ya_emitido(): void
    {
        $this->prepararEmpresa();
        $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: true));

        $this->empresa()->update(['electronic_invoicing_enabled' => false]);

        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $factura->fresh()->document_kind);
    }

    public function test_el_interruptor_de_la_empresa_manda_sobre_el_grupo(): void
    {
        // El grupo pide electrónico, la empresa está apagada: gana la
        // empresa. Es el interruptor general.
        $this->prepararEmpresa();
        $this->autorizarRango();
        $this->empresa()->update(['electronic_invoicing_enabled' => false]);

        $factura = $this->emitir($this->contratoEn(electronico: true));

        $this->assertSame(ElectronicInvoicingDecider::INTERNO, $factura->document_kind);
    }

    // ==================== Las notas heredan ====================

    public function test_la_nota_de_una_factura_interna_es_interna(): void
    {
        $this->prepararEmpresa();
        $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: false));
        $nota = $this->emitirNota($factura);

        $this->assertSame(ElectronicInvoicingDecider::INTERNO, $nota->document_kind);
        $this->assertSame(
            0,
            ElectronicDocument::withoutGlobalScopes()->where('credit_debit_note_id', $nota->id)->count(),
        );
    }

    public function test_la_nota_de_una_factura_electronica_es_electronica(): void
    {
        $this->prepararEmpresa();
        $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: true));
        $nota = $this->emitirNota($factura);

        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $nota->document_kind);
    }

    public function test_una_nota_no_gasta_consecutivo_del_rango_de_facturas(): void
    {
        // Las notas llevan su propia numeración: no salen del rango
        // autorizado de facturas (anexo §12.1).
        $this->prepararEmpresa();
        $rango = $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: true));
        $antes = $rango->fresh()->current_number;

        $this->emitirNota($factura);

        $this->assertSame($antes, $rango->fresh()->current_number);
    }

    // ==================== Habilitación ====================

    public function test_en_pruebas_se_producen_documentos_para_el_set(): void
    {
        // Es el camino de la habilitación: `dian:set-de-pruebas` no
        // inventa documentos, manda los que ya existen. Si en pruebas
        // no se emitiera nada, no habría set que mandar y la DIAN no
        // habilitaría nunca.
        $this->prepararEmpresa(ambiente: DianConfiguration::PRUEBAS);
        $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: true));

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->first();

        $this->assertNotNull($documento, 'En pruebas no se produjo documento: no habría set que mandar.');
        $this->assertSame(DianConfiguration::PRUEBAS, $documento->environment_code);
    }

    public function test_el_ambiente_queda_congelado_en_el_documento(): void
    {
        // El QR apunta al catálogo de SU ambiente. Si mañana la empresa
        // pasa a producción, este documento tiene que seguir diciendo
        // dónde se emitió.
        $this->prepararEmpresa(ambiente: DianConfiguration::PRUEBAS);
        $this->autorizarRango();

        $factura = $this->emitir($this->contratoEn(electronico: true));

        $this->empresa()->dianConfiguration->update([
            'environment_code' => DianConfiguration::PRODUCCION,
            'enabled_at' => now(),
        ]);

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)->firstOrFail();

        $this->assertSame(DianConfiguration::PRUEBAS, $documento->fresh()->environment_code);
    }

    // ==================== Andamiaje ====================

    private function empresa(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->branch->company_id);
    }

    private function prepararEmpresa(string $ambiente = DianConfiguration::PRODUCCION): void
    {
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

        DianConfiguration::updateOrCreate(
            ['company_id' => $this->branch->company_id],
            [
                'environment_code' => $ambiente,
                'enabled_at' => $ambiente === DianConfiguration::PRODUCCION ? now() : null,
                'software_id' => 'fa326ca7-c1f8-40d3-a6fc-24d7c1040607',
                'software_pin' => '12345',
            ],
        );
    }

    private function autorizarRango(): NumberingRange
    {
        $resolucion = DianResolution::withoutGlobalScopes()->create([
            'company_id' => $this->branch->company_id,
            'resolution_number' => '18760000001',
            'document_type_code' => DianResolution::FACTURA,
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addYear(),
            'technical_key' => '693ff6f2a553c3646a063436fd4dd9ded0311471',
        ]);

        return NumberingRange::withoutGlobalScopes()->create([
            'dian_resolution_id' => $resolucion->id,
            'branch_id' => $this->branch->id,
            'prefix' => 'SETP',
            'range_start' => 990000000,
            'range_end' => 995000000,
            'current_number' => 0,
        ]);
    }

    private function grupo(bool $electronico): AffinityGroup
    {
        return AffinityGroup::factory()
            ->when($electronico, fn ($f) => $f->electronico())
            ->create(['company_id' => $this->branch->company_id]);
    }

    /**
     * Un contrato del grupo pedido, con el cliente completo.
     *
     * Los datos fiscales del CLIENTE se rellenan siempre, también en
     * los contratos internos. No hacen falta ahí, pero dejarlos a
     * medias haría que la diferencia entre los dos caminos pudiera
     * venir de un cliente incompleto en vez del grupo — y entonces la
     * prueba no demostraría lo que dice demostrar.
     */
    private function contratoEn(bool $electronico): Contract
    {
        $contrato = $this->createBillableContract();
        $contrato->update(['affinity_group_id' => $this->grupo($electronico)->id]);

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

    private function emitir(Contract $contrato, ?string $cuando = null): Invoice
    {
        $resultado = app(InvoiceGenerator::class)->generateForContract(
            $contrato,
            $cuando ? Carbon::parse($cuando) : now(),
            $this->admin->id,
        );

        $this->assertTrue(
            $resultado['generated'] ?? false,
            'No se generó la factura: ' . ($resultado['reason'] ?? 'sin motivo'),
        );

        return $resultado['invoice'];
    }

    private function emitirNota(Invoice $factura)
    {
        return app(NoteIssuer::class)->emitir($factura, [
            'type' => \App\Billing\Enums\NoteType::Credito->value,
            'concept_code' => '3',
            'reason' => 'Descuento acordado con el cliente.',
            'subtotal' => 1000,
            'tax' => 0,
        ]);
    }
}
