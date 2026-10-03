<?php

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\Service;

/**
 * El ámbito de un servicio: de la empresa, o de una sola sucursal.
 *
 * EL FALLO
 * --------
 * El formulario de edición ofrecía el radio desde que existe el
 * catálogo compartido, pero `ServiceController::update()` nunca
 * escribía `branch_id`: se cambiaba la opción, se guardaba, salía el
 * «actualizado con éxito» y el servicio seguía igual. Sin error, sin
 * aviso, sin nada. El plan sí lo guardaba, que es por lo que nadie
 * sospechó del servicio.
 *
 * Y EL FRENO QUE HACÍA FALTA
 * --------------------------
 * Encerrar en una sede un servicio que usan planes de otras les quita
 * el servicio sin decirlo: el plan no revienta, pero el servicio
 * desaparece de su pantalla, y el siguiente que lo edite y guarde lo
 * pierde para siempre. Eso se niega, nombrando los planes.
 */
class ServiceScopeTest extends BillingTestCase
{
    private function servicioDeLaEmpresa(): Service
    {
        return Service::factory()->create([
            'name' => 'Internet 50 megas',
            'branch_id' => null,
            'user_id' => $this->admin->id,
        ]);
    }

    private function guardar(Service $servicio, array $extra = [])
    {
        return $this->put(route('services.update', $servicio), array_merge([
            'name' => $servicio->name,
            'base_price' => '100000',
            'tax_percentage' => '0',
        ], $extra));
    }

    public function test_pasar_un_servicio_de_la_empresa_a_una_sucursal(): void
    {
        $servicio = $this->servicioDeLaEmpresa();

        $this->guardar($servicio, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ])->assertRedirect(route('services.index'));

        $this->assertSame($this->branch->id, $servicio->fresh()->branch_id);
    }

    public function test_pasar_un_servicio_de_la_sucursal_a_la_empresa(): void
    {
        $servicio = Service::factory()->create([
            'name' => 'TV por suscripción',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->guardar($servicio, ['de_la_empresa' => '1'])
            ->assertRedirect(route('services.index'));

        $this->assertNull($servicio->fresh()->branch_id);
    }

    public function test_el_resto_de_los_campos_se_siguen_guardando(): void
    {
        // El arreglo no puede llevarse por delante lo que ya funcionaba
        $servicio = $this->servicioDeLaEmpresa();

        $this->guardar($servicio, [
            'name' => 'Internet 100 megas',
            'base_price' => '27.731,09',
            'tax_percentage' => '19',
        ])->assertRedirect(route('services.index'));

        $servicio->refresh();
        $this->assertSame('Internet 100 megas', $servicio->name);
        $this->assertEqualsWithDelta(27731.09, (float) $servicio->base_price, 0.001);
        $this->assertEqualsWithDelta(19, (float) $servicio->tax_percentage, 0.001);
        $this->assertNull($servicio->branch_id, 'sin tocar el radio sigue siendo de la empresa');
    }

    public function test_no_se_encierra_un_servicio_que_usa_un_plan_de_otra_sucursal(): void
    {
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $servicio = $this->servicioDeLaEmpresa();

        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $otra->id,
            'user_id' => $this->admin->id,
        ]);
        $plan->services()->attach($servicio->id);

        $this->guardar($servicio, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ])->assertSessionHas('error');

        $this->assertNull($servicio->fresh()->branch_id, 'no se tocó');
    }

    public function test_el_aviso_nombra_el_plan_que_estorba(): void
    {
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);
        $servicio = $this->servicioDeLaEmpresa();

        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $otra->id,
            'user_id' => $this->admin->id,
        ]);
        $plan->services()->attach($servicio->id);

        $this->guardar($servicio, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertStringContainsString('Hogar 50', session('error'));
    }

    public function test_un_plan_de_la_empresa_tambien_lo_impide(): void
    {
        // Un plan de la empresa vale en todas las sedes: no puede
        // depender de un servicio que solo existe en una.
        $servicio = $this->servicioDeLaEmpresa();

        $plan = Plan::factory()->create([
            'name' => 'Plan corporativo',
            'branch_id' => null,
            // Un plan de la empresa SÍ lleva company_id: se lo pone el
            // contexto al guardarlo desde la pantalla. Sin él, el
            // filtro de empresa lo dejaría fuera y la prueba estaría
            // comprobando otra cosa.
            'company_id' => $this->branch->company_id,
            'user_id' => $this->admin->id,
        ]);
        $plan->services()->attach($servicio->id);

        $this->guardar($servicio, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ])->assertSessionHas('error');

        $this->assertNull($servicio->fresh()->branch_id);
    }

    public function test_un_plan_de_la_misma_sucursal_no_estorba(): void
    {
        $servicio = $this->servicioDeLaEmpresa();

        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);
        $plan->services()->attach($servicio->id);

        $this->guardar($servicio, [
            'de_la_empresa' => '0',
            'branch_id' => $this->branch->id,
        ])->assertRedirect(route('services.index'));

        $this->assertSame($this->branch->id, $servicio->fresh()->branch_id);
    }

    public function test_abrirlo_a_la_empresa_nunca_se_frena(): void
    {
        // Ampliar el ámbito no le quita el servicio a nadie
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $servicio = Service::factory()->create([
            'name' => 'Internet 50 megas',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $plan = Plan::factory()->create([
            'name' => 'Hogar 50',
            'branch_id' => $otra->id,
            'user_id' => $this->admin->id,
        ]);
        $plan->services()->attach($servicio->id);

        $this->guardar($servicio, ['de_la_empresa' => '1'])
            ->assertRedirect(route('services.index'));

        $this->assertNull($servicio->fresh()->branch_id);
    }

    public function test_el_formulario_muestra_el_ambito_que_tiene(): void
    {
        $servicio = Service::factory()->create([
            'name' => 'TV por suscripción',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->get(route('services.edit', $servicio))
            ->assertOk()
            ->assertSee('Ahora es exclusivo de', false);
    }

    // ==================== El listado ====================

    public function test_el_listado_distingue_lo_de_la_empresa_de_lo_de_la_sede(): void
    {
        // Antes la columna pintaba el nombre de la sede o un guion, y
        // el guion es ambiguo: lo mismo se lee «de la empresa» que
        // «sin asignar». Y DataTables la escondia fuera del
        // consolidado, justo cuando el usuario tampoco podia deducirlo.
        Service::factory()->create([
            'name' => 'Internet compartido',
            'branch_id' => null,
            'company_id' => $this->branch->company_id,
            'user_id' => $this->admin->id,
        ]);

        Service::factory()->create([
            'name' => 'TV local',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->get(route('services.index'))
            ->assertOk()
            ->assertSee('Ámbito', false)
            ->assertSee('Empresa', false)
            ->assertSee($this->branch->name, false);
    }

}
