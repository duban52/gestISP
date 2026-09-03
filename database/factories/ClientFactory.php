<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //creación de clientes de prueba
            'branch_id' => fake()->randomElement([1 , 2, 3]),
            'type_document' => 'Cédula de ciudadanía',
            // El codigo es lo que vale desde la fase 8; el texto se
            // conserva como respaldo durante la transicion.
            'document_type_code' => '13',
            'identity_number' => fake()->unique()->numerify('##########'),
            'name' => fake()->name(),
            'last_name' => fake()->lastName(),
            'type_client' => fake()->text(10),
            'number_phone' =>fake()->phoneNumber(),
            'aditional_phone' => fake()->phoneNumber(),
            'email' => fake()->email(),
            'birthday' => fake()->date(),
            'user_id' => 1,

        ];
    }
}
