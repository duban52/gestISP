<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 *
 * Una factura minima, para las pruebas que necesitan una factura pero no
 * les importa lo que lleve dentro —la transmision a la DIAN, por
 * ejemplo, que solo necesita algo de lo que colgar el documento
 * electronico—.
 *
 * Para probar la EMISION no se usa esto: se emite de verdad con
 * InvoiceGenerator, que es lo unico que produce una factura con sus
 * lineas, sus impuestos y su numeracion.
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $sucursal = Branch::factory()->create();

        return [
            'branch_id' => $sucursal->id,
            'company_id' => $sucursal->company_id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'subtotal' => 100000,
            'tax' => 19000,
            'total' => 119000,
            'pending_invoice_amount' => 119000,
            'status' => 'Pendiente',
        ];
    }
}
