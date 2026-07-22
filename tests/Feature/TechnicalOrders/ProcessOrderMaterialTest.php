<?php

namespace Tests\Feature\TechnicalOrders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Inventory;
use App\Models\Material;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regla de negocio del procesamiento de órdenes: una instalación no
 * puede cerrarse sin registrar el material y los equipos instalados.
 */
class ProcessOrderMaterialTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $tecnico;
    private Warehouse $almacen;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole($role);
        $this->tecnico->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        // Almacén personal del técnico (de ahí sale el material)
        $this->almacen = Warehouse::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
            'description' => 'Almacén del técnico',
        ]);

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->tecnico->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($this->tecnico)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);
    }

    private function orden(string $detalle): TechnicalOrder
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Pendiente',
            'user_id' => $this->tecnico->id,
        ]);

        return TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->tecnico->id,
            'created_by' => $this->tecnico->id,
            'type' => 'Servicio',
            'detail' => $detalle,
            'status' => 'Asignada',
            'initial_comment' => 'Orden de prueba',
        ]);
    }

    private function equipoConSerial(string $serial): Material
    {
        $material = Material::create([
            'name' => 'ONT Huawei',
            'is_equipment' => true,
        ]);

        Inventory::create([
            'warehouse_id' => $this->almacen->id,
            'material_id' => $material->id,
            'quantity' => 1,
            'unit_of_measurement' => 'Unidades',
            'serial_number' => $serial,
        ]);

        return $material;
    }

    /** PNG 1x1 válido en Data URL, hace de firma en las pruebas. */
    private const FIRMA_DEMO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function datosReporte(array $extra = []): array
    {
        return array_merge([
            'observations_technical' => 'Todo en orden',
            'client_observation' => 'Cliente conforme',
            'solution' => 'Servicio instalado',
            'client_signature' => self::FIRMA_DEMO,
        ], $extra);
    }

    public function test_una_instalacion_sin_material_no_se_procesa(): void
    {
        $orden = $this->orden('Instalación de servicio (creación automática)');

        $respuesta = $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte());

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('error');

        // La orden sigue asignada: no avanzó a Prefinalizada
        $this->assertSame('Asignada', $orden->fresh()->status);
    }

    public function test_una_instalacion_con_equipo_se_procesa_y_descuenta_inventario(): void
    {
        $orden = $this->orden('Instalacion de servicio');
        $material = $this->equipoConSerial('SN-ONT-001');

        $respuesta = $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte([
            'material_id' => [$material->id],
            'quantity' => [1],
            'serial_number' => ['SN-ONT-001'],
        ]));

        $respuesta->assertRedirect(route('technicals_orders.my_technical_orders'));
        $respuesta->assertSessionHas('success');

        $orden->refresh();
        $this->assertSame('Prefinalizada', $orden->status);

        // El equipo se descontó del almacén (la fila del serial se borra)
        $this->assertDatabaseMissing('inventories', [
            'warehouse_id' => $this->almacen->id,
            'serial_number' => 'SN-ONT-001',
        ]);

        // Y el serial quedó vinculado al contrato
        $this->assertSame('SN-ONT-001', $orden->contract->fresh()->cpe_sn);

        // La firma quedó guardada como imagen
        $this->assertNotNull($orden->client_signature);
        $rutaRelativa = str_replace('storage/', '', $orden->client_signature);
        Storage::disk('public')->assertExists($rutaRelativa);
    }

    public function test_no_se_procesa_sin_la_firma_del_cliente(): void
    {
        $orden = $this->orden('Configuraciones');

        // Sin client_signature en el payload
        $datos = $this->datosReporte();
        unset($datos['client_signature']);

        $respuesta = $this->post(route('technicals_orders.process', $orden->id), $datos);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHasErrors('client_signature');
        $this->assertSame('Asignada', $orden->fresh()->status);
    }

    public function test_una_orden_que_no_es_instalacion_no_exige_material(): void
    {
        $orden = $this->orden('Configuraciones');

        $respuesta = $this->post(route('technicals_orders.process', $orden->id), $this->datosReporte());

        $respuesta->assertRedirect(route('technicals_orders.my_technical_orders'));
        $respuesta->assertSessionHas('success');
        $this->assertSame('Prefinalizada', $orden->fresh()->status);
    }
}
