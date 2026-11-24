<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quién ve qué almacén.
 *
 * LA REGLA
 * --------
 * Superadministrador y administrador ven todos los almacenes de sus
 * sucursales. Cualquier otro rol ve SOLO los almacenes de los que es
 * dueño: ni los de sus compañeros, ni la bodega general.
 *
 * Vive en un único sitio, `Warehouse::scopeVisibles()`, y estas
 * pruebas recorren TODAS las puertas por las que se ve un almacén o
 * su inventario: el listado, la ficha, el PDF, el desplegable de
 * movimientos, los dos endpoints JSON de existencias, el registro de
 * un movimiento, el historial y el detalle de una operación.
 *
 * LO QUE YA ESTABA ABIERTO ANTES DE ESTO
 * --------------------------------------
 * La ficha de un almacén y su PDF no comprobaban NADA: cambiando el
 * número en la URL se veía el inventario de cualquier almacén, incluso
 * de otra sucursal. El Excel de movimientos, en panel consolidado,
 * exportaba los de todas las sucursales.
 */
class WarehouseVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private User $admin;
    private User $tecnico;
    private User $otroTecnico;
    private Role $rolTecnico;
    private Warehouse $bodega;
    private Warehouse $furgonetaPropia;
    private Warehouse $furgonetaAjena;
    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create();

        $this->admin = $this->usuarioCon('administrador');

        // El seeder no le da «Ver inventarios» al administrador. Se le
        // da aquí: lo que se prueba es quién ve QUÉ almacén, no el
        // reparto de permisos por defecto, que se edita en pantalla.
        Role::where('name', 'administrador')->firstOrFail()
            ->givePermissionTo(Permission::firstOrCreate(['name' => 'warehouses.show', 'guard_name' => 'web']));

        // El técnico, con los permisos de almacén que se le den en la
        // pantalla de roles: la regla tiene que cumplirse aunque el
        // permiso lo tenga.
        $this->rolTecnico = Role::where('name', 'tecnico')->firstOrFail();
        $this->rolTecnico->givePermissionTo(collect([
            'warehouses.index', 'warehouses.show', 'warehouse.pdf',
            'movements.index', 'movements.create', 'movements.history',
            'movements.query_sn', 'movements.material_quantity',
        ])->map(fn ($p) => Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web'])));

        $this->tecnico = $this->usuarioCon('tecnico', '3000000002');
        $this->otroTecnico = $this->usuarioCon('tecnico', '3000000003');

        $this->bodega = $this->almacen(null, 'Bodega general');
        $this->furgonetaPropia = $this->almacen($this->tecnico, 'Furgoneta propia');
        $this->furgonetaAjena = $this->almacen($this->otroTecnico, 'Furgoneta ajena');

        $categoria = Category::create(['branch_id' => $this->sucursal->id, 'name' => 'Cables', 'description' => 'x']);
        $this->material = Material::create([
            'branch_id' => $this->sucursal->id,
            'category_id' => $categoria->id,
            'name' => 'Fibra drop',
            'is_equipment' => false,
            'unit_of_measurement' => 'Metros',
        ]);

        foreach ([$this->bodega, $this->furgonetaPropia, $this->furgonetaAjena] as $almacen) {
            Inventory::create([
                'warehouse_id' => $almacen->id,
                'material_id' => $this->material->id,
                'quantity' => 100,
                'unit_of_measurement' => 'Metros',
            ]);
        }
    }

    private function usuarioCon(string $rol, string $telefono = '3000000001'): User
    {
        $usuario = User::factory()->create(['number_phone' => $telefono]);
        $role = Role::where('name', $rol)->firstOrFail();
        $usuario->assignRole($role);
        $usuario->branches()->attach($this->sucursal->id, ['role_id' => $role->id]);

        return $usuario;
    }

    private function almacen(?User $dueno, string $nombre): Warehouse
    {
        return Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $dueno?->id,
            'description' => $nombre,
        ]);
    }

    private function entrarComo(User $usuario): void
    {
        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $usuario->roles->first()->id,
        ]);
    }

    // ==================== El listado ====================

    public function test_el_administrador_ve_todos_los_almacenes(): void
    {
        $this->entrarComo($this->admin);

        $this->get(route('warehouses.index'))
            ->assertOk()
            ->assertSee('Bodega general')
            ->assertSee('Furgoneta propia')
            ->assertSee('Furgoneta ajena');
    }

    public function test_el_tecnico_solo_ve_los_suyos(): void
    {
        $this->entrarComo($this->tecnico);

        $this->get(route('warehouses.index'))
            ->assertOk()
            ->assertSee('Furgoneta propia')
            ->assertDontSee('Furgoneta ajena')
            ->assertDontSee('Bodega general');
    }

    /**
     * El rol ACTIVO manda, no todos los roles del usuario.
     *
     * Alguien que es administrador en otra sede y técnico aquí trabaja
     * como técnico mientras esté aquí.
     */
    public function test_manda_el_rol_con_el_que_se_entro(): void
    {
        $this->tecnico->assignRole('administrador');

        $this->actingAs($this->tecnico)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $this->rolTecnico->id,
        ]);

        $this->get(route('warehouses.index'))
            ->assertOk()
            ->assertDontSee('Bodega general');
    }

    // ==================== La ficha y el PDF: la URL no cuela ====================

    /** @dataProvider rutasDeUnAlmacen */
    public function test_un_almacen_ajeno_no_se_abre_por_la_url(string $ruta): void
    {
        $this->entrarComo($this->tecnico);

        $this->get(route($ruta, $this->furgonetaAjena))->assertForbidden();
        $this->get(route($ruta, $this->bodega))->assertForbidden();
    }

    /** @dataProvider rutasDeUnAlmacen */
    public function test_el_propio_si_se_abre(string $ruta): void
    {
        $this->entrarComo($this->tecnico);

        $this->get(route($ruta, $this->furgonetaPropia))->assertOk();
    }

    public static function rutasDeUnAlmacen(): array
    {
        return [
            'ficha con el inventario' => ['warehouses.show'],
            'inventario en PDF' => ['warehouse.pdf'],
        ];
    }

    public function test_el_administrador_abre_cualquiera(): void
    {
        $this->entrarComo($this->admin);

        $this->get(route('warehouses.show', $this->furgonetaAjena))->assertOk();
        $this->get(route('warehouses.show', $this->bodega))->assertOk();
    }

    // ==================== Existencias por JSON ====================

    public function test_no_se_leen_las_existencias_de_un_almacen_ajeno(): void
    {
        $this->entrarComo($this->tecnico);

        $this->getJson(route('movements.material_quantity', [$this->bodega->id, $this->material->id]))
            ->assertForbidden();
        $this->getJson(route('movements.query_sn', [$this->furgonetaAjena->id, $this->material->id]))
            ->assertForbidden();

        $this->getJson(route('movements.material_quantity', [$this->furgonetaPropia->id, $this->material->id]))
            ->assertOk()
            ->assertJson(['quantity' => 100]);
    }

    // ==================== Registrar movimientos ====================

    public function test_el_desplegable_de_origen_solo_ofrece_los_suyos(): void
    {
        $this->entrarComo($this->tecnico);

        $html = $this->get(route('movements.index'))->assertOk()->getContent();

        $origen = $this->entre($html, 'id="warehouse_origin_id"', '</select>');

        $this->assertStringContainsString('Furgoneta propia', $origen);
        $this->assertStringNotContainsString('Bodega general', $origen);
        $this->assertStringNotContainsString('Furgoneta ajena', $origen);
    }

    public function test_no_se_puede_sacar_de_un_almacen_ajeno(): void
    {
        // El desplegable no lo ofrece, pero el id viaja en la petición.
        $this->entrarComo($this->tecnico);

        $this->post(route('movements.store'), [
            'type' => 'Salida',
            'reason' => 'Instalación',
            'warehouse_origin_id' => $this->bodega->id,
            'materials' => [['material_id' => $this->material->id, 'quantity' => 10]],
        ])->assertSessionHasErrors('warehouse_origin_id');

        $this->assertSame(100.0, (float) Inventory::where('warehouse_id', $this->bodega->id)->value('quantity'));
    }

    /**
     * Devolver material a la bodega SÍ se puede.
     *
     * El destino de un traslado puede ser cualquier almacén de la
     * sucursal: si no, el técnico no tendría cómo devolver lo que le
     * sobró. Lo que no ve es lo que hay dentro.
     */
    public function test_se_puede_devolver_material_a_la_bodega(): void
    {
        $this->entrarComo($this->tecnico);

        $this->post(route('movements.store'), [
            'type' => 'Transferencia',
            'reason' => 'Devolución',
            'warehouse_origin_id' => $this->furgonetaPropia->id,
            'warehouse_destination_id' => $this->bodega->id,
            'materials' => [['material_id' => $this->material->id, 'quantity' => 30]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(130.0, (float) Inventory::where('warehouse_id', $this->bodega->id)->sum('quantity'));
    }

    public function test_no_se_puede_ingresar_material_en_un_almacen_ajeno(): void
    {
        // Una ENTRADA no es un traslado: crea existencias en el destino
        // sin sacarlas de ningún sitio.
        $this->entrarComo($this->tecnico);

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->bodega->id,
            'materials' => [['material_id' => $this->material->id, 'quantity' => 50]],
        ])->assertSessionHasErrors('warehouse_destination_id');
    }

    // ==================== Historial ====================

    public function test_el_historial_del_tecnico_solo_trae_lo_que_toca_sus_almacenes(): void
    {
        $this->entrarComo($this->admin);

        $suyo = $this->movimiento($this->bodega, $this->furgonetaPropia, 'Para el técnico propio');
        $ajeno = $this->movimiento($this->bodega, $this->furgonetaAjena, 'Para el otro técnico');

        $this->entrarComo($this->tecnico);

        $this->get(route('movements.history', ['start_date' => now()->subDay()->toDateString()]))
            ->assertOk()
            ->assertSee('Para el técnico propio')
            ->assertDontSee('Para el otro técnico');

        $this->get(route('movements.operation', $suyo->operation_id))->assertOk();
        $this->get(route('movements.operation', $ajeno->operation_id))->assertForbidden();
    }

    private function movimiento(Warehouse $origen, Warehouse $destino, string $observacion): MaterialMovement
    {
        $this->post(route('movements.store'), [
            'type' => 'Transferencia',
            'reason' => 'Asignación',
            'warehouse_origin_id' => $origen->id,
            'warehouse_destination_id' => $destino->id,
            'observations' => $observacion,
            'materials' => [['material_id' => $this->material->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        return MaterialMovement::where('observations', $observacion)->firstOrFail();
    }

    /** El trozo de HTML entre dos marcas: un desplegable concreto. */
    private function entre(string $html, string $desde, string $hasta): string
    {
        $inicio = strpos($html, $desde);
        $this->assertNotFalse($inicio, "No se encontró {$desde}");

        return substr($html, $inicio, strpos($html, $hasta, $inicio) - $inicio);
    }
}
