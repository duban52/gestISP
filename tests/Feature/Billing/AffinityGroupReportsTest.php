<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Models\AffinityGroup;
use App\Models\BillingRun;
use App\Models\Invoice;

/**
 * Filtrar y totalizar por grupo de afinidad.
 *
 * POR QUÉ IMPORTA
 * ---------------
 * El grupo es como el negocio segmenta sus contratos. Una corrida de
 * facturación sin desglosar por grupo obliga a sumar a mano desde el
 * listado para saber cuánto se le facturó a cada segmento.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que el reporte de corrida se pueda acotar a un grupo, y que el
 *    filtro llegue también a las descargas: si la pantalla enseña un
 *    grupo y el archivo trae toda la corrida, lo que se le entrega a
 *    contabilidad no es lo que se revisó.
 * 2. Que los totales por grupo SUMEN el total. Si un grupo se quedara
 *    fuera, el reporte se contradiría consigo mismo.
 * 3. Que la etiqueta «Por recaudar» del listado de facturas siga al
 *    filtro. Antes sumaba siempre toda la sucursal, así que al filtrar
 *    por grupo decía una cifra que no correspondía a la tabla de abajo.
 */
class AffinityGroupReportsTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Un contrato de un grupo con nombre, y su factura emitida. */
    private function facturaDeGrupo(string $nombreGrupo, float $precio): Invoice
    {
        $grupo = AffinityGroup::firstOrCreate(
            ['company_id' => $this->branch->company_id, 'name' => $nombreGrupo],
            ['code' => strtoupper(substr($nombreGrupo, 0, 8)), 'active' => true],
        );

        $contrato = $this->createBillableContract(price: $precio, taxPercent: 0);
        $contrato->update(['affinity_group_id' => $grupo->id]);

        return $this->emitir($contrato->fresh());
    }

    /** La corrida que agrupa las facturas ya emitidas del período. */
    private function corrida(): BillingRun
    {
        return BillingRun::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'billed_year_month' => now()->format('Ym'),
            'invoices_count' => Invoice::count(),
            'total_billed' => (float) Invoice::sum('total'),
            'executed_at' => now(),
        ]);
    }

    // ==================== El reporte de la corrida ====================

    public function test_el_reporte_totaliza_por_grupo(): void
    {
        $this->facturaDeGrupo('Residencial', 50000);
        $this->facturaDeGrupo('Residencial', 30000);
        $this->facturaDeGrupo('Corporativo', 200000);

        $respuesta = $this->get(route('billing_runs.show', $this->corrida()))->assertOk();

        $respuesta->assertSee('Totales por grupo de afinidad', false);
        $respuesta->assertSee('Residencial', false);
        $respuesta->assertSee('Corporativo', false);

        $porGrupo = collect($respuesta->viewData('porGrupo'));

        $this->assertEqualsWithDelta(80000, $porGrupo->firstWhere('grupo', 'Residencial')['total'], 0.01);
        $this->assertEqualsWithDelta(200000, $porGrupo->firstWhere('grupo', 'Corporativo')['total'], 0.01);
    }

    public function test_los_grupos_suman_exactamente_el_total(): void
    {
        // Si un grupo se quedara fuera —los contratos SIN grupo son el
        // caso evidente—, el reporte se contradiría consigo mismo.
        $this->facturaDeGrupo('Residencial', 50000);

        $sinGrupo = $this->createBillableContract(price: 90000, taxPercent: 0);
        $this->emitir($sinGrupo);

        $respuesta = $this->get(route('billing_runs.show', $this->corrida()))->assertOk();

        $porGrupo = collect($respuesta->viewData('porGrupo'));
        $resumen = $respuesta->viewData('resumen');

        $this->assertEqualsWithDelta($resumen['total'], $porGrupo->sum('total'), 0.01);
        $this->assertEqualsWithDelta($resumen['facturas'], $porGrupo->sum('facturas'), 0.01);

        // Y los que no tienen grupo aparecen nombrados, no desaparecidos.
        $this->assertNotNull($porGrupo->firstWhere('grupo', 'Sin grupo'));
    }

    public function test_el_reporte_se_puede_acotar_a_un_grupo(): void
    {
        $residencial = $this->facturaDeGrupo('Residencial', 50000);
        $corporativo = $this->facturaDeGrupo('Corporativo', 200000);

        $grupoResidencial = $residencial->contract->affinity_group_id;

        $respuesta = $this->get(route('billing_runs.show', [
            $this->corrida(),
            'affinity_group_id' => [$grupoResidencial],
        ]))->assertOk();

        $facturas = $respuesta->viewData('facturas');

        $this->assertCount(1, $facturas);
        $this->assertSame($residencial->id, $facturas->first()->id);

        // Y el resumen de arriba se acota con el listado, no se queda
        // contando la corrida entera.
        $this->assertEqualsWithDelta(50000, $respuesta->viewData('resumen')['total'], 0.01);
        $this->assertNotEquals($corporativo->id, $facturas->first()->id);
    }

    public function test_la_descarga_hereda_el_filtro(): void
    {
        // Si la pantalla enseña un grupo y el archivo trae toda la
        // corrida, lo que se le entrega a contabilidad no es lo que se
        // revisó.
        $residencial = $this->facturaDeGrupo('Residencial', 50000);
        $this->facturaDeGrupo('Corporativo', 200000);

        $export = new \App\Exports\BillingRunExport(
            $this->corrida(),
            [$residencial->contract->affinity_group_id],
        );

        $filas = $export->collection();

        $this->assertCount(1, $filas);
        // El grupo va como columna, para poder dinamizar en la hoja.
        $this->assertContains('Grupo de afinidad', $export->headings());
        $this->assertContains('Residencial', $filas->first());
    }

    // ==================== La etiqueta «Por recaudar» ====================

    public function test_por_recaudar_sigue_al_filtro_de_grupo(): void
    {
        $residencial = $this->facturaDeGrupo('Residencial', 50000);
        $corporativo = $this->facturaDeGrupo('Corporativo', 200000);

        foreach ([$residencial, $corporativo] as $factura) {
            $factura->forceFill([
                'status' => InvoiceStatus::Pendiente->value,
                'pending_invoice_amount' => $factura->total,
            ])->save();
        }

        // Sin filtro: todo.
        $this->assertEqualsWithDelta(
            250000,
            (float) $this->get(route('invoices.index'))->assertOk()->viewData('totalPendding'),
            0.01,
        );

        // Con filtro: solo el grupo pedido. Antes seguía diciendo
        // 250.000 mientras la tabla de abajo enseñaba una sola factura.
        $this->assertEqualsWithDelta(
            50000,
            (float) $this->get(route('invoices.index', [
                'affinity_group_id' => [$residencial->contract->affinity_group_id],
            ]))->assertOk()->viewData('totalPendding'),
            0.01,
        );
    }

    public function test_la_etiqueta_dice_que_esta_acotada(): void
    {
        // Una cifra filtrada presentada como si fuera el total de la
        // sucursal es peor que no enseñarla.
        $residencial = $this->facturaDeGrupo('Residencial', 50000);

        $this->get(route('invoices.index', [
            'affinity_group_id' => [$residencial->contract->affinity_group_id],
        ]))
            ->assertOk()
            ->assertSee('Por recaudar', false)
            ->assertSee('solo Residencial', false);
    }
}
