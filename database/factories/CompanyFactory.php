<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'legal_name' => fake()->company(),
            'document_type_code' => '31',
            'document_number' => fake()->unique()->numerify('9########'),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'operation_mode' => Company::MODO_INDEPENDIENTE,
            'electronic_invoicing_enabled' => false,
            'active' => true,
        ];
    }

    /** Empresa que trabaja todas sus sucursales desde un panel unico. */
    public function consolidada(): static
    {
        return $this->state(fn () => ['operation_mode' => Company::MODO_CONSOLIDADO]);
    }
}
