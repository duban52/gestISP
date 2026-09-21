<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\BillingMode;
use App\Billing\Enums\ProrationMode;
use App\Models\Branch;
use App\Models\BranchBillingSetting;

/**
 * Copiar la configuración de facturación a todas las sucursales.
 *
 * No hay una configuración «de la empresa» guardada aparte, y es
 * deliberado: la que manda sigue siendo la de cada sucursal, que es la
 * que leen los servicios de facturación. Si hubiera dos sitios, la
 * pantalla de la sucursal podría estar enseñando algo distinto de lo
 * que se aplica.
 *
 * Esto es un «aplicar a todas» para no configurar cinco sedes una por
 * una — que es como se separan— y para VER cuáles quedaron distintas.
 */
class CompanyBillingSettingsTest extends BillingTestCase
{
    private function otraSucursal(): Branch
    {
        return Branch::factory()->create(['company_id' => $this->branch->company_id]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'proration_mode' => ProrationMode::FullMonth->value,
            'billing_mode' => BillingMode::Automatico->value,
            'billing_day' => 3,
            'due_days' => 15,
            'suspension_threshold' => 3,
            'suspension_days' => 10,
        ], $extra);
    }

    public function test_se_aplica_a_todas_las_sucursales_de_la_empresa(): void
    {
        $otra = $this->otraSucursal();

        $this->post(route('companies.billing', $this->branch->company_id), $this->payload())
            ->assertSessionHasNoErrors();

        foreach ([$this->branch, $otra] as $sucursal) {
            $config = BranchBillingSetting::forBranch($sucursal->id)->fresh();

            $this->assertSame(ProrationMode::FullMonth, $config->proration_mode);
            $this->assertSame(BillingMode::Automatico, $config->billing_mode);
            $this->assertSame(3, $config->billing_day);
            $this->assertSame(15, $config->due_days);
            $this->assertSame(3, $config->suspension_threshold);
            $this->assertSame(10, $config->suspension_days);
        }
    }

    public function test_no_toca_las_sucursales_de_otra_empresa(): void
    {
        $ajena = Branch::factory()->create();
        $antes = BranchBillingSetting::forBranch($ajena->id);

        $this->post(route('companies.billing', $this->branch->company_id), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame($antes->due_days, BranchBillingSetting::forBranch($ajena->id)->fresh()->due_days);
    }

    public function test_en_manual_no_guarda_el_dia(): void
    {
        $this->post(route('companies.billing', $this->branch->company_id), $this->payload([
            'billing_mode' => BillingMode::Manual->value,
            'billing_day' => 3,
        ]))->assertSessionHasNoErrors();

        $this->assertNull(BranchBillingSetting::forBranch($this->branch->id)->fresh()->billing_day);
    }

    public function test_en_automatico_el_dia_es_obligatorio(): void
    {
        $this->post(route('companies.billing', $this->branch->company_id), $this->payload([
            'billing_day' => null,
        ]))->assertSessionHasErrors('billing_day');
    }

    public function test_la_ficha_de_la_empresa_avisa_de_las_sucursales_distintas(): void
    {
        // Una sede con otro plazo no se ve por ningún lado hasta que un
        // cliente reclama.
        $otra = $this->otraSucursal();
        BranchBillingSetting::forBranch($otra->id)->update(['due_days' => 45]);

        $this->get(route('companies.show', $this->branch->company_id))
            ->assertOk()
            ->assertSee('configuración distinta')
            ->assertSee($otra->name);
    }
}
