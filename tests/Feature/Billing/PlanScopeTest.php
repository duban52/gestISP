<?php

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\Service;
use App\Tenancy\CurrentContext;

/**
 * El ámbito de un plan: de la empresa, o de una sola sucursal.
 *
 * El plan SÍ guardaba su ámbito —al revés que el servicio, que no lo
 * escribía—, pero le faltaban los dos frenos del nivel de abajo:
 *
 *   1. Encerrarlo en una sede con contratos de otras colgando. El
 *      contrato no revienta, pero su validación exige que el plan sea
 *      de la empresa o de SU sucursal: queda sin poder guardarse ni
 *      para cambiarle la dirección.
 *
 *   2. Meterle servicios que el plan no alcanza. `exists:services,id`
 *      valía cualquier fila de la tabla: un servicio de otra empresa,
 *      o —lo probable— uno exclusivo de una sede dentro de un plan de
 *      la empresa, que en las demás factura un renglón de menos.
 */
class PlanScopeTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El alcance de empresa solo actúa con el contexto activo, y
        // es parte de lo que se comprueba aquí.
        app(CurrentContext::class)->establecer(
            $this->branch->company_id,
            [$this->branch->id],
            $this->branch->id,
        );
    }

    private function planDeLaEmpresa(string $nombre = 'Hogar 100'): Plan
    {
        return Plan::factory()->create([
            'name' => $nombre,
            'branch_id' => null,
            'company_id' => $this->branch->company_id,
            'user_id' => $this->admin->id,
        ]);
    }

    private function guardar(Plan $plan, array $extra = [])
    {
        return $this->put(route('plans.update', $plan), array_merge([
            'name' => $plan->name,
            'active' => '1',
        ], $extra));
    }

    // ==================== El ámbito ====================

    public function test_pasar_un_plan_de_la_empresa_a_una_sucursal(): void
    {
        $plan = $this->planDeLaEmpresa();

        $this->guardar($plan, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ])->assertRedirect(route('plans.index'));

        $this->assertSame($this->branch->id, $plan->fresh()->branch_id);
    }

    public function test_pasar_un_plan_de_la_sucursal_a_la_empresa(): void
    {
        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->guardar($plan, ['de_la_empresa' => '1'])
            ->assertRedirect(route('plans.index'));

        $this->assertNull($plan->fresh()->branch_id);
    }

    public function test_no_se_encierra_un_plan_con_contratos_de_otra_sucursal(): void
    {
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);
        $plan = $this->planDeLaEmpresa();

        $cliente = Client::factory()->create([
            'branch_id' => $otra->id,
            'number_phone' => '3111111111',
            'aditional_phone' => '3111111112',
            'user_id' => $this->admin->id,
        ]);

        Contract::factory()->create([
            'branch_id' => $otra->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
            'status' => 'Activo',
        ]);

        $this->guardar($plan, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ])->assertSessionHas('error');

        $this->assertNull($plan->fresh()->branch_id, 'no se tocó');
    }

    public function test_los_contratos_de_la_misma_sucursal_no_estorban(): void
    {
        $plan = $this->planDeLaEmpresa();

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'number_phone' => '3111111113',
            'aditional_phone' => '3111111114',
            'user_id' => $this->admin->id,
        ]);

        Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
            'status' => 'Activo',
        ]);

        $this->guardar($plan, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ])->assertRedirect(route('plans.index'));

        $this->assertSame($this->branch->id, $plan->fresh()->branch_id);
    }

    public function test_abrirlo_a_la_empresa_nunca_se_frena(): void
    {
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $cliente = Client::factory()->create([
            'branch_id' => $otra->id,
            'number_phone' => '3111111115',
            'aditional_phone' => '3111111116',
            'user_id' => $this->admin->id,
        ]);

        Contract::factory()->create([
            'branch_id' => $otra->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
            'status' => 'Activo',
        ]);

        $this->guardar($plan, ['de_la_empresa' => '1'])
            ->assertRedirect(route('plans.index'));

        $this->assertNull($plan->fresh()->branch_id);
    }

    // ==================== Los servicios que puede llevar ====================

    public function test_un_plan_de_la_empresa_no_admite_un_servicio_de_una_sede(): void
    {
        // Es el que factura de menos sin avisar: en las demás
        // sucursales el plan sale con un renglón menos.
        $plan = $this->planDeLaEmpresa();

        $servicioDeSede = Service::factory()->create([
            'name' => 'TV local',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->guardar($plan, [
            'de_la_empresa' => '1',
            'services' => [$servicioDeSede->id],
        ])->assertSessionHasErrors('services.0');

        $this->assertSame(0, $plan->fresh()->services()->count());
    }

    public function test_un_plan_de_la_empresa_si_admite_servicios_de_la_empresa(): void
    {
        $plan = $this->planDeLaEmpresa();

        $servicio = Service::factory()->create([
            'name' => 'Internet 100',
            'branch_id' => null,
            'company_id' => $this->branch->company_id,
            'user_id' => $this->admin->id,
        ]);

        $this->guardar($plan, [
            'de_la_empresa' => '1',
            'services' => [$servicio->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $plan->fresh()->services()->count());
    }

    public function test_un_plan_de_sucursal_admite_los_suyos_y_los_de_la_empresa(): void
    {
        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $deLaEmpresa = Service::factory()->create([
            'name' => 'Internet 100',
            'branch_id' => null,
            'company_id' => $this->branch->company_id,
            'user_id' => $this->admin->id,
        ]);

        $deLaSede = Service::factory()->create([
            'name' => 'TV local',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->guardar($plan, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
            'services' => [$deLaEmpresa->id, $deLaSede->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $plan->fresh()->services()->count());
    }

    public function test_no_se_puede_meter_un_servicio_de_otra_empresa(): void
    {
        // El formulario no lo ofrece, pero `exists:services,id` a secas
        // aceptaba cualquier id que existiera en la tabla.
        $otraEmpresa = Company::factory()->create();
        $sedeAjena = Branch::factory()->create(['company_id' => $otraEmpresa->id]);

        $ajeno = Service::factory()->create([
            'name' => 'Internet de otro',
            'branch_id' => $sedeAjena->id,
            'user_id' => $this->admin->id,
        ]);

        $plan = $this->planDeLaEmpresa();

        $this->guardar($plan, [
            'de_la_empresa' => '1',
            'services' => [$ajeno->id],
        ])->assertSessionHasErrors('services.0');

        $this->assertSame(0, $plan->fresh()->services()->count());
    }

    public function test_el_formulario_muestra_el_ambito_que_tiene(): void
    {
        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->get(route('plans.edit', $plan))
            ->assertOk()
            ->assertSee('Ahora es exclusivo de', false);
    }

    // ==================== El listado ====================

    public function test_el_listado_distingue_lo_de_la_empresa_de_lo_de_la_sede(): void
    {
        $this->planDeLaEmpresa('Hogar compartido');

        Plan::factory()->create([
            'name' => 'Hogar local',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->get(route('plans.index'))
            ->assertOk()
            ->assertSee('Ámbito', false)
            ->assertSee('Empresa', false)
            ->assertSee($this->branch->name, false);
    }

}
