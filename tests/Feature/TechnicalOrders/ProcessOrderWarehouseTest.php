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
 * DE QUÉ ALMACÉN SALE EL MATERIAL DE UNA ORDEN.
 *
 * EL DEFECTO
 * ----------
 * El almacén se resolvía con `Auth::id()`: el de quien estuviera
 * logueado. Pero quien cierra la orden no es siempre el técnico que la
 * ejecutó —la oficina también la cierra—, y como
 * `WarehouseController::store()` deja `user_id = Auth::id()` cuando no
 * se elige dueño, el almacén PRINCIPAL queda a nombre de quien lo creó.
 *
 * Resultado: una instalación descargaba del almacén principal mientras
 * el del técnico seguía cuadrando con equipos que ya no estaban en la
 * furgoneta. El inventario mentía por los dos lados a la vez.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que el material salga del almacén del técnico ASIGNADO, lo cierre
 * quien lo cierre; y que la disponibilidad que se ENSEÑA sea la de ese
 * mismo almacén, porque enseñar una y descontar de otra es lo que hace
 * que el técnico elija material que al procesar no aparece.
 */
class ProcessOrderWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $tecnico;
    private User $oficina;
    private Warehouse $almacenDelTecnico;
    private Warehouse $almacenPrincipal;
    private Plan $plan;
    private Role $rol;

    /** PNG 1x1 válido en Data URL, hace de firma en las pruebas. */
    private const FIRMA_DEMO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->tecnico = $this->usuario();
        $this->oficina = $this->usuario();

        // EL ESCENARIO QUE REPRODUCE EL FALLO: el almacén principal está
        // a nombre de la oficina —que es lo que pasa en cuanto alguien
        // lo crea sin elegir dueño— y el técnico tiene el suyo.
        $this->almacenPrincipal = Warehouse::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->oficina->id,
            'description' => 'Almacén principal',
        ]);

        $this->almacenDelTecnico = Warehouse::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
            'description' => 'Furgoneta del técnico',
        ]);

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->oficina->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function usuario(): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        return $usuario;
    }

    private function comoLaOficina(): self
    {
        $this->actingAs($this->oficina)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);

        return $this;
    }

    private function orden(?User $asignado = null): TechnicalOrder
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->oficina->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Pendiente',
            'user_id' => $this->oficina->id,
        ]);

        return TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $asignado?->id,
            'created_by' => $this->oficina->id,
            'type' => 'Servicio',
            'detail' => 'Instalacion de servicio',
            'status' => 'Asignada',
            'initial_comment' => 'Orden de prueba',
        ]);
    }

    /** Una ONT con el MISMO serial en los dos almacenes. */
    private function ontEnLosDosAlmacenes(string $serial): Material
    {
        // CON `branch_id`: `Material` lleva el scope global de empresa
        // y sin sucursal el `company_id` queda nulo, asi que el catalogo
        // sale VACIO y la prueba mediria otra cosa.
        $material = Material::create([
            'branch_id' => $this->branch->id,
            'name' => 'ONT Huawei',
            'is_equipment' => true,
        ]);

        foreach ([$this->almacenPrincipal, $this->almacenDelTecnico] as $almacen) {
            Inventory::create([
                'warehouse_id' => $almacen->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'unit_of_measurement' => 'Unidades',
                'serial_number' => $serial,
            ]);
        }

        return $material;
    }

    private function datosReporte(array $extra = []): array
    {
        return array_merge([
            'observations_technical' => 'Todo en orden',
            'client_observation' => 'Cliente conforme',
            'solution' => 'Servicio instalado',
            'client_signature' => self::FIRMA_DEMO,
        ], $extra);
    }

    public function test_el_material_sale_del_almacen_del_tecnico_aunque_cierre_la_oficina(): void
    {
        // EL CASO DEL DEFECTO. El mismo serial está en los dos
        // almacenes, así que la prueba solo pasa si se descuenta del
        // correcto — no basta con que «se descuente algo».
        $orden = $this->orden($this->tecnico);
        $material = $this->ontEnLosDosAlmacenes('SN-ONT-001');

        $respuesta = $this->comoLaOficina()->post(
            route('technicals_orders.process', $orden->id),
            $this->datosReporte([
                'material_id' => [$material->id],
                'quantity' => [1],
                'serial_number' => ['SN-ONT-001'],
            ]),
        );

        $respuesta->assertSessionHas('success');
        $this->assertSame('Prefinalizada', $orden->fresh()->status);

        // Salió de la furgoneta del técnico...
        $this->assertDatabaseMissing('inventories', [
            'warehouse_id' => $this->almacenDelTecnico->id,
            'serial_number' => 'SN-ONT-001',
        ]);

        // ...y el principal quedó INTACTO.
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->almacenPrincipal->id,
            'serial_number' => 'SN-ONT-001',
        ]);
    }

    public function test_la_pantalla_ensena_la_disponibilidad_del_tecnico(): void
    {
        // La disponibilidad que se ve y la que se descuenta tienen que
        // ser la misma. Si la oficina viera su propio stock, elegiría
        // material que en la furgoneta no está.
        $orden = $this->orden($this->tecnico);

        $soloDelPrincipal = Material::create([
            'branch_id' => $this->branch->id,
            'name' => 'Cable que solo hay en el principal',
            'is_equipment' => false,
        ]);
        Inventory::create([
            'warehouse_id' => $this->almacenPrincipal->id,
            'material_id' => $soloDelPrincipal->id,
            'quantity' => 100,
            'unit_of_measurement' => 'Metros',
        ]);

        $soloDelTecnico = Material::create([
            'branch_id' => $this->branch->id,
            'name' => 'Cable que lleva el tecnico',
            'is_equipment' => false,
        ]);
        Inventory::create([
            'warehouse_id' => $this->almacenDelTecnico->id,
            'material_id' => $soloDelTecnico->id,
            'quantity' => 30,
            'unit_of_measurement' => 'Metros',
        ]);

        $respuesta = $this->comoLaOficina()->get(route('technicals_orders.show', $orden->id));

        $respuesta->assertOk();
        $respuesta->assertSee('Cable que lleva el tecnico', false);
        $respuesta->assertDontSee('Cable que solo hay en el principal', false);
    }

    public function test_sin_almacen_del_tecnico_no_se_descuenta_nada(): void
    {
        // Antes esto descargaba del almacén de la oficina. Ahora se
        // niega: es preferible que la orden no cierre a que el stock de
        // otro almacén se mueva a espaldas de su responsable.
        $sinAlmacen = $this->usuario();
        $orden = $this->orden($sinAlmacen);
        $material = $this->ontEnLosDosAlmacenes('SN-ONT-002');

        $respuesta = $this->comoLaOficina()->post(
            route('technicals_orders.process', $orden->id),
            $this->datosReporte([
                'material_id' => [$material->id],
                'quantity' => [1],
                'serial_number' => ['SN-ONT-002'],
            ]),
        );

        $respuesta->assertSessionHas('error');
        $this->assertSame('Asignada', $orden->fresh()->status);

        // Ni un almacén ni el otro se tocaron.
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->almacenPrincipal->id,
            'serial_number' => 'SN-ONT-002',
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->almacenDelTecnico->id,
            'serial_number' => 'SN-ONT-002',
        ]);
    }

    // ==================== Varios almacenes por técnico ====================
    //
    // Un técnico puede tener más de uno: la furgoneta y un stock aparte.
    // Antes el sistema elegía el más antiguo, o sea ADIVINABA, y el
    // material se descontaba de donde nadie había dicho.

    /** Un segundo almacén del mismo técnico. */
    private function segundoAlmacenDelTecnico(): Warehouse
    {
        return Warehouse::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->tecnico->id,
            'created_by' => $this->oficina->id,
            'description' => 'Stock de casa del técnico',
        ]);
    }

    public function test_con_dos_almacenes_hay_que_decir_de_cual_sale(): void
    {
        $segundo = $this->segundoAlmacenDelTecnico();
        $orden = $this->orden($this->tecnico);
        $material = $this->ontEnLosDosAlmacenes('SN-ONT-010');

        // Y también en el segundo, para que ninguno sea «el evidente».
        Inventory::create([
            'warehouse_id' => $segundo->id,
            'material_id' => $material->id,
            'quantity' => 1,
            'unit_of_measurement' => 'Unidades',
            'serial_number' => 'SN-ONT-010',
        ]);

        $respuesta = $this->comoLaOficina()->post(
            route('technicals_orders.process', $orden->id),
            $this->datosReporte([
                'material_id' => [$material->id],
                'quantity' => [1],
                'serial_number' => ['SN-ONT-010'],
                // Sin `warehouse_id`: antes se resolvía solo, por el más
                // antiguo. Ahora se niega en vez de adivinar.
            ]),
        );

        $respuesta->assertSessionHas('error');
        $this->assertSame('Asignada', $orden->fresh()->status);

        foreach ([$this->almacenDelTecnico, $segundo] as $almacen) {
            $this->assertDatabaseHas('inventories', [
                'warehouse_id' => $almacen->id,
                'serial_number' => 'SN-ONT-010',
            ]);
        }
    }

    public function test_se_descuenta_del_almacen_elegido(): void
    {
        $segundo = $this->segundoAlmacenDelTecnico();
        $orden = $this->orden($this->tecnico);
        $material = $this->ontEnLosDosAlmacenes('SN-ONT-011');

        Inventory::create([
            'warehouse_id' => $segundo->id,
            'material_id' => $material->id,
            'quantity' => 1,
            'unit_of_measurement' => 'Unidades',
            'serial_number' => 'SN-ONT-011',
        ]);

        $this->comoLaOficina()->post(
            route('technicals_orders.process', $orden->id),
            $this->datosReporte([
                'warehouse_id' => $segundo->id,
                'material_id' => [$material->id],
                'quantity' => [1],
                'serial_number' => ['SN-ONT-011'],
            ]),
        )->assertSessionHas('success');

        // Salió del elegido...
        $this->assertDatabaseMissing('inventories', [
            'warehouse_id' => $segundo->id,
            'serial_number' => 'SN-ONT-011',
        ]);

        // ...y el otro del mismo técnico quedó intacto.
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->almacenDelTecnico->id,
            'serial_number' => 'SN-ONT-011',
        ]);
    }

    public function test_no_se_puede_elegir_el_almacen_de_otro(): void
    {
        // La petición la manipula cualquiera. Sin esta comprobación
        // bastaba con cambiar un número para descontar del almacén
        // principal, o del de otro técnico.
        $this->segundoAlmacenDelTecnico();
        $orden = $this->orden($this->tecnico);
        $material = $this->ontEnLosDosAlmacenes('SN-ONT-012');

        $respuesta = $this->comoLaOficina()->post(
            route('technicals_orders.process', $orden->id),
            $this->datosReporte([
                'warehouse_id' => $this->almacenPrincipal->id, // no es suyo
                'material_id' => [$material->id],
                'quantity' => [1],
                'serial_number' => ['SN-ONT-012'],
            ]),
        );

        $respuesta->assertSessionHas('error');
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->almacenPrincipal->id,
            'serial_number' => 'SN-ONT-012',
        ]);
    }

    public function test_con_un_solo_almacen_no_se_pregunta(): void
    {
        // Sería una pregunta con una única respuesta posible.
        $orden = $this->orden($this->tecnico);

        $respuesta = $this->comoLaOficina()
            ->get(route('technicals_orders.show', $orden->id))
            ->assertOk();

        $respuesta->assertDontSee('Almacén del que sale el material', false);
        $respuesta->assertSee('Furgoneta del técnico', false);
    }

    public function test_con_varios_la_pantalla_obliga_a_elegir(): void
    {
        $this->segundoAlmacenDelTecnico();
        $orden = $this->orden($this->tecnico);

        $respuesta = $this->comoLaOficina()
            ->get(route('technicals_orders.show', $orden->id))
            ->assertOk();

        $respuesta->assertSee('Almacén del que sale el material', false);
        $respuesta->assertSee('Furgoneta del técnico', false);
        $respuesta->assertSee('Stock de casa del técnico', false);
    }
}
