<?php

namespace Tests\Feature\Billing;

use App\Models\NumberingRange;
use App\Models\DianResolution;
use App\Billing\Services\ElectronicInvoicingDecider;
use App\Billing\Services\InvoiceGenerator;
use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\InvoiceNumberingSequence;

/**
 * Por qué camino sale cada factura.
 *
 * QUÉ DECIDE ESTA FASE
 * --------------------
 * Si un documento es una **factura electrónica** o un **documento
 * interno**. Todavía no hay ninguna conexión con la DIAN: lo que esta
 * fase deja montado es que los dos caminos existan, se decidan en un
 * único sitio y no se pisen.
 *
 * LAS TRES COSAS QUE SE DEFIENDEN
 * -------------------------------
 * 1. **La decisión vive en un solo sitio.** En cuanto esa condición se
 *    escriba dos veces, un día dirán cosas distintas — y ese día un
 *    documento sale por el camino equivocado.
 *
 * 2. **Se congela al emitir.** Cambiar el grupo de un contrato NO
 *    puede alterar las facturas ya emitidas. Una factura emitida no
 *    cambia de naturaleza porque alguien edite otra cosa después.
 *
 * 3. **Las dos series no comparten consecutivos.** Un rango autorizado
 *    que se gasta con documentos que nunca se reportan deja huecos que
 *    hay que justificar.
 */
class ElectronicInvoicingDecisionTest extends BillingTestCase
{
    private ElectronicInvoicingDecider $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decider = app(ElectronicInvoicingDecider::class);
    }

    private function empresa(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->branch->company_id);
    }

    /**
     * Deja la empresa lista para emitir electronicamente.
     *
     * Desde la fase 10 hacen falta TRES cosas, no dos: el interruptor
     * de la empresa, el grupo del contrato, y la configuracion DIAN en
     * produccion CON la habilitacion aprobada.
     *
     * Ese ultimo matiz importa: no basta con cambiar el ambiente. Sin
     * `enabled_at` no se ha pasado el set de pruebas, y emitir asi es
     * emitir documentos que van a ser rechazados.
     */
    private function habilitarEmpresa(): void
    {
        $this->empresa()->update(['electronic_invoicing_enabled' => true]);

        \App\Models\DianConfiguration::updateOrCreate(
            ['company_id' => $this->branch->company_id],
            [
                'environment_code' => \App\Models\DianConfiguration::PRODUCCION,
                'enabled_at' => now(),
            ],
        );
    }

    private function grupo(bool $electronico): AffinityGroup
    {
        return AffinityGroup::factory()
            ->when($electronico, fn ($f) => $f->electronico())
            ->create(['company_id' => $this->branch->company_id]);
    }

    /**
     * Emite la factura del mes de un contrato.
     *
     * `generateForContract` pide la fecha y el usuario y devuelve un
     * array con el resultado, no la factura: se envuelve aqui para que
     * las pruebas digan lo que quieren decir.
     */
    private function emitir(Contract $contrato, ?string $cuando = null): \App\Models\Invoice
    {
        $resultado = app(InvoiceGenerator::class)->generateForContract(
            $contrato,
            $cuando ? \Illuminate\Support\Carbon::parse($cuando) : now(),
            $this->admin->id,
        );

        $this->assertTrue(
            $resultado['generated'],
            'No se genero la factura: ' . ($resultado['reason'] ?? 'sin motivo'),
        );

        return $resultado['invoice'];
    }

    /**
     * Registra una resolucion de la DIAN con su rango autorizado.
     *
     * Desde la fase 10 una factura electronica solo puede numerarse de
     * un rango autorizado: no se inventa una serie. Sin esto, emitir
     * electronicamente falla — y esa es justamente una de las cosas
     * que se comprueban aqui.
     */
    private function autorizarRango(string $prefijo = 'SETP', int $desde = 990000000, int $hasta = 995000000): NumberingRange
    {
        $resolucion = DianResolution::withoutGlobalScopes()->create([
            'company_id' => $this->branch->company_id,
            'resolution_number' => '18760000001',
            'document_type_code' => DianResolution::FACTURA,
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addYear(),
            'technical_key' => 'clave-tecnica-de-prueba',
        ]);

        return NumberingRange::withoutGlobalScopes()->create([
            'dian_resolution_id' => $resolucion->id,
            'branch_id' => $this->branch->id,
            'prefix' => $prefijo,
            'range_start' => $desde,
            'range_end' => $hasta,
            'current_number' => 0,
        ]);
    }

    private function contratoEn(AffinityGroup $grupo): Contract
    {
        $contrato = $this->createBillableContract();
        $contrato->update(['affinity_group_id' => $grupo->id]);

        return $contrato->fresh();
    }

    // ==================== La decisión ====================

    public function test_hacen_falta_las_tres_condiciones(): void
    {
        $electronico = $this->grupo(electronico: true);
        $contrato = $this->contratoEn($electronico);

        // 1. La empresa nace con la facturación electrónica apagada:
        //    es el interruptor que impide emitir electrónicamente por
        //    accidente antes de estar habilitado.
        $this->assertFalse($this->decider->esElectronico($contrato));

        $this->empresa()->update(['electronic_invoicing_enabled' => true]);

        // 2. Con el interruptor y el grupo ya no basta: falta la
        //    configuración DIAN.
        $this->assertFalse($this->decider->esElectronico($contrato->fresh()));

        \App\Models\DianConfiguration::create([
            'company_id' => $this->branch->company_id,
            'environment_code' => \App\Models\DianConfiguration::PRUEBAS,
        ]);

        // 3. En pruebas tampoco: emitir en pruebas no es emitir.
        $this->assertFalse($this->decider->esElectronico($contrato->fresh()));

        $this->empresa()->dianConfiguration->update([
            'environment_code' => \App\Models\DianConfiguration::PRODUCCION,
        ]);

        // 4. En producción PERO sin habilitación aprobada, tampoco:
        //    sin pasar el set de pruebas, lo que se emita lo rechazan.
        $this->assertFalse($this->decider->esElectronico($contrato->fresh()));

        $this->empresa()->dianConfiguration->update(['enabled_at' => now()]);

        $this->assertTrue($this->decider->esElectronico($contrato->fresh()));
    }

    public function test_el_ambiente_de_pruebas_no_emite_electronicamente(): void
    {
        // Es la trampa más fácil de caer: cambiar el ambiente sin haber
        // pasado la habilitación y creer que ya se está facturando.
        $contrato = $this->contratoEn($this->grupo(electronico: true));

        $this->habilitarEmpresa();
        $this->empresa()->dianConfiguration->update([
            'environment_code' => \App\Models\DianConfiguration::PRUEBAS,
        ]);

        $this->assertFalse($this->decider->esElectronico($contrato->fresh()));
        $this->assertStringContainsString(
            'ambiente de pruebas',
            $this->decider->motivoInterno($contrato->fresh()),
        );
    }

    public function test_con_la_empresa_encendida_pero_grupo_interno_no_lo_es(): void
    {
        $this->habilitarEmpresa();

        $contrato = $this->contratoEn($this->grupo(electronico: false));

        $this->assertFalse($this->decider->esElectronico($contrato));
        $this->assertSame(ElectronicInvoicingDecider::INTERNO, $this->decider->tipoPara($contrato));
    }

    public function test_un_contrato_sin_grupo_nunca_es_electronico(): void
    {
        $this->habilitarEmpresa();

        $contrato = $this->createBillableContract();

        $this->assertFalse($this->decider->esElectronico($contrato));
    }

    public function test_dice_por_que_no_es_electronico(): void
    {
        // «No salió electrónica» sin decir por qué obliga a revisar
        // tres sitios distintos.
        $contrato = $this->contratoEn($this->grupo(electronico: true));

        $this->assertStringContainsString(
            'no tiene activada la facturación electrónica',
            $this->decider->motivoInterno($contrato),
        );

        // Con el interruptor puesto pero sin configuración, el motivo
        // cambia: dice lo siguiente que falta, no «no se sabe».
        $this->empresa()->update(['electronic_invoicing_enabled' => true]);

        $this->assertStringContainsString(
            'no tiene configuración',
            $this->decider->motivoInterno($contrato->fresh()),
        );

        $this->habilitarEmpresa();

        $this->assertNull($this->decider->motivoInterno($contrato->fresh()));
    }

    public function test_dice_que_el_contrato_no_tiene_grupo(): void
    {
        $this->habilitarEmpresa();

        $this->assertStringContainsString(
            'no tiene grupo de afinidad',
            $this->decider->motivoInterno($this->createBillableContract()),
        );
    }

    // ==================== Se congela al emitir ====================

    public function test_la_factura_guarda_el_grupo_y_el_tipo(): void
    {
        $this->habilitarEmpresa();
        $this->autorizarRango();

        $grupo = $this->grupo(electronico: true);
        $contrato = $this->contratoEn($grupo);

        $factura = $this->emitir($contrato);

        $this->assertSame($grupo->id, (int) $factura->affinity_group_id);
        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $factura->document_kind);
    }

    public function test_cambiar_el_grupo_del_contrato_no_toca_las_facturas_emitidas(): void
    {
        // Es la decisión D7 del plan, y la razón de que el grupo se
        // copie a la factura en vez de leerse del contrato al vuelo.
        $this->habilitarEmpresa();
        $this->autorizarRango();

        $electronico = $this->grupo(electronico: true);
        $interno = $this->grupo(electronico: false);

        $contrato = $this->contratoEn($electronico);
        $factura = $this->emitir($contrato);

        $contrato->update(['affinity_group_id' => $interno->id]);

        $factura->refresh();

        $this->assertSame($electronico->id, (int) $factura->affinity_group_id);
        $this->assertSame(ElectronicInvoicingDecider::ELECTRONICO, $factura->document_kind);
    }

    public function test_una_factura_emitida_se_consulta_por_su_columna(): void
    {
        // No se vuelve a decidir: si se recalculara al vuelo, la misma
        // factura respondería cosas distintas según cuándo se pregunte.
        $contrato = $this->contratoEn($this->grupo(electronico: true));
        $factura = $this->emitir($contrato);

        $this->assertFalse($this->decider->facturaEsElectronica($factura));

        // Se enciende la empresa DESPUÉS de emitir: la factura ya
        // emitida sigue siendo lo que era.
        $this->habilitarEmpresa();

        $this->assertFalse($this->decider->facturaEsElectronica($factura->fresh()));
    }

    // ==================== Las dos series no se mezclan ====================

    public function test_cada_tipo_sale_de_un_sitio_distinto(): void
    {
        // Es la decisión D5, y desde la fase 10 no es solo «series
        // distintas»: son TABLAS distintas. La interna sale de su
        // propia secuencia; la electrónica, de un rango que autorizó la
        // DIAN. Así es imposible que un documento interno gaste un
        // consecutivo autorizado.
        $this->habilitarEmpresa();
        $rango = $this->autorizarRango();

        $facturaInterna = $this->emitir($this->contratoEn($this->grupo(electronico: false)));
        $facturaElectronica = $this->emitir($this->contratoEn($this->grupo(electronico: true)));

        // La interna apunta a su secuencia; la electrónica, a ninguna:
        // de qué rango salió queda en su documento electrónico.
        $this->assertNotNull($facturaInterna->numbering_sequence_id);
        $this->assertNull($facturaElectronica->numbering_sequence_id);

        // La interna arranca en 1; la electrónica, en el primer número
        // del rango AUTORIZADO — no en 1.
        $this->assertSame(1, (int) $facturaInterna->number);
        $this->assertSame($rango->range_start, (int) $facturaElectronica->number);
    }

    public function test_sin_rango_autorizado_no_se_emite_electronicamente(): void
    {
        // Un rango no se inventa: lo autoriza la DIAN. Emitir con
        // números de fuera del rango es emitir con números que nadie
        // autorizó, que es peor que no emitir.
        //
        // Falla en voz alta y con el motivo, para que se vea al primer
        // intento y no el día que la DIAN rechace el lote.
        $this->habilitarEmpresa();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/rango de numeracion autorizado/');

        $this->emitir($this->contratoEn($this->grupo(electronico: true)));
    }

    public function test_un_rango_de_resolucion_vencida_no_vale(): void
    {
        // Una resolución fuera de vigencia no autoriza nada, por muchos
        // consecutivos que le queden.
        $this->habilitarEmpresa();

        $rango = $this->autorizarRango();
        $rango->resolution->update(['valid_until' => now()->subDay()]);

        $this->expectException(\RuntimeException::class);

        $this->emitir($this->contratoEn($this->grupo(electronico: true)));
    }

    public function test_los_prefijos_distinguen_los_dos_documentos(): void
    {
        // Un documento que no es una factura electrónica no debe
        // parecerlo, y eso empieza por su número. El de la electrónica
        // ya no se lo inventa el sistema: es el que autorizó la
        // resolución.
        $this->habilitarEmpresa();
        $this->autorizarRango('SETP');

        $interna = $this->emitir($this->contratoEn($this->grupo(electronico: false)));
        $electronica = $this->emitir($this->contratoEn($this->grupo(electronico: true)));

        $this->assertStringStartsWith('FAC', $interna->prefix);
        $this->assertSame('SETP', $electronica->prefix);
    }

    public function test_emitir_internas_no_gasta_consecutivos_autorizados(): void
    {
        // Un rango autorizado que se agota con documentos que nunca se
        // reportan deja huecos que hay que justificar ante la DIAN.
        $this->habilitarEmpresa();
        $rango = $this->autorizarRango();

        $interno = $this->grupo(electronico: false);

        for ($i = 0; $i < 5; $i++) {
            $this->emitir($this->contratoEn($interno));
        }

        // Cinco documentos internos después, el rango autorizado sigue
        // intacto.
        $this->assertSame(0, (int) $rango->fresh()->current_number);

        $primeraElectronica = $this->emitir($this->contratoEn($this->grupo(electronico: true)));

        $this->assertSame($rango->range_start, (int) $primeraElectronica->number);
        $this->assertSame($rango->range_start, (int) $rango->fresh()->current_number);
    }

    public function test_no_se_puede_emitir_pasado_el_rango(): void
    {
        // Es la comprobación que viene de la fase 6, ahora sobre la
        // serie que de verdad importa: pasado el rango son números que
        // nadie autorizó.
        $this->habilitarEmpresa();
        $this->autorizarRango('SETP', desde: 1, hasta: 2);

        $electronico = $this->grupo(electronico: true);

        $this->emitir($this->contratoEn($electronico));
        $this->emitir($this->contratoEn($electronico));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/agotó su rango/');

        $this->emitir($this->contratoEn($electronico));
    }

    public function test_las_series_que_ya_existian_son_internas(): void
    {
        // No hay ninguna resolución registrada, así que todo lo emitido
        // hasta ahora es documento interno. Nada cambia de
        // comportamiento por la migración.
        $this->emitir($this->contratoEn($this->grupo(electronico: false)));

        $series = InvoiceNumberingSequence::withoutGlobalScopes()->get();

        $this->assertNotEmpty($series);

        foreach ($series as $serie) {
            $this->assertSame(ElectronicInvoicingDecider::INTERNO, $serie->kind);
        }
    }
}
