<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Un plan con contratos no desaparece: se retira.
 *
 * DE DÓNDE SALE
 * -------------
 * `contracts.plan_id` estaba con `onDelete('set null')`. Borrar un plan
 * dejaba sin plan a todos sus contratos, y un contrato sin plan no
 * tiene servicios que facturar —salen de `$contract->plan->services`—.
 *
 * En una factura interna eso es un documento absurdo. En una
 * ELECTRÓNICA es caro: gasta un consecutivo del rango autorizado, que
 * no se recupera, en un XML que el XSD de la DIAN rechaza, porque un
 * `Invoice` sin `InvoiceLine` no es válido.
 *
 * LAS TRES REDES, Y POR QUÉ HACEN FALTA LAS TRES
 * ----------------------------------------------
 *   1. `PlanController::destroy()` se niega y da un mensaje legible.
 *      Es la de arriba y solo cubre ese camino.
 *   2. La clave foránea `restrictOnDelete`. Es la de abajo: ataja
 *      también un borrado por SQL o un camino nuevo que nadie recuerde
 *      blindar. Es la que se comprueba aquí.
 *   3. `InvoiceGenerator` se niega a emitir una factura vacía, por si
 *      alguna vez un contrato acaba sin plan de otra forma.
 *
 * Y la consecuencia de todo esto: si un plan no se puede borrar, tiene
 * que poder RETIRARSE, o el catálogo solo crece.
 */
class PlanRetirementTest extends BillingTestCase
{
    // ==================== La clave foránea ====================

    public function test_la_base_impide_borrar_un_plan_con_contratos(): void
    {
        // Se borra por SQL a propósito: salta el guardián del
        // controlador y llega a la red de abajo, que es la que se
        // quiere comprobar.
        $contrato = $this->createBillableContract();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('plans')->where('id', $contrato->plan_id)->delete();
    }

    public function test_el_contrato_conserva_su_plan(): void
    {
        $contrato = $this->createBillableContract();
        $planId = $contrato->plan_id;

        try {
            DB::table('plans')->where('id', $planId)->delete();
        } catch (\Throwable) {
            // Se esperaba.
        }

        $this->assertSame($planId, $contrato->fresh()->plan_id);
    }

    public function test_un_plan_sin_contratos_si_se_borra(): void
    {
        // No se trata de blindar todo: un plan que nadie usa se borra.
        $this->comoAdministrador();

        $plan = Plan::create([
            'name' => 'Plan que nadie contrató',
            'branch_id' => $this->branch->id,
        ]);

        $this->delete(route('plans.destroy', $plan))->assertRedirect();

        $this->assertNull(Plan::withoutGlobalScopes()->find($plan->id));
    }

    // ==================== Retirar ====================

    public function test_retirar_un_plan_no_toca_sus_contratos(): void
    {
        $this->comoAdministrador();

        $contrato = $this->createBillableContract();

        $this->patch(route('plans.toggle', $contrato->plan_id))->assertRedirect();

        $this->assertFalse(Plan::withoutGlobalScopes()->find($contrato->plan_id)->active);
        $this->assertSame($contrato->plan_id, $contrato->fresh()->plan_id);
    }

    public function test_un_contrato_con_plan_retirado_se_sigue_facturando(): void
    {
        // Es la razón de ser de «retirar» frente a «borrar»: dejar de
        // vender un plan no puede dejar de cobrarle a quien ya lo tiene.
        $this->comoAdministrador();

        $contrato = $this->createBillableContract();
        Plan::withoutGlobalScopes()->whereKey($contrato->plan_id)->update(['active' => false]);

        $this->post(route('contracts.invoice', $contrato))->assertRedirect();

        $this->assertSame(
            1,
            \App\Models\Invoice::where('contract_id', $contrato->id)->count(),
            'Un plan retirado dejó de facturar a un contrato vivo.',
        );
    }

    public function test_un_plan_retirado_no_se_ofrece_en_contratos_nuevos(): void
    {
        $this->comoAdministrador();

        $contrato = $this->createBillableContract();
        $plan = Plan::withoutGlobalScopes()->findOrFail($contrato->plan_id);
        $plan->update(['active' => false]);

        $this->get(route('contracts.create', $contrato->client_id))
            ->assertOk()
            ->assertDontSee($plan->name, escape: false);
    }

    public function test_se_puede_devolver_al_catalogo(): void
    {
        $this->comoAdministrador();

        $plan = Plan::create([
            'name' => 'Plan retirado',
            'active' => false,
            'branch_id' => $this->branch->id,
        ]);

        $this->patch(route('plans.toggle', $plan))->assertRedirect();

        $this->assertTrue($plan->fresh()->active);
    }

    public function test_retirar_es_editar_no_borrar(): void
    {
        // Lleva el permiso plans.edit: los contratos que lo tienen lo
        // conservan, así que no es una eliminación.
        $this->comoAdministrador();
        Role::where('name', 'superadministrador')->firstOrFail()->revokePermissionTo('plans.edit');

        $plan = Plan::create(['name' => 'Un plan', 'branch_id' => $this->branch->id]);

        $this->patch(route('plans.toggle', $plan))->assertForbidden();
    }

    // ==================== El plan nuevo nace activo ====================

    public function test_un_plan_nuevo_se_ofrece_por_defecto(): void
    {
        $this->comoAdministrador();

        $servicio = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->post(route('plans.store'), [
            'name' => 'Plan recién creado',
            'services' => [$servicio->id],
        ])->assertRedirect();

        $this->assertTrue(
            Plan::withoutGlobalScopes()->where('name', 'Plan recién creado')->firstOrFail()->active,
        );
    }

    private function comoAdministrador(): void
    {
        // BillingTestCase ya deja un superadministrador autenticado con
        // su contexto; aquí solo se documenta la intención.
    }
}
