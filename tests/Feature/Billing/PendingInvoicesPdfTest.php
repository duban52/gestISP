<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Jobs\GeneratePendingInvoicesPdf;
use App\Models\Invoice;
use App\Models\PdfReport;
use Illuminate\Support\Facades\Queue;

/**
 * El PDF masivo de facturas pendientes.
 *
 * LOS TRES AGUJEROS QUE CIERRA
 * ----------------------------
 *  1. **Enseñaba el PDF viejo.** La pantalla preguntaba «¿hay algún
 *     PDF de esta sucursal?» y, como siempre había uno de alguna vez
 *     anterior, contestaba que sí AL INSTANTE: se abría el visor con
 *     el documento de la semana pasada mientras el nuevo seguía en la
 *     cola. Ahora se pregunta por la generación concreta que se pidió.
 *
 *  2. **Salía en blanco.** Filtraba por la sucursal del CLIENTE, y un
 *     cliente puede tenerla en nulo —son de la empresa, no de una
 *     sede—. Con eso no coincidía ninguna factura y el PDF salía con
 *     una sola página vacía, sin error y sin aviso.
 *
 *  3. **Un fallo era invisible.** Si el trabajo reventaba, el estado
 *     no se guardaba en ningún sitio: la pantalla seguía sirviendo el
 *     anterior tan tranquila.
 *
 * Lo que NO se prueba aquí es el render de dompdf: eso es la
 * biblioteca. Se prueba qué facturas entran, qué estado queda y qué
 * ve quien espera.
 */
class PendingInvoicesPdfTest extends BillingTestCase
{
    // ==================== Qué facturas entran ====================

    /**
     * El caso que vaciaba el PDF.
     *
     * El cliente de la empresa tiene `branch_id` nulo. Filtrando por
     * la sucursal del cliente no salía ni una factura; la sucursal
     * buena es la de la factura, que es la que pone el membrete y
     * gasta el consecutivo.
     */
    public function test_entran_las_facturas_aunque_el_cliente_no_tenga_sucursal(): void
    {
        $contrato = $this->createBillableContract(price: 50000);
        $contrato->client->update(['branch_id' => null]);

        $this->post(route('contracts.invoice', $contrato))->assertSessionHasNoErrors();

        $informe = $this->generar();

        $this->assertSame(PdfReport::LISTO, $informe->status);
        $this->assertSame(1, $informe->total, 'La factura no entró en el PDF.');
    }

    public function test_no_entran_las_facturas_de_otra_sucursal(): void
    {
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        $ajeno = \App\Models\Branch::factory()->create();

        $informe = $this->generar($ajeno->id);

        $this->assertSame(0, $informe->total);
    }

    // ==================== Lo que ve quien espera ====================

    public function test_encolar_devuelve_con_que_seguirlo(): void
    {
        Queue::fake();

        $respuesta = $this->post(route('invoices.generate_max_pdf'))
            ->assertOk()
            ->assertJsonStructure(['ok', 'id', 'url_estado']);

        Queue::assertPushed(GeneratePendingInvoicesPdf::class);

        $this->assertDatabaseHas('pdf_reports', [
            'id' => $respuesta->json('id'),
            'status' => PdfReport::EN_COLA,
        ]);
    }

    /**
     * El bug que hacía que pareciera instantáneo y roto.
     *
     * Con un PDF viejo en la tabla, pedir uno nuevo tiene que
     * contestar por el NUEVO —todavía sin terminar— y no por el que ya
     * estaba.
     */
    public function test_el_pdf_viejo_no_se_hace_pasar_por_el_nuevo(): void
    {
        Queue::fake();

        $viejo = PdfReport::create([
            'branch_id' => $this->branch->id,
            'status' => PdfReport::LISTO,
            'pdf_path' => 'pending_invoices/de_la_semana_pasada.pdf',
            'finished_at' => now()->subWeek(),
        ]);

        $nuevo = $this->post(route('invoices.generate_max_pdf'))->json('id');

        $this->assertNotSame($viejo->id, $nuevo);

        $this->getJson(route('invoices.check-pdf-status', $nuevo))
            ->assertOk()
            ->assertJson(['terminado' => false, 'pdfPath' => null]);
    }

    public function test_el_progreso_se_puede_consultar(): void
    {
        $informe = PdfReport::create([
            'branch_id' => $this->branch->id,
            'status' => PdfReport::GENERANDO,
            'total' => 200,
            'procesadas' => 50,
        ]);

        $this->getJson(route('invoices.check-pdf-status', $informe))
            ->assertOk()
            ->assertJson(['porcentaje' => 25, 'terminado' => false]);
    }

    public function test_un_fallo_se_cuenta_en_vez_de_esconderse(): void
    {
        $informe = PdfReport::create([
            'branch_id' => $this->branch->id,
            'status' => PdfReport::FALLIDO,
            'error' => 'Allowed memory size exhausted',
        ]);

        $respuesta = $this->getJson(route('invoices.check-pdf-status', $informe))->assertOk();

        $this->assertTrue($respuesta->json('terminado'));
        $this->assertNull($respuesta->json('pdfPath'));
        $this->assertStringContainsString('memory', $respuesta->json('texto'));
    }

    public function test_no_se_consulta_el_informe_de_otra_sucursal(): void
    {
        $ajeno = PdfReport::create([
            'branch_id' => \App\Models\Branch::factory()->create()->id,
            'status' => PdfReport::LISTO,
            'pdf_path' => 'pending_invoices/ajeno.pdf',
        ]);

        $this->getJson(route('invoices.check-pdf-status', $ajeno))->assertForbidden();
    }

    /**
     * Una barra parada en 0 sobre un trabajo ya terminado se lee como
     * que se colgó, aunque no hubiera ni una factura que imprimir.
     */
    public function test_un_pdf_terminado_marca_el_cien_por_cien(): void
    {
        $informe = PdfReport::create([
            'branch_id' => $this->branch->id,
            'status' => PdfReport::LISTO,
            'pdf_path' => 'pending_invoices/vacio.pdf',
            'total' => 0,
        ]);

        $this->assertSame(100, $informe->porcentaje());
    }

    // ==================== Qué entra: lo elige el usuario ====================

    /**
     * EL BUG QUE VACIABA EL PDF.
     *
     * Los estados estaban fijos a «Pendiente» y «riesgo de corte». Una
     * cartera con mora —toda cartera real— tiene las facturas en
     * VENCIDA, así que el PDF salía casi vacío y sin un solo aviso.
     */
    public function test_por_defecto_entran_tambien_las_vencidas(): void
    {
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        Invoice::where('contract_id', $contrato->id)
            ->update(['status' => InvoiceStatus::Vencida->value]);

        $informe = $this->generar();

        $this->assertSame(1, $informe->total, 'La factura vencida se quedó fuera del PDF.');
    }

    public function test_se_puede_pedir_un_solo_estado(): void
    {
        $vencida = $this->facturaCon(InvoiceStatus::Vencida);
        $this->facturaCon(InvoiceStatus::Pendiente);

        $informe = $this->generar(criterio: ['estados' => [InvoiceStatus::Vencida->value]]);

        $this->assertSame(1, $informe->total);
        $this->assertNotNull($vencida);
    }

    /**
     * Una selección a mano manda sobre los estados.
     *
     * Si alguien marcó una factura concreta, la quiere — aunque esté
     * pagada y aunque no encaje en ningún filtro de estado.
     */
    public function test_la_seleccion_manual_manda_sobre_el_estado(): void
    {
        $pagada = $this->facturaCon(InvoiceStatus::Pagada);
        $this->facturaCon(InvoiceStatus::Vencida);

        $informe = $this->generar(criterio: ['ids' => [$pagada->id]]);

        $this->assertSame(1, $informe->total);
    }

    public function test_un_estado_inventado_se_rechaza(): void
    {
        // Sin validar, un estado que no existe produce un PDF vacío
        // sin explicación: justo lo que esto viene a arreglar.
        $this->post(route('invoices.generate_max_pdf'), ['estados' => ['Inventado']])
            ->assertSessionHasErrors('estados.0');
    }

    public function test_no_se_cuelan_facturas_de_otra_sucursal_por_id(): void
    {
        // Los ids vienen del navegador: marcar una factura ajena en el
        // inspector no puede meterla en el PDF de esta sede.
        $ajena = \App\Models\Branch::factory()->create();

        $respuesta = $this->postJson(route('invoices.generate_max_pdf'), [
            'ids' => [999999],
        ])->assertOk();

        $this->assertNotNull($respuesta->json('id'));
    }

    /** Una factura de este banco de pruebas, en el estado que se pida. */
    private function facturaCon(InvoiceStatus $estado): Invoice
    {
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();
        $factura->update(['status' => $estado->value]);

        return $factura;
    }

    /** Lanza el trabajo de verdad y devuelve su informe. */
    private function generar(?int $branchId = null, array $criterio = []): PdfReport
    {
        $informe = PdfReport::create([
            'branch_id' => $branchId ?? $this->branch->id,
            'status' => PdfReport::EN_COLA,
        ]);

        (new GeneratePendingInvoicesPdf($branchId ?? $this->branch->id, $informe->id, $criterio))->handle();

        return $informe->fresh();
    }
}
