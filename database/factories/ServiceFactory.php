<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * branch_id y user_id no tienen default: los tests deben
     * pasarlos explícitos (o crear la cadena con los helpers de
     * BillingTestCase) para que la sucursal sea coherente en toda
     * la cadena plan → contrato → factura.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Internet ' . fake()->unique()->numerify('### Mbps'),
            'base_price' => 100000,
            'tax_percentage' => 0,
        ];
    }
}
