<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La observación de un movimiento de material.
 *
 * NO ES EL MOTIVO. `reason` es una lista cerrada —Compra, Deterioro,
 * Venta, Orden técnica— y por eso sirve para agrupar. La observación
 * es lo que el motivo no cuenta: que fueron cuatro ONT quemadas por
 * un rayo en la torre del Alto, que el proveedor mandó dos cajas de
 * más.
 *
 * Lo que se fija aquí:
 *
 *   · Va en los TRES tipos, al revés que el proveedor y la factura,
 *     que se anulan fuera de una entrada.
 *   · Es opcional, y un movimiento sin ella sigue entrando.
 *   · Llega entera a todos los sitios donde se consulta el
 *     movimiento: comprobante, historial, los dos PDF y el Excel. Un
 *     dato que solo se ve en la pantalla donde se escribió no sirve
 *     de nada seis meses después.
 *   · Se puede buscar por ella.
 */
class MovementObservationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private User $admin;
    private Warehouse $principal;
    private Warehouse $furgoneta;

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

    private function material(string $nombre = 'Fibra drop', bool $equipo = false): Material
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

    /** Mete material en el almacén principal, para poder sacarlo. */
    private function cargar(Material $material, float $cantidad = 500): void
    {
        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Inicial',
            'warehouse_destination_id' => $this->principal->id,
            'materials' => [[
                'material_id' => $material->id,
                'quantity' => $cantidad,
            ]],
        ])->assertSessionHasNoErrors();
    }

    // ==================== En los tres tipos ====================

    public function test_una_entrada_guarda_su_observacion(): void
    {
        $material = $this->material();

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Llegaron dos cajas de más; se le avisó al proveedor.',
            'materials' => [[
                'material_id' => $material->id,
                'quantity' => 300,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Llegaron dos cajas de más; se le avisó al proveedor.',
            MaterialMovement::latest('id')->firstOrFail()->observations,
        );
    }

    public function test_una_salida_tambien(): void
    {
        // Es el caso que lo pedía: «Salida por deterioro» no dice qué
        // se dañó ni por qué.
        $material = $this->material();
        $this->cargar($material);

        $this->post(route('movements.store'), [
            'type' => 'Salida',
            'reason' => 'Deterioro',
            'warehouse_origin_id' => $this->principal->id,
            'observations' => 'Rayo en la torre del Alto: se quemaron 4 drops.',
            'materials' => [[
                'material_id' => $material->id,
                'quantity' => 40,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            'torre del Alto',
            MaterialMovement::latest('id')->firstOrFail()->observations,
        );
    }

    public function test_un_traslado_tambien(): void
    {
        $material = $this->material();
        $this->cargar($material);

        $this->post(route('movements.store'), [
            'type' => 'Transferencia',
            'reason' => 'Transferencia',
            'warehouse_origin_id' => $this->principal->id,
            'warehouse_destination_id' => $this->furgoneta->id,
            'observations' => 'Para las instalaciones del jueves en La Mesa.',
            'materials' => [[
                'material_id' => $material->id,
                'quantity' => 100,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            'La Mesa',
            MaterialMovement::latest('id')->firstOrFail()->observations,
        );
    }

    public function test_es_opcional(): void
    {
        $material = $this->material();

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'materials' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(MaterialMovement::latest('id')->firstOrFail()->observations);
    }

    public function test_se_repite_en_todos_los_renglones_de_la_operacion(): void
    {
        // Cada serial es su propio renglón: si la observación se
        // quedara solo en el primero, el historial —que agrupa— la
        // perdería en cuanto alguien filtrara por otro renglón.
        $equipo = $this->material('ONT Huawei', equipo: true);

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Lote de la compra de octubre.',
            'materials' => [[
                'material_id' => $equipo->id,
                'quantity' => 3,
                'serial_numbers' => ['SN-001', 'SN-002', 'SN-003'],
            ]],
        ])->assertSessionHasNoErrors();

        $renglones = MaterialMovement::where('material_id', $equipo->id)->get();

        $this->assertCount(3, $renglones);
        $this->assertSame(
            ['Lote de la compra de octubre.'],
            $renglones->pluck('observations')->unique()->values()->all(),
        );
    }

    // ==================== Donde se consulta ====================

    public function test_sale_en_el_comprobante_de_la_operacion(): void
    {
        $material = $this->material();

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Pedido urgente para Yarumal.',
            'materials' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $operacion = MaterialMovement::latest('id')->firstOrFail()->operation_id;

        $this->get(route('movements.operation', $operacion))
            ->assertOk()
            ->assertSee('Observación', false)
            ->assertSee('Pedido urgente para Yarumal.', false);
    }

    public function test_sale_en_el_historial(): void
    {
        $material = $this->material();

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Pedido urgente para Yarumal.',
            'materials' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->get(route('movements.history'))
            ->assertOk()
            ->assertSee('Observación', false)
            ->assertSee('Pedido urgente para Yarumal.', false);
    }

    public function test_se_puede_buscar_por_la_observacion(): void
    {
        $material = $this->material();

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Rayo en la torre del Alto.',
            'materials' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Compra normal de octubre.',
            'materials' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->get(route('movements.history', [
            'filter_field' => 'observations',
            'filter_value' => 'torre del Alto',
        ]))
            ->assertOk()
            ->assertSee('Rayo en la torre del Alto.', false)
            ->assertDontSee('Compra normal de octubre.', false);
    }

    public function test_sale_en_el_pdf_del_comprobante(): void
    {
        $material = $this->material();
        $this->cargar($material);

        $this->post(route('movements.store'), [
            'type' => 'Salida',
            'reason' => 'Deterioro',
            'warehouse_origin_id' => $this->principal->id,
            'observations' => 'Rayo en la torre del Alto.',
            'materials' => [['material_id' => $material->id, 'quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $operacion = MaterialMovement::latest('id')->firstOrFail()->operation_id;

        // El PDF sale de verdad...
        $respuesta = $this->get(route('movements.operation_pdf', $operacion));
        $respuesta->assertOk();
        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());

        // ...y la observación va dentro. Se comprueba sobre el HTML que
        // alimenta a dompdf: dentro del PDF el texto queda comprimido y
        // partido en fragmentos de kerning, y buscarlo ahí daria un
        // falso negativo cada vez que cambie una fuente.
        $this->assertStringContainsString(
            'Rayo en la torre del Alto.',
            $this->html('gestisp.materials.movements.pdf_summary', [
                'movements' => MaterialMovement::where('operation_id', $operacion)
                    ->with(['material', 'user', 'warehouseOrigin.branch', 'warehouseDestination.branch'])
                    ->get()->all(),
                'operacion' => $operacion,
                'verCostos' => true,
            ]),
        );
    }

    public function test_sale_en_el_pdf_del_informe(): void
    {
        $material = $this->material();

        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Pedido urgente para Yarumal.',
            'materials' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $respuesta = $this->get(route('movements.pdf'));
        $respuesta->assertOk();
        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());

        $this->assertStringContainsString(
            'Pedido urgente para Yarumal.',
            $this->html('gestisp.materials.movements.pdf', [
                'operaciones' => collect([MaterialMovement::latest('id')->firstOrFail()]),
                'materialesPorOperacion' => collect(),
                'from' => null,
                'to' => null,
                'verCostos' => true,
            ]),
        );
    }

    /**
     * El HTML que se le entrega a dompdf, sin generar el PDF.
     *
     * La plantilla hereda del maquetado comun (PdfBranding), que es lo
     * que pone encabezado y pie: renderizarla suelta comprueba lo que
     * importa aqui sin depender de la tipografia.
     */
    private function html(string $vista, array $datos): string
    {
        return view($vista, $datos)->render();
    }

    public function test_sale_en_el_excel(): void
    {
        $exportacion = new \App\Exports\MaterialsMovementsExport();

        // La columna existe y va justo antes de quién lo hizo: el
        // Excel es lo que se manda al contador, y una observación que
        // no viaja con el movimiento no la lee nadie.
        $this->assertContains('Observacion', $exportacion->headings());

        $material = $this->material();
        $this->post(route('movements.store'), [
            'type' => 'Entrada',
            'reason' => 'Compra',
            'warehouse_destination_id' => $this->principal->id,
            'observations' => 'Pedido urgente para Yarumal.',
            'materials' => [['material_id' => $material->id, 'quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $fila = $exportacion->map(
            MaterialMovement::with(['material', 'user'])->latest('id')->firstOrFail()
        );

        $this->assertContains('Pedido urgente para Yarumal.', $fila);
    }

    public function test_el_formulario_la_ofrece_sin_distinguir_el_tipo(): void
    {
        // Fuera del bloque de la compra: ese se esconde con JS cuando
        // el tipo no es Entrada, y ahí la observación desaparecería.
        $html = $this->get(route('movements.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="observations"', $html);

        $campo = strpos($html, 'name="observations"');
        $bloqueCompra = strpos($html, 'id="datos-compra"');

        $this->assertTrue(
            $campo < $bloqueCompra,
            'la observación debe ir ANTES del bloque de datos de compra, que se oculta por tipo',
        );
    }
}
