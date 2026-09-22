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
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Seriales desde un archivo en los movimientos de almacén.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que en una salida o una transferencia cada serial EXISTA en el
 *    almacén de origen. Antes solo se contaban: uno inexistente dejaba
 *    el movimiento registrado sin mover nada del inventario.
 * 2. Que en una entrada no se ingrese un serial que ya está.
 * 3. Que un pedido de 500 equipos entra completo: sin perder seriales
 *    por el límite de campos de PHP.
 */
class SerialFileTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private Warehouse $principal;
    private Warehouse $furgoneta;
    private Material $ont;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $admin = User::factory()->create();
        $admin->assignRole($rol);
        $admin->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($admin)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->principal = Warehouse::create(['branch_id' => $this->sucursal->id, 'description' => 'Almacén principal']);
        $this->furgoneta = Warehouse::create(['branch_id' => $this->sucursal->id, 'description' => 'Furgoneta']);
        $this->ont = $this->material('ONT Huawei', true);
    }

    // ==================== Entradas ====================

    public function test_un_pedido_de_500_equipos_entra_completo(): void
    {
        $seriales = array_map(fn ($i) => sprintf('HWTC%08d', $i), range(1, 500));

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'materials' => [[
                'material_id' => $this->ont->id,
                'quantity' => 500,
                'serials_text' => implode("\n", $seriales),
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(500, Inventory::where('warehouse_id', $this->principal->id)->count());
        $this->assertSame(500, MaterialMovement::count());
    }

    public function test_no_se_ingresa_un_serial_que_ya_esta(): void
    {
        $this->existencia('HWTC00000001', $this->furgoneta);

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'materials' => [[
                'material_id' => $this->ont->id,
                'quantity' => 2,
                // En minúsculas: es el mismo equipo.
                'serials_text' => "hwtc00000001\nHWTC00000002",
            ]],
        ])->assertSessionHasErrors('error');

        $this->assertSame(0, Inventory::where('warehouse_id', $this->principal->id)->count());
        $this->assertStringContainsString('Ya está en el inventario', session('errors')->first('error'));
    }

    public function test_el_archivo_dice_cuales_valen_y_por_que_no_los_demas(): void
    {
        $this->existencia('HWTC00000009', $this->furgoneta);

        $archivo = UploadedFile::fake()->createWithContent(
            'pedido.csv',
            "Serial;Modelo\nHWTC00000001;HG8145\nHWTC00000002;HG8145\nHWTC00000001;HG8145\nHWTC00000009;HG8145\n4.8575443123457E+15;HG8145\n",
        );

        $r = $this->post(route('movements.serials_file'), [
            'archivo' => $archivo,
            'type' => 'Entrada',
            'material_id' => $this->ont->id,
        ])->assertOk()->json();

        $this->assertSame(['HWTC00000001', 'HWTC00000002'], $r['validos']);
        $this->assertSame(5, $r['leidos']);
        $motivos = collect($r['problemas'])->mapWithKeys(fn ($p) => [$p['serial'] => $p['motivo']]);

        $this->assertCount(3, $motivos);
        $this->assertSame('Repetido en la lista.', $motivos['HWTC00000001']);
        $this->assertStringStartsWith('Ya está en el inventario: ONT Huawei en «Furgoneta»', $motivos['HWTC00000009']);
        $this->assertStringStartsWith('Excel lo convirtió en número', $motivos['4.8575443123457E+15']);
    }

    public function test_se_lee_un_excel_de_verdad(): void
    {
        $hoja = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja->getActiveSheet()->fromArray([['SN'], ['HWTC00000001'], ['HWTC00000002']]);
        $ruta = tempnam(sys_get_temp_dir(), 'sn') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($hoja))->save($ruta);

        $r = $this->post(route('movements.serials_file'), [
            'archivo' => new UploadedFile($ruta, 'pedido.xlsx', null, null, true),
            'type' => 'Entrada',
            'material_id' => $this->ont->id,
        ])->assertOk()->json();

        $this->assertSame(['HWTC00000001', 'HWTC00000002'], $r['validos']);
        @unlink($ruta);
    }

    // ==================== Salidas y transferencias ====================

    public function test_una_salida_con_un_serial_que_no_existe_no_se_registra(): void
    {
        $this->existencia('HWTC00000001', $this->principal);

        $this->post(route('movements.store'), [
            'type' => 'Salida',
            'reason' => 'Instalación',
            'warehouse_origin_id' => $this->principal->id,
            'materials' => [[
                'material_id' => $this->ont->id,
                'quantity' => 1,
                'serial_numbers' => ['NO-EXISTE'],
            ]],
        ])->assertSessionHasErrors('error');

        // Antes quedaba el movimiento registrado sin tocar el inventario.
        $this->assertSame(0, MaterialMovement::count());
        $this->assertSame(1, Inventory::count());
    }

    public function test_una_transferencia_solo_mueve_lo_que_esta_en_el_origen(): void
    {
        $this->existencia('HWTC00000001', $this->principal);
        $this->existencia('HWTC00000002', $this->furgoneta);

        $archivo = UploadedFile::fake()->createWithContent('traslado.txt', "hwtc00000001\nHWTC00000002\nNO-EXISTE\n");

        $r = $this->post(route('movements.serials_file'), [
            'archivo' => $archivo,
            'type' => 'Transferencia',
            'material_id' => $this->ont->id,
            'warehouse_origin_id' => $this->principal->id,
        ])->assertOk()->json();

        // El guardado, con sus mayúsculas.
        $this->assertSame(['HWTC00000001'], $r['validos']);
        $this->assertSame('No está en el almacén de origen: está en «Furgoneta».', $r['problemas'][0]['motivo']);
        $this->assertSame('No existe en el inventario.', $r['problemas'][1]['motivo']);

        // Y con lo que valía, la transferencia se hace.
        $this->post(route('movements.store'), [
            'type' => 'Transferencia',
            'reason' => 'Carga de furgoneta',
            'warehouse_origin_id' => $this->principal->id,
            'warehouse_destination_id' => $this->furgoneta->id,
            'materials' => [[
                'material_id' => $this->ont->id,
                'quantity' => 1,
                'serials_text' => implode("\n", $r['validos']),
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->furgoneta->id, Inventory::where('serial_number', 'HWTC00000001')->value('warehouse_id'));
    }

    public function test_un_serial_de_otro_material_no_sale(): void
    {
        $router = $this->material('Router TP-Link', true);
        $this->existencia('SN-ROUTER-1', $this->principal, $router);

        $r = $this->post(route('movements.serials_file'), [
            'archivo' => UploadedFile::fake()->createWithContent('s.txt', "SN-ROUTER-1\n"),
            'type' => 'Salida',
            'material_id' => $this->ont->id,
            'warehouse_origin_id' => $this->principal->id,
        ])->assertOk()->json();

        $this->assertSame([], $r['validos']);
        $this->assertSame('Es de otro material: Router TP-Link.', $r['problemas'][0]['motivo']);
    }

    public function test_un_material_sin_serial_no_carga_archivo(): void
    {
        $cable = $this->material('Cable drop', false);

        $this->post(route('movements.serials_file'), [
            'archivo' => UploadedFile::fake()->createWithContent('s.txt', "X\n"),
            'type' => 'Entrada',
            'material_id' => $cable->id,
        ])->assertStatus(422);
    }

    public function test_la_pantalla_trae_la_carga_de_archivo(): void
    {
        $this->get(route('movements.index'))
            ->assertOk()
            ->assertSee('Cargar los seriales desde un archivo')
            ->assertSee(route('movements.serials_file'), false);
    }

    // ==================== Apoyo ====================

    private function material(string $nombre, bool $equipo): Material
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
        ]);
    }

    private function existencia(string $serial, Warehouse $almacen, ?Material $material = null): Inventory
    {
        return Inventory::create([
            'warehouse_id' => $almacen->id,
            'material_id' => ($material ?? $this->ont)->id,
            'quantity' => 1,
            'unit_of_measurement' => 'Unidades',
            'serial_number' => $serial,
        ]);
    }
}
