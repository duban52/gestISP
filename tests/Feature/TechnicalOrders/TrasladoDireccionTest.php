<?php

namespace Tests\Feature\TechnicalOrders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pedir un traslado pide la dirección nueva.
 *
 * El técnico va a donde diga el contrato: un traslado sin dirección
 * nueva lo mandaría a la casa de la que el cliente se fue.
 */
class TrasladoDireccionTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contrato;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $admin = User::factory()->create();
        $admin->assignRole($rol);
        $admin->branches()->attach($branch->id, ['role_id' => $rol->id]);

        $this->actingAs($admin)->withSession([
            'branch_id' => (string) $branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $datos = ['branch_id' => $branch->id, 'user_id' => $admin->id];

        $this->contrato = Contract::factory()->create(array_merge($datos, [
            'client_id' => Client::factory()->create($datos)->id,
            'plan_id' => Plan::factory()->create($datos)->id,
            'status' => 'Activo',
            'department' => 'Antioquia',
            'municipality' => 'Medellín',
            'neighborhood' => 'Laureles',
            'address' => 'Calle 20 # 19-30',
            'latitude' => 6.2442,
            'longitude' => -75.5812,
        ]));
    }

    public function test_un_traslado_sin_direccion_nueva_no_se_crea(): void
    {
        $this->post(route('technicals_orders.store'), [
            'contract_id' => $this->contrato->id,
            'order_type' => 'Servicio',
            'order_detail' => 'Traslado de servicio',
        ])->assertSessionHasErrors(['address', 'department', 'municipality', 'neighborhood']);

        $this->assertSame(0, TechnicalOrder::count());
        $this->assertSame('Calle 20 # 19-30', $this->contrato->fresh()->address);
    }

    public function test_el_traslado_pone_el_contrato_en_la_direccion_nueva(): void
    {
        $this->post(route('technicals_orders.store'), [
            'contract_id' => $this->contrato->id,
            'order_type' => 'Servicio',
            'order_detail' => 'Traslado de servicio',
            'initial_comment' => 'Se muda el 30',
            'department' => 'Antioquia',
            'municipality' => 'Rionegro',
            'neighborhood' => 'Centro',
            '_direcciones' => ['address'],
            'address_partes' => ['tipo' => 'Carrera', 'numero' => '50', 'placa' => '48', 'placa2' => '12'],
        ])->assertSessionHasNoErrors();

        $contrato = $this->contrato->fresh();

        $this->assertSame('Carrera 50 # 48-12', $contrato->address);
        $this->assertSame('Rionegro', $contrato->municipality);
        $this->assertSame('Centro', $contrato->neighborhood);

        // El punto de la casa anterior ya no sirve y no se marcó otro.
        $this->assertFalse($contrato->isGeolocated());

        // La dirección anterior queda escrita en la orden.
        $comentario = TechnicalOrder::firstOrFail()->initial_comment;
        $this->assertStringContainsString('Se muda el 30', $comentario);
        $this->assertStringContainsString('Traslado a: Carrera 50 # 48-12, Centro, Rionegro, Antioquia', $comentario);
        $this->assertStringContainsString('Dirección anterior: Calle 20 # 19-30, Laureles, Medellín, Antioquia', $comentario);
    }

    public function test_con_punto_nuevo_el_contrato_queda_ubicado_ahi(): void
    {
        $this->post(route('technicals_orders.store'), [
            'contract_id' => $this->contrato->id,
            'order_type' => 'Servicio',
            'order_detail' => 'Traslado de servicio',
            'department' => 'Antioquia',
            'municipality' => 'Rionegro',
            'neighborhood' => 'Centro',
            '_direcciones' => ['address'],
            'address_partes' => ['tipo' => 'Carrera', 'numero' => '50', 'placa' => '48', 'placa2' => '12'],
            'latitude' => 6.1550,
            'longitude' => -75.3740,
            'location_source' => 'mapa',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(6.1550, (float) $this->contrato->fresh()->latitude);
    }

    public function test_una_orden_que_no_es_traslado_no_toca_la_direccion(): void
    {
        $this->post(route('technicals_orders.store'), [
            'contract_id' => $this->contrato->id,
            'order_type' => 'Servicio',
            'order_detail' => 'Sin servicio de internet',
        ])->assertSessionHasNoErrors();

        // Se crea aunque no traiga comentario (la columna no admite nulos).
        $this->assertSame(1, TechnicalOrder::count());
        $this->assertSame('Calle 20 # 19-30', $this->contrato->fresh()->address);
        $this->assertTrue($this->contrato->fresh()->isGeolocated());
    }

    public function test_el_formulario_marca_el_detalle_de_traslado(): void
    {
        $this->get(route('technicals_orders.create', $this->contrato))
            ->assertOk()
            ->assertSee('data-traslado="1"', false)
            ->assertSee('Dirección nueva del servicio');
    }
}
