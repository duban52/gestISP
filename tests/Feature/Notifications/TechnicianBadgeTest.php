<?php

namespace Tests\Feature\Notifications;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\TechnicalOrderAssignedTechnician;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Contador de órdenes no vistas y sondeo del navegador para técnicos.
 */
class TechnicianBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $tecnico;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->rol = Role::where('name', 'tecnico')->firstOrFail();
        $this->rol->givePermissionTo('technicals_orders.my_technical_orders');

        $this->tecnico = User::factory()->create(['number_phone' => '3159998877']);
        $this->tecnico->assignRole($this->rol);
        $this->tecnico->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->tecnico)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    /**
     * Crea una notificación de asignación no leída, como la que deja
     * el canal database al asignar una orden.
     */
    private function notificacionNoLeida(): void
    {
        $this->tecnico->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TechnicalOrderAssignedTechnician::class,
            'data' => ['titulo' => 'Nueva orden asignada', 'detalle' => 'Instalación', 'url' => '/x'],
            'read_at' => null,
        ]);
    }

    public function test_el_sondeo_devuelve_las_no_leidas(): void
    {
        $this->notificacionNoLeida();
        $this->notificacionNoLeida();

        $this->getJson(route('notifications.poll'))
            ->assertOk()
            ->assertJsonPath('unread', 2)
            ->assertJsonCount(2, 'items');
    }

    public function test_abrir_mis_ordenes_marca_las_notificaciones_como_leidas(): void
    {
        $this->notificacionNoLeida();
        $this->notificacionNoLeida();

        $this->assertSame(2, $this->tecnico->unreadNotifications()->count());

        $this->get(route('technicals_orders.my_technical_orders'))->assertOk();

        $this->assertSame(0, $this->tecnico->fresh()->unreadNotifications()->count());
    }

    public function test_marcar_todas_como_leidas(): void
    {
        $this->notificacionNoLeida();

        $this->post(route('notifications.read_all'))
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->assertSame(0, $this->tecnico->fresh()->unreadNotifications()->count());
    }
}
