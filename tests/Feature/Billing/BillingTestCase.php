<?php

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Base de los tests de facturación.
 *
 * Prepara el entorno mínimo real del módulo: roles y permisos del
 * seeder oficial, un superadministrador autenticado con sucursal y
 * rol activos en sesión (como deja el login), y helpers para armar
 * la cadena sucursal → cliente → plan → servicios → contrato.
 */
abstract class BillingTestCase extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->admin = $this->createSuperadmin($this->branch);
    }

    /**
     * Crea un superadministrador asignado a la sucursal y lo deja
     * autenticado con la sesión que produce el login real
     * (branch_id + current_role_id).
     */
    protected function createSuperadmin(Branch $branch): User
    {
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $user = User::factory()->create(['number_phone' => '3000000000']);
        $user->assignRole($role);
        $user->branches()->attach($branch->id, ['role_id' => $role->id]);

        $this->actingAs($user)->withSession([
            'branch_id' => $branch->id,
            'current_role_id' => $role->id,
        ]);

        return $user;
    }

    /**
     * Abre una caja para el usuario autenticado del test.
     * Todo cobro exige caja abierta, así que los tests de pago
     * deben llamar esto antes de cobrar.
     */
    protected function openCashRegister(float $initialAmount = 0): CashRegister
    {
        return CashRegister::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'initial_amount' => $initialAmount,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    /**
     * Crea un contrato facturable completo en la sucursal del test:
     * cliente + servicio (precio/IVA dados) + plan + contrato Activo.
     *
     * @param float $price      Precio base mensual del servicio
     * @param float $taxPercent Porcentaje de IVA del servicio
     * @param string|null $activationDate Fecha de activación (null = primer día del mes actual)
     */
    protected function createBillableContract(
        float $price = 100000,
        float $taxPercent = 0,
        ?string $activationDate = null
    ): Contract {
        $client = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'number_phone' => '3111111111',
            'aditional_phone' => '3111111112',
            'user_id' => $this->admin->id,
        ]);

        $service = Service::factory()->create([
            'base_price' => $price,
            'tax_percentage' => $taxPercent,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);
        $plan->services()->attach($service->id);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
            'status' => 'Activo',
            'activation_date' => $activationDate ?? now()->startOfMonth()->toDateString(),
        ]);
    }
}
