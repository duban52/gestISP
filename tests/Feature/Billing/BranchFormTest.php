<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\BillingMode;
use App\Billing\Enums\ProrationMode;
use App\Models\Branch;
use App\Models\BranchBillingSetting;

/**
 * El formulario de crear sucursal trae todo lo que conlleva una
 * sucursal, igual que el de editar.
 *
 * Antes se creaba a medias: sin prefijo de contrato y sin configuración
 * de facturación, así que había que acordarse de entrar a editarla
 * justo después. Y el departamento y el municipio se escribían a mano,
 * lo que deja «Medellin», «medellín» y «Medellín» conviviendo en la
 * base y cualquier informe por ciudad contándolos como tres.
 */
class BranchFormTest extends BillingTestCase
{
    private function datos(array $extra = []): array
    {
        return array_merge([
            'company_id' => $this->branch->company_id,
            'name' => 'Sucursal Nueva',
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'address' => 'Calle 20 # 19-30',
            'number_phone' => '3001234567',
        ], $extra);
    }

    public function test_el_formulario_ofrece_los_mismos_campos_que_el_de_editar(): void
    {
        $respuesta = $this->get(route('branches.create'))->assertOk();

        foreach ([
            'name="contract_prefix"',
            'name="department"',
            'name="municipality"',
            'name="proration_mode"',
            'name="billing_mode"',
            'name="billing_day"',
            'name="due_days"',
            'name="suspension_threshold"',
            'name="suspension_days"',
            'name="moving_price"',
            'name="reconnection_price"',
        ] as $campo) {
            $respuesta->assertSee($campo, false);
        }
    }

    public function test_el_departamento_y_el_municipio_son_listas(): void
    {
        // Del catálogo de ColombiaLocations, no escritos a mano.
        $this->get(route('branches.create'))
            ->assertOk()
            ->assertSee('id="department"', false)
            // Las opciones salen del catalogo, no las escribe nadie.
            ->assertSee('<option value="Antioquia"', false)
            ->assertSee('<option value="Vaupés"', false)
            ->assertSee('Busque o seleccione una ciudad o municipio');
    }

    public function test_la_pantalla_de_editar_usa_las_mismas_listas(): void
    {
        $this->get(route('branches.edit', $this->branch))
            ->assertOk()
            ->assertSee('id="municipality"', false)
            ->assertSee('Busque o seleccione un departamento');
    }

    public function test_crear_guarda_tambien_la_configuracion_de_facturacion(): void
    {
        $this->post(route('branches.store'), $this->datos([
            'contract_prefix' => 'yar',
            'proration_mode' => ProrationMode::FullMonth->value,
            'billing_mode' => BillingMode::Automatico->value,
            'billing_day' => 7,
            'due_days' => 12,
            'suspension_threshold' => 3,
            'suspension_days' => 15,
        ]))->assertSessionHasNoErrors();

        $sucursal = Branch::where('name', 'Sucursal Nueva')->firstOrFail();
        $config = BranchBillingSetting::forBranch($sucursal->id);

        // El prefijo se guarda en mayúsculas, como en editar.
        $this->assertSame('YAR', $sucursal->contract_prefix);
        $this->assertSame(ProrationMode::FullMonth, $config->proration_mode);
        $this->assertSame(BillingMode::Automatico, $config->billing_mode);
        $this->assertSame(7, $config->billing_day);
        $this->assertSame(12, $config->due_days);
    }

    public function test_sin_configuracion_la_sucursal_nace_con_los_valores_de_siempre(): void
    {
        // Otra pantalla puede crear sucursales sin mandar esos campos:
        // exigirlos la rompería.
        $this->post(route('branches.store'), $this->datos(['name' => 'Sin Configurar']))
            ->assertSessionHasNoErrors();

        $sucursal = Branch::where('name', 'Sin Configurar')->firstOrFail();
        $config = BranchBillingSetting::forBranch($sucursal->id);

        $this->assertSame(ProrationMode::Prorated, $config->proration_mode);
        $this->assertSame(BillingMode::Manual, $config->billing_mode);
        $this->assertSame(20, $config->due_days);
    }

    public function test_en_automatico_el_dia_es_obligatorio_al_crear(): void
    {
        $this->post(route('branches.store'), $this->datos([
            'billing_mode' => BillingMode::Automatico->value,
        ]))->assertSessionHasErrors('billing_day');

        $this->assertNull(Branch::where('name', 'Sucursal Nueva')->first());
    }
}
