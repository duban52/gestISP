<?php

namespace Tests\Feature\TechnicalOrders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Material;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Comprobante PDF de una orden técnica (soporte ante el cliente).
 */
class OrderPdfTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);
    }

    private function ordenCerrada(): TechnicalOrder
    {
        $plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);

        $client = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
        ]);

        $contract = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'status' => 'Activo',
            'user_id' => $this->user->id,
        ]);

        $order = TechnicalOrder::create([
            'contract_id' => $contract->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->user->id,
            'created_by' => $this->user->id,
            'type' => 'Servicio',
            'detail' => 'Instalación de servicio',
            'status' => 'Cerrada',
            'initial_comment' => 'Instalación del servicio',
            'observations_technical' => 'Se instaló la ONT y se probó el enlace.',
            'client_observation' => 'Cliente conforme con la instalación.',
            'solution' => 'Servicio activo y navegando.',
            'client_signature' => 'storage/technical_orders/signatures/inexistente.png',
        ]);

        $material = Material::create(['name' => 'ONT Huawei', 'is_equipment' => true]);
        $order->materials()->create([
            'material_id' => $material->id,
            'quantity' => 1,
            'serial_number' => 'SN-ONT-001',
        ]);

        return $order;
    }

    public function test_genera_el_pdf_de_una_orden(): void
    {
        $order = $this->ordenCerrada();

        $respuesta = $this->get(route('technicals_orders.pdf', $order->id));

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'application/pdf');

        // El cuerpo es un PDF real (empieza con la firma %PDF)
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    public function test_el_pdf_requiere_autenticacion(): void
    {
        $order = $this->ordenCerrada();

        $this->app['auth']->logout();
        session()->flush();

        $respuesta = $this->get(route('technicals_orders.pdf', $order->id));

        $respuesta->assertRedirect(route('login'));
    }
}
