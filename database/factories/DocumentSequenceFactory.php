<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DocumentSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DocumentSequence>
 */
class DocumentSequenceFactory extends Factory
{
    protected $model = DocumentSequence::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'document_type' => DocumentSequence::CONTRATO,
            'prefix' => strtoupper(fake()->unique()->lexify('???')),
            'padding' => 6,
            'current_number' => 0,
            'active' => true,
        ];
    }

    /** Serie con rango autorizado, como sera la fiscal. */
    public function conRango(int $desde, int $hasta): static
    {
        return $this->state(fn () => [
            'range_start' => $desde,
            'range_end' => $hasta,
            'current_number' => $desde - 1,
        ]);
    }

    /** Ya no entrega numeros, pero se conserva como historico. */
    public function inactiva(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
