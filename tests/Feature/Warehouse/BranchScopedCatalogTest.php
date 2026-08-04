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
 * Catálogo de almacén separado por sucursal.
 *
 * El fallo que corrige: `categories` y `materials` nacieron sin
 * `branch_id`, así que el catálogo era global. Una sede creaba un
 * material y aparecía en todas, aunque su inventario estuviera solo
 * en la primera.
 *
 * Filtrar los listados no basta: las rutas reciben el id por la URL,
 * de modo que también hay que cortar el acceso directo. Buena parte
 * de estas pruebas comprueba justamente eso.
 */
class BranchScopedCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private Branch $otraSucursal;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create(['name' => 'Gómez Plata']);
        $this->otraSucursal = Branch::factory()->create(['name' => 'Yarumal']);

        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);
        $this->admin->branches()->attach($this->otraSucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $rol->id,
        ]);
    }

    private function categoria(Branch $sucursal, string $nombre): Category
    {
        return Category::create([
            'branch_id' => $sucursal->id,
            'name' => $nombre,
            'description' => 'Categoría de prueba',
        ]);
    }

    private function material(Branch $sucursal, string $nombre, ?Category $categoria = null): Material
    {
        return Material::create([
            'branch_id' => $sucursal->id,
            'name' => $nombre,
            'category_id' => ($categoria ?? $this->categoria($sucursal, 'Cat ' . $nombre))->id,
            'is_equipment' => false,
        ]);
    }

    // ==================== Listados ====================

    public function test_el_catalogo_de_materiales_solo_muestra_los_de_la_sucursal(): void
    {
        $propio = $this->material($this->sucursal, 'Fibra DROP');
        $ajeno = $this->material($this->otraSucursal, 'Cable ajeno');

        $this->get(route('materials.index'))
            ->assertOk()
            ->assertSee('Fibra DROP')
            ->assertDontSee('Cable ajeno');

        $this->assertNotNull($propio->branch_id);
        $this->assertNotSame($propio->branch_id, $ajeno->branch_id);
    }

    public function test_las_categorias_solo_se_ven_en_su_sucursal(): void
    {
        $this->categoria($this->sucursal, 'Cables propios');
        $this->categoria($this->otraSucursal, 'Cables ajenos');

        $this->get(route('categories.index'))
            ->assertOk()
            ->assertSee('Cables propios')
            ->assertDontSee('Cables ajenos');
    }

    public function test_el_formulario_de_material_solo_ofrece_categorias_de_la_sucursal(): void
    {
        $this->categoria($this->sucursal, 'Categoría propia');
        $this->categoria($this->otraSucursal, 'Categoría ajena');

        $this->get(route('materials.create'))
            ->assertOk()
            ->assertSee('Categoría propia')
            ->assertDontSee('Categoría ajena');
    }

    public function test_cambiar_de_sucursal_cambia_el_catalogo(): void
    {
        $this->material($this->sucursal, 'Solo aquí');
        $this->material($this->otraSucursal, 'Solo allá');

        $this->withSession(['branch_id' => (string) $this->otraSucursal->id])
            ->get(route('materials.index'))
            ->assertOk()
            ->assertSee('Solo allá')
            ->assertDontSee('Solo aquí');
    }

    // ==================== Creación ====================

    public function test_lo_que_se_crea_queda_en_la_sucursal_activa(): void
    {
        $categoria = $this->categoria($this->sucursal, 'Consumibles');

        $this->post(route('materials.store'), [
            'name' => 'Conector SC/APC',
            'category_id' => $categoria->id,
            'is_equipment' => 0,
        ])->assertRedirect();

        $this->assertDatabaseHas('materials', [
            'name' => 'Conector SC/APC',
            'branch_id' => $this->sucursal->id,
        ]);
    }

    public function test_no_se_puede_crear_un_material_bajo_una_categoria_ajena(): void
    {
        $ajena = $this->categoria($this->otraSucursal, 'Categoría ajena');

        // Con un simple exists: bastaba con enviar el id de otra sede
        $this->post(route('materials.store'), [
            'name' => 'Material colado',
            'category_id' => $ajena->id,
            'is_equipment' => 0,
        ])->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('materials', ['name' => 'Material colado']);
    }

    public function test_no_se_repite_el_nombre_de_categoria_dentro_de_la_sucursal(): void
    {
        $this->categoria($this->sucursal, 'Cables');

        $this->post(route('categories.store'), [
            'name' => 'Cables',
            'description' => 'Otra vez',
        ])->assertSessionHasErrors('name');
    }

    public function test_dos_sucursales_si_pueden_tener_la_misma_categoria(): void
    {
        $this->categoria($this->otraSucursal, 'Cables');

        // El nombre es único DENTRO de la sucursal, no en todo el sistema
        $this->post(route('categories.store'), [
            'name' => 'Cables',
            'description' => 'La de esta sede',
        ])->assertRedirect();

        $this->assertSame(2, Category::where('name', 'Cables')->count());
    }

    // ============ Acceso directo por la URL ============

    public function test_no_se_puede_editar_un_material_de_otra_sucursal(): void
    {
        $ajeno = $this->material($this->otraSucursal, 'Material ajeno');

        // El enlace nunca lo ofrece, pero la ruta acepta cualquier id
        $this->get(route('materials.edit', $ajeno))->assertStatus(403);
        $this->delete(route('materials.destroy', $ajeno))->assertStatus(403);

        $this->assertDatabaseHas('materials', ['id' => $ajeno->id]);
    }

    public function test_no_se_puede_editar_una_categoria_de_otra_sucursal(): void
    {
        $ajena = $this->categoria($this->otraSucursal, 'Categoría ajena');

        $this->get(route('categories.edit', $ajena))->assertStatus(403);
        $this->delete(route('categories.destroy', $ajena))->assertStatus(403);
    }

    public function test_no_se_borra_una_categoria_con_materiales(): void
    {
        $categoria = $this->categoria($this->sucursal, 'Con materiales');
        $this->material($this->sucursal, 'Algo', $categoria);

        // Borrarla dejaría los materiales sin clasificar (SET NULL)
        $this->delete(route('categories.destroy', $categoria))
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['id' => $categoria->id]);
    }

    // ==================== Movimientos ====================

    public function test_el_formulario_de_movimientos_solo_ofrece_material_de_la_sucursal(): void
    {
        $this->material($this->sucursal, 'Fibra propia');
        $this->material($this->otraSucursal, 'Fibra ajena');

        $this->get(route('movements.index'))
            ->assertOk()
            ->assertSee('Fibra propia')
            ->assertDontSee('Fibra ajena');
    }

    public function test_no_se_puede_mover_material_de_otra_sucursal(): void
    {
        $ajeno = $this->material($this->otraSucursal, 'Material ajeno');

        $almacen = Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
            'description' => 'Almacén principal',
        ]);

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $almacen->id,
            'materials' => [[
                'material_id' => $ajeno->id,
                'quantity' => 5,
                'unit_of_measurement' => 'Unidades',
            ]],
        ])->assertSessionHasErrors('materials.0.material_id');

        $this->assertSame(0, Inventory::count());
    }

    public function test_no_se_consulta_el_stock_de_un_almacen_ajeno(): void
    {
        $material = $this->material($this->sucursal, 'Fibra');

        $ajeno = Warehouse::create([
            'branch_id' => $this->otraSucursal->id,
            'user_id' => $this->admin->id,
            'description' => 'Bodega de Yarumal',
        ]);

        // Son endpoints JSON con el id en la URL: sin el corte,
        // cambiar el número bastaba para leer existencias ajenas.
        $this->get(route('movements.material_quantity', [$ajeno->id, $material->id]))
            ->assertStatus(403);

        $this->get(route('movements.query_sn', [$ajeno->id, $material->id]))
            ->assertStatus(403);
    }

    public function test_registrar_un_movimiento_queda_en_la_trazabilidad(): void
    {
        $material = $this->material($this->sucursal, 'Fibra DROP');

        $almacen = Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
            'description' => 'Almacén principal',
        ]);

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $almacen->id,
            'materials' => [[
                'material_id' => $material->id,
                'quantity' => 100,
                'unit_of_measurement' => 'Metros',
            ]],
        ])->assertRedirect();

        // Una sola entrada que responde quién movió qué, de dónde y
        // por qué — las filas sueltas de inventario no lo cuentan.
        $this->assertDatabaseHas('audits', ['action' => 'movements.registered']);

        $registro = \App\Models\Audit::where('action', 'movements.registered')->firstOrFail();

        $this->assertStringContainsString('entrada', $registro->description);
        $this->assertStringContainsString('Almacén principal', $registro->description);
        $this->assertSame('inventario', $registro->category);
    }
}
