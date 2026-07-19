<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * branch_id no tiene default: los tests deben pasarlo explícito
     * para mantener la coherencia de sucursal en toda la cadena.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Plan ' . fake()->unique()->numerify('###'),
        ];
    }
}
