<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\AffinityGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AffinityGroup>
 */
class AffinityGroupFactory extends Factory
{
    protected $model = AffinityGroup::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // El código es único por empresa, así que se genera único
            // globalmente: es más restrictivo de lo necesario y nunca
            // choca.
            'code' => strtoupper(fake()->unique()->bothify('G##?')),
            'name' => fake()->words(2, true),
            'requires_electronic_invoicing' => false,
            'requires_client_tax_data' => false,
            'active' => true,
            // No se marca por defecto: la base solo admite uno por
            // empresa, y una factory que lo marcara reventaría al crear
            // el segundo grupo de la misma empresa.
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    /** El grupo que reciben los contratos que no eligen ninguno. */
    public function porDefecto(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /** Sus contratos facturan electrónicamente y se reportan a la DIAN. */
    public function electronico(): static
    {
        return $this->state(fn () => [
            'requires_electronic_invoicing' => true,
            // Un grupo electrónico exige datos fiscales del cliente:
            // van juntos en la práctica.
            'requires_client_tax_data' => true,
        ]);
    }

    /** Ya no se ofrece al dar de alta, pero sus contratos siguen en él. */
    public function inactivo(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
