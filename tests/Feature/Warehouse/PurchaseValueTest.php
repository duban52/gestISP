<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Material;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryCosting;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El valor unitario de compra del material.
 *
 * PARA QUÉ ESTÁ ESTO
 * ------------------
 * Para poder decir cuánto vale, en dinero, lo que hay en un almacén.
 * Es una cifra que se mira para decidir, así que tiene que ser fiel: un
 * inventario valorado que se equivoca es peor que no tenerlo, porque
 * nadie duda de un número.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que los equipos lleven su costo EXACTO (una fila por serial).
 * 2. Que los consumibles lleven promedio ponderado, porque todas las
 *    compras se acumulan en una sola fila.
 * 3. Que una entrada SIN precio no abarate lo que ya había. El nulo es
 *    «no se sabe», no «gratis».
 * 4. Que trasladar material no cambie lo que costó. Si el destino lo
 *    valorara a cero, mover cable de una bodega a otra haría
 *    desaparecer dinero del inventario sin que nadie gastara nada.
 * 5. Que los costos NO se vean sin el permiso `materials.costs`.
 */
class PurchaseValueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private User $admin;
    private Role $rol;
    private Warehouse $principal;
    private Warehouse $furgoneta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create(['name' => 'Gómez Plata']);
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($this->rol);
        $this->admin->branches()->attach($this->sucursal->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $this->rol->id,
        ]);

        $this->principal = Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
            'description' => 'Almacén principal',
        ]);

        $this->furgoneta = Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
            'description' => 'Furgoneta',
        ]);
    }

    private function material(string $nombre, bool $equipo, ?float $referencia = null): Material
    {
        $categoria = Category::create([
            'branch_id' => $this->sucursal->id,
            'name' => 'Categoría ' . $nombre,
            'description' => 'De prueba',
        ]);

        return Material::create([
            'branch_id' => $this->sucursal->id,
            'category_id' => $categoria->id,
            'name' => $nombre,
            'is_equipment' => $equipo,
            'unit_of_measurement' => $equipo ? 'Unidades' : 'Metros',
            'purchase_unit_value' => $referencia,
        ]);
    }

    /** Registra un movimiento por la ruta real, como el formulario. */
    private function mover(array $datos): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('movements.store'), $datos);
    }

    private function entrada(Material $material, float $cantidad, ?float $valor, ?Warehouse $destino = null)
    {
        return $this->mover([
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => ($destino ?? $this->principal)->id,
            'materials' => [array_filter([
                'material_id' => $material->id,
                'quantity' => $cantidad,
                'unit_of_measurement' => 'Metros',
                'purchase_unit_value' => $valor,
            ], fn ($v) => $v !== null)],
        ]);
    }

    private function costoEn(Warehouse $almacen, Material $material): ?float
    {
        $fila = Inventory::where('warehouse_id', $almacen->id)
            ->where('material_id', $material->id)
            ->first();

        return $fila?->purchase_unit_value !== null ? (float) $fila->purchase_unit_value : null;
    }

    // ==================== La aritmética ====================

    public function test_el_promedio_ponderado_reparte_el_costo_real(): void
    {
        // 200 m a 1.200 y 300 m a 1.400 son 500 m que costaron 660.000:
        // 1.320 cada metro. Quedarse con el último precio (1.400) haría
        // que los 200 m viejos valieran de golpe lo que no costaron.
        $costos = app(InventoryCosting::class);

        $this->assertSame(1320.0, $costos->promedioPonderado(1200.0, 200, 1400.0, 300));
    }

    public function test_una_entrada_sin_precio_no_abarata_lo_que_habia(): void
    {
        // El nulo es «no se sabe», no «gratis». Tomarlo como cero
        // rebajaría el promedio y el inventario valdría menos de lo que
        // costó — la forma silenciosa de que estas cifras dejen de
        // servir.
        $costos = app(InventoryCosting::class);

        $this->assertSame(1200.0, $costos->promedioPonderado(1200.0, 200, null, 300));
    }

    public function test_la_primera_compra_con_precio_fija_el_costo(): void
    {
        $costos = app(InventoryCosting::class);

        $this->assertSame(1400.0, $costos->promedioPonderado(null, 200, 1400.0, 300));
        $this->assertSame(1400.0, $costos->promedioPonderado(null, 0, 1400.0, 300));
    }

    public function test_lo_que_no_tiene_precio_no_se_totaliza_como_cero(): void
    {
        // Un almacén con 500 m de cable sin precio no vale cero pesos:
        // vale una cifra que no se conoce, y la pantalla tiene que poder
        // decir eso en vez de presentar un total incompleto como bueno.
        $resumen = app(InventoryCosting::class)->totalizar([
            ['cantidad' => 10, 'costo' => 1000.0],
            ['cantidad' => 500, 'costo' => null],
        ]);

        $this->assertSame(10000.0, $resumen['total']);
        $this->assertSame(1, $resumen['sin_valorar']);
    }

    // ==================== Las entradas ====================

    public function test_un_equipo_guarda_su_costo_exacto(): void
    {
        // Una fila por serial: cada ONT vale lo que costó ELLA, no un
        // promedio.
        $ont = $this->material('ONT Huawei', equipo: true);

        $this->mover([
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'materials' => [[
                'material_id' => $ont->id,
                'quantity' => 1,
                'unit_of_measurement' => 'Unidades',
                'serial_numbers' => ['SN-001'],
                'purchase_unit_value' => 180000,
            ]],
        ])->assertSessionHasNoErrors();

        $this->mover([
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'materials' => [[
                'material_id' => $ont->id,
                'quantity' => 1,
                'unit_of_measurement' => 'Unidades',
                'serial_numbers' => ['SN-002'],
                'purchase_unit_value' => 210000,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventories', [
            'serial_number' => 'SN-001',
            'purchase_unit_value' => 180000.00,
        ]);
        $this->assertDatabaseHas('inventories', [
            'serial_number' => 'SN-002',
            'purchase_unit_value' => 210000.00,
        ]);
    }

    public function test_dos_compras_de_consumible_dan_el_promedio_ponderado(): void
    {
        $cable = $this->material('Fibra DROP', equipo: false);

        $this->entrada($cable, 200, 1200)->assertSessionHasNoErrors();
        $this->assertSame(1200.0, $this->costoEn($this->principal, $cable));

        $this->entrada($cable, 300, 1400)->assertSessionHasNoErrors();

        $this->assertSame(1320.0, $this->costoEn($this->principal, $cable));
        $this->assertSame(500, (int) Inventory::where('material_id', $cable->id)->first()->quantity);
    }

    public function test_una_entrada_en_blanco_conserva_el_costo(): void
    {
        // El formulario PROPONE el valor del catálogo; dejarlo vacío es
        // un «no se sabe» deliberado y no puede inventarse un precio ni
        // rebajar el que ya había.
        $cable = $this->material('Fibra DROP', equipo: false, referencia: 9999);

        $this->entrada($cable, 200, 1200)->assertSessionHasNoErrors();
        $this->entrada($cable, 300, null)->assertSessionHasNoErrors();

        $this->assertSame(1200.0, $this->costoEn($this->principal, $cable));
    }

    public function test_el_movimiento_guarda_lo_que_se_pago(): void
    {
        // El histórico: la fila de inventario solo guarda el RESULTADO
        // del promedio, así que sin esto no hay forma de auditarlo ni
        // de rehacerlo.
        $cable = $this->material('Fibra DROP', equipo: false);

        $this->entrada($cable, 200, 1200);

        $this->assertDatabaseHas('material_movements', [
            'material_id' => $cable->id,
            'type' => 'Entrada',
            'purchase_unit_value' => 1200.00,
        ]);
    }

    // ==================== Los traslados ====================

    public function test_trasladar_consumible_lleva_el_costo_al_destino(): void
    {
        // Si el destino lo valorara a cero, mover cable de una bodega a
        // otra haría desaparecer dinero del inventario total sin que
        // nadie comprara ni gastara nada.
        $cable = $this->material('Fibra DROP', equipo: false);

        $this->entrada($cable, 500, 1300)->assertSessionHasNoErrors();

        $this->mover([
            'type' => 'Transferencia',
            'reason' => 'Carga de furgoneta',
            'warehouse_origin_id' => $this->principal->id,
            'warehouse_destination_id' => $this->furgoneta->id,
            'materials' => [[
                'material_id' => $cable->id,
                'quantity' => 100,
                'unit_of_measurement' => 'Metros',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1300.0, $this->costoEn($this->furgoneta, $cable));
        $this->assertSame(1300.0, $this->costoEn($this->principal, $cable));
    }

    public function test_un_traslado_no_puede_revaluar_el_material(): void
    {
        // El costo viaja con la existencia. Aceptarlo desde el
        // formulario permitiría subir el valor del inventario moviendo
        // material de sitio, sin comprar nada.
        $cable = $this->material('Fibra DROP', equipo: false);

        $this->entrada($cable, 500, 1300)->assertSessionHasNoErrors();

        $this->mover([
            'type' => 'Transferencia',
            'reason' => 'Carga de furgoneta',
            'warehouse_origin_id' => $this->principal->id,
            'warehouse_destination_id' => $this->furgoneta->id,
            'materials' => [[
                'material_id' => $cable->id,
                'quantity' => 100,
                'unit_of_measurement' => 'Metros',
                'purchase_unit_value' => 999999,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1300.0, $this->costoEn($this->furgoneta, $cable));
    }

    public function test_un_equipo_trasladado_conserva_su_costo(): void
    {
        $ont = $this->material('ONT Huawei', equipo: true);

        $this->mover([
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'materials' => [[
                'material_id' => $ont->id,
                'quantity' => 1,
                'unit_of_measurement' => 'Unidades',
                'serial_numbers' => ['SN-777'],
                'purchase_unit_value' => 195000,
            ]],
        ])->assertSessionHasNoErrors();

        $this->mover([
            'type' => 'Transferencia',
            'reason' => 'Carga de furgoneta',
            'warehouse_origin_id' => $this->principal->id,
            'warehouse_destination_id' => $this->furgoneta->id,
            'materials' => [[
                'material_id' => $ont->id,
                'quantity' => 1,
                'unit_of_measurement' => 'Unidades',
                'serial_numbers' => ['SN-777'],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->furgoneta->id,
            'serial_number' => 'SN-777',
            'purchase_unit_value' => 195000.00,
        ]);
    }

    // ==================== El catálogo ====================

    public function test_el_material_guarda_su_valor_de_referencia(): void
    {
        $categoria = Category::create([
            'branch_id' => $this->sucursal->id,
            'name' => 'Cables',
            'description' => 'De prueba',
        ]);

        $this->post(route('materials.store'), [
            'name' => 'Fibra DROP',
            'category_id' => $categoria->id,
            'is_equipment' => 0,
            'unit_of_measurement' => 'Metros',
            'purchase_unit_value' => 1250.50,
        ])->assertRedirect();

        $this->assertDatabaseHas('materials', [
            'name' => 'Fibra DROP',
            'purchase_unit_value' => 1250.50,
        ]);
    }

    public function test_el_valor_de_referencia_es_opcional(): void
    {
        $categoria = Category::create([
            'branch_id' => $this->sucursal->id,
            'name' => 'Cables',
            'description' => 'De prueba',
        ]);

        $this->post(route('materials.store'), [
            'name' => 'Grapas',
            'category_id' => $categoria->id,
            'is_equipment' => 0,
            'unit_of_measurement' => 'Unidades',
        ])->assertRedirect();

        // NULL y no cero: un cero se suma en los totales y hace creer
        // que el material no costó nada.
        $this->assertNull(Material::where('name', 'Grapas')->first()->purchase_unit_value);
    }

    public function test_no_se_admite_un_valor_negativo(): void
    {
        $categoria = Category::create([
            'branch_id' => $this->sucursal->id,
            'name' => 'Cables',
            'description' => 'De prueba',
        ]);

        $this->post(route('materials.store'), [
            'name' => 'Imposible',
            'category_id' => $categoria->id,
            'is_equipment' => 0,
            'unit_of_measurement' => 'Unidades',
            'purchase_unit_value' => -5,
        ])->assertSessionHasErrors('purchase_unit_value');
    }

    public function test_editar_sin_el_campo_no_borra_el_valor(): void
    {
        // Quien no tiene permiso para ver costos edita un material con
        // un formulario que no lleva ese campo. Guardar null ahí
        // borraría un dato que esa persona ni siquiera podía ver.
        $cable = $this->material('Fibra DROP', equipo: false, referencia: 1250);

        $this->put(route('materials.update', $cable), [
            'name' => 'Fibra DROP renombrada',
            'category_id' => $cable->category_id,
            'is_equipment' => 0,
            'unit_of_measurement' => 'Metros',
        ])->assertRedirect();

        $this->assertSame('1250.00', $cable->fresh()->purchase_unit_value);
    }

    // ==================== Quién puede verlos ====================

    /** Deja al rol de la sesión sin el permiso de ver costos. */
    private function sinPermisoDeCostos(): void
    {
        $this->rol->revokePermissionTo('materials.costs');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_la_pantalla_del_inventario_ensena_los_costos_con_permiso(): void
    {
        $cable = $this->material('Fibra DROP', equipo: false);
        $this->entrada($cable, 500, 1300)->assertSessionHasNoErrors();

        $this->get(route('warehouses.show', $this->principal))
            ->assertOk()
            ->assertSee('Valor del inventario', false)
            ->assertSee('650,000.00', false); // 500 × 1.300
    }

    public function test_sin_permiso_la_pantalla_no_ensena_ningun_costo(): void
    {
        $cable = $this->material('Fibra DROP', equipo: false);
        $this->entrada($cable, 500, 1300)->assertSessionHasNoErrors();

        $this->sinPermisoDeCostos();

        $respuesta = $this->get(route('warehouses.show', $this->principal))->assertOk();

        // Sigue viendo el inventario —cuánto hay— pero no lo que costó.
        $respuesta->assertSee('Fibra DROP', false);
        $respuesta->assertDontSee('Valor del inventario', false);
        $respuesta->assertDontSee('650,000.00', false);
        $respuesta->assertDontSee('1,300.00', false);
    }

    public function test_el_pdf_del_inventario_respeta_el_permiso(): void
    {
        // UN PDF SE GUARDA, SE REENVÍA Y SE IMPRIME. Si el permiso solo
        // se comprobara en la pantalla, bastaría con descargar el
        // inventario para saltárselo.
        //
        // Se mira el dato con el que se renderiza y no el PDF ya
        // generado: el texto de un PDF va comprimido y buscarlo dentro
        // daría una prueba que pasa por no encontrar nada.
        $cable = $this->material('Fibra DROP', equipo: false);
        $this->entrada($cable, 500, 1300)->assertSessionHasNoErrors();

        $conPermiso = $this->datosDelPdf();
        $this->assertTrue($conPermiso['verCostos']);
        $this->assertEqualsWithDelta(650000.0, $conPermiso['resumenValor']['total'], 0.01);

        $this->sinPermisoDeCostos();

        $this->assertFalse($this->datosDelPdf()['verCostos']);
    }

    /** Los datos con los que se renderiza el PDF del inventario. */
    private function datosDelPdf(): array
    {
        $capturado = [];

        \Illuminate\Support\Facades\View::composer(
            'gestisp.warehouses.pdf',
            function ($vista) use (&$capturado) {
                $capturado = $vista->getData();
            },
        );

        $this->get(route('warehouse.pdf', $this->principal))->assertOk();

        return $capturado;
    }
}
