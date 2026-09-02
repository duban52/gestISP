<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Cada sucursal nace con su propia empresa, salvo que la
        // prueba diga otra cosa. Eso hace que las pruebas sean
        // multiempresa por defecto, que es justo lo que hace falta
        // para que salten los fallos de aislamiento entre empresas.
        // Para varias sucursales de la MISMA empresa:
        //
        //     $empresa = Company::factory()->create();
        //     Branch::factory()->create(['company_id' => $empresa->id]);
        //     Branch::factory()->create(['company_id' => $empresa->id]);
        return [
            'company_id' => Company::factory(),
            'nit' => fake()->unique()->numerify('##########'),
            'name' => fake()->unique()->city(),
            'country' => fake()->country(),
            'department' => fake()->state(),
            'municipality' => fake()->city(),
            'address' => fake()->address(),
            'number_phone' => fake()->phoneNumber(),
            'additional_number' => fake()->optional()->phoneNumber(),
            'image' => fake()->optional()->imageUrl(640, 480, 'business', true, 'Faker'),
            'moving_price' => fake()->optional()->randomFloat(2, 10, 500),
            'reconnection_price' => fake()->optional()->randomFloat(2, 10, 500),
            'message_custom_invoice' => fake()->optional()->sentence(),
            'observation' => fake()->optional()->paragraph(),
        ];
    }
}
