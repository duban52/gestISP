<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Material;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La unidad de medida es del MATERIAL, no de cada operación.
 *
 * EL DEFECTO
 * ----------
 * Se preguntaba en cada movimiento y en cada orden técnica. Eso es
 * preguntar por algo que no cambia, y tiene dos costes:
 *
 * · Se podía contestar MAL. Nada impedía ingresar 200 «Unidades» de
 *   fibra en un almacén y sacar 50 «Metros» de la misma fibra en otro:
 *   el sistema los sumaba igual y las existencias quedaban en una
 *   unidad que no significaba nada.
 * · En las órdenes técnicas era trámite puro: se preguntaba y ni
 *   siquiera se guardaba (`technical_order_materials` no tiene la
 *   columna). Una pregunta de más en un formulario que se rellena de
 *   pie, en casa del cliente.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que la unidad se declare una vez, al crear el material, y que a
 * partir de ahí el sistema la asuma — incluso si alguien manda otra en
 * la petición.
 */
class UnitOfMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private User $admin;
    private Warehouse $almacen;
    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create(['name' => 'Gómez Plata']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->almacen = Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
            'description' => 'Almacén principal',
        ]);

        $this->categoria = Category::create([
            'branch_id' => $this->sucursal->id,
            'name' => 'Cables',
            'description' => 'De prueba',
        ]);
    }

    public function test_al_crear_el_material_se_pide_la_unidad(): void
    {
        $this->post(route('materials.store'), [
            'name' => 'Fibra óptica',
            'category_id' => $this->categoria->id,
            'is_equipment' => 0,
        ])->assertSessionHasErrors('unit_of_measurement');

        $this->assertDatabaseMissing('materials', ['name' => 'Fibra óptica']);
    }

    public function test_la_unidad_queda_guardada_en_el_material(): void
    {
        $this->post(route('materials.store'), [
            'name' => 'Fibra óptica',
            'category_id' => $this->categoria->id,
            'is_equipment' => 0,
            'unit_of_measurement' => 'Metros',
        ])->assertRedirect();

        $this->assertSame('Metros', Material::where('name', 'Fibra óptica')->first()->unit_of_measurement);
    }

    public function test_no_se_admite_una_unidad_inventada(): void
    {
        // Lista cerrada: con texto libre acaban conviviendo «Metros»,
        // «metros» y «mts» para el mismo material, y agrupar existencias
        // deja de funcionar.
        $this->post(route('materials.store'), [
            'name' => 'Fibra óptica',
            'category_id' => $this->categoria->id,
            'is_equipment' => 0,
            'unit_of_measurement' => 'mts',
        ])->assertSessionHasErrors('unit_of_measurement');
    }

    public function test_el_movimiento_usa_la_unidad_del_material_aunque_llegue_otra(): void
    {
        // EL CASO DEL DEFECTO. Se manda «Unidades» para un material que
        // se mide en metros: antes se guardaba tal cual y las
        // existencias quedaban mezcladas. Se ignora.
        $fibra = Material::create([
            'branch_id' => $this->sucursal->id,
            'category_id' => $this->categoria->id,
            'name' => 'Fibra óptica',
            'is_equipment' => false,
            'unit_of_measurement' => 'Metros',
        ]);

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->almacen->id,
            'materials' => [[
                'material_id' => $fibra->id,
                'quantity' => 200,
                'unit_of_measurement' => 'Unidades', // la petición miente
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventories', [
            'material_id' => $fibra->id,
            'unit_of_measurement' => 'Metros',
        ]);

        $this->assertDatabaseHas('material_movements', [
            'material_id' => $fibra->id,
            'unit_of_measurement' => 'Metros',
        ]);
    }

    public function test_el_movimiento_ya_no_necesita_que_se_la_manden(): void
    {
        $fibra = Material::create([
            'branch_id' => $this->sucursal->id,
            'category_id' => $this->categoria->id,
            'name' => 'Fibra óptica',
            'is_equipment' => false,
            'unit_of_measurement' => 'Metros',
        ]);

        // Sin `unit_of_measurement` en la petición: antes era obligatoria.
        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->almacen->id,
            'materials' => [[
                'material_id' => $fibra->id,
                'quantity' => 200,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Metros',
            Inventory::where('material_id', $fibra->id)->first()->unit_of_measurement,
        );
    }

    public function test_el_formulario_de_movimientos_ya_no_pregunta_la_unidad(): void
    {
        // La casilla desaparece; en su lugar la unidad se ENSEÑA junto a
        // la cantidad, tomada del material.
        $respuesta = $this->get(route('movements.index'))->assertOk();

        $respuesta->assertDontSee('id="modal-unit-of-measurement"', false);
        $respuesta->assertSee('id="modal-unit-label"', false);
    }

    public function test_cambiar_la_unidad_no_reescribe_el_historico(): void
    {
        // Decir hoy que la fibra son metros no convierte en metros las
        // «unidades» que alguien ingresó el año pasado: el movimiento es
        // la foto de lo que se hizo entonces.
        $fibra = Material::create([
            'branch_id' => $this->sucursal->id,
            'category_id' => $this->categoria->id,
            'name' => 'Fibra óptica',
            'is_equipment' => false,
            'unit_of_measurement' => 'Unidades',
        ]);

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->almacen->id,
            'materials' => [['material_id' => $fibra->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->put(route('materials.update', $fibra), [
            'name' => 'Fibra óptica',
            'category_id' => $this->categoria->id,
            'is_equipment' => 0,
            'unit_of_measurement' => 'Metros',
        ])->assertRedirect();

        $this->assertSame('Metros', $fibra->fresh()->unit_of_measurement);
        $this->assertDatabaseHas('material_movements', [
            'material_id' => $fibra->id,
            'unit_of_measurement' => 'Unidades',
        ]);
    }
}
