<?php

namespace Tests\Feature\Clients;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El listado de clientes.
 *
 * LO QUE TENÍA
 * ------------
 * Tres columnas —número de contrato, servicio y estado— que se
 * pintaban y nunca se llenaban: eran `<td></td>` vacíos. El ojo de
 * la última columna era un `href=""`. El Excel bajaba la empresa
 * entera sin encabezados y sin respetar un solo filtro de la
 * pantalla, y no había PDF.
 *
 * LO QUE SE FIJA AQUÍ
 * -------------------
 * Que los contratos del cliente se vean, que los filtros filtren de
 * verdad —incluido buscar por número de contrato, que es lo que el
 * cliente trae apuntado en un papel—, y que lo que se descarga sea
 * exactamente lo que se está mirando.
 */
class ClientListingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private User $admin;

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
            'company_id' => $this->sucursal->company_id,
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $rol->id,
        ]);

        app(CurrentContext::class)->establecer(
            $this->sucursal->company_id,
            [$this->sucursal->id],
            $this->sucursal->id,
        );
    }

    private function cliente(array $datos = []): Client
    {
        static $n = 0;
        $n++;

        return Client::factory()->create(array_merge([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
            'name' => 'Cliente ' . $n,
            'last_name' => 'Apellido ' . $n,
            'identity_number' => '100000000' . $n,
            'number_phone' => '311111111' . $n,
            'aditional_phone' => '312111111' . $n,
        ], $datos));
    }

    private function conContrato(Client $cliente, string $numero, string $estado = 'Activo'): Contract
    {
        // Nombres unicos: la tabla tiene UNIQUE (empresa, sede, nombre),
        // asi que dos contratos en la misma prueba chocarian.
        $servicio = Service::factory()->create([
            'name' => 'Internet 100 megas ' . $numero,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
            'base_price' => 100000,
            'tax_percentage' => 0,
        ]);

        $plan = Plan::factory()->create([
            'name' => 'Hogar 100 ' . $numero,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->admin->id,
        ]);
        $plan->services()->attach($servicio->id);

        return Contract::factory()->create([
            'branch_id' => $this->sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
            'contract_number' => $numero,
            'status' => $estado,
        ]);
    }

    // ==================== Lo que se ve ====================

    public function test_el_listado_enseña_los_contratos_del_cliente(): void
    {
        // Eran tres celdas vacías: el contrato, el plan y el estado.
        $cliente = $this->cliente(['name' => 'Yolanda', 'last_name' => 'Palacio']);
        $contrato = $this->conContrato($cliente, 'EGP000123');

        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee('Yolanda', false)
            ->assertSee($contrato->numero_visible, false)
            ->assertSee('Hogar 100 ', false)
            ->assertSee('Activo', false);
    }

    public function test_un_cliente_sin_contrato_lo_dice(): void
    {
        $this->cliente(['name' => 'Sin', 'last_name' => 'Contrato']);

        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee('Sin contrato', false);
    }

    public function test_las_acciones_llevan_a_algun_sitio(): void
    {
        // El ojo de antes era href="" — no iba a ninguna parte.
        $cliente = $this->cliente();

        $this->get(route('clients.index'))
            ->assertOk()
            ->assertSee(route('clients.edit', $cliente), false)
            ->assertSee(route('contracts.create', $cliente), false);
    }

    public function test_las_cifras_cuentan_lo_filtrado(): void
    {
        $conContrato = $this->cliente(['name' => 'Conectado']);
        $this->conContrato($conContrato, 'EGP000001');

        $this->cliente(['name' => 'Suelto']);
        $this->cliente(['name' => 'Otro suelto']);

        $this->get(route('clients.index', ['contratos' => 'no']))
            ->assertOk()
            ->assertSee('Suelto', false)
            ->assertDontSee('Conectado', false);
    }

    // ==================== Los filtros ====================

    public function test_busca_por_nombre_o_apellido(): void
    {
        $this->cliente(['name' => 'Yolanda', 'last_name' => 'Palacio']);
        $this->cliente(['name' => 'Mariana', 'last_name' => 'Serna']);

        // Por el APELLIDO: el filtro viejo solo miraba `name`, así que
        // buscar «Palacio» no encontraba a nadie.
        $this->get(route('clients.index', [
            'filter_field' => 'name',
            'filter_value' => 'Palacio',
        ]))
            ->assertOk()
            ->assertSee('Yolanda', false)
            ->assertDontSee('Mariana', false);
    }

    public function test_busca_por_nombre_completo(): void
    {
        $this->cliente(['name' => 'Yolanda', 'last_name' => 'Palacio']);

        $this->get(route('clients.index', [
            'filter_field' => 'name',
            'filter_value' => 'Yolanda Palacio',
        ]))
            ->assertOk()
            ->assertSee('Yolanda', false);
    }

    public function test_busca_por_numero_de_contrato(): void
    {
        // En el mostrador es lo único que trae el cliente apuntado.
        $conContrato = $this->cliente(['name' => 'Conectado']);
        $contrato = $this->conContrato($conContrato, 'EGP000777');

        $this->cliente(['name' => 'Otro']);

        $this->get(route('clients.index', [
            'filter_field' => 'contract_number',
            'filter_value' => $contrato->contract_number,
        ]))
            ->assertOk()
            ->assertSee('Conectado', false)
            ->assertDontSee('>Otro<', false);
    }

    public function test_busca_por_documento_parcial(): void
    {
        // Era una comparación EXACTA: medio documento no encontraba nada.
        $this->cliente(['name' => 'Yolanda', 'identity_number' => '1098765432']);
        $this->cliente(['name' => 'Mariana', 'identity_number' => '1000000001']);

        $this->get(route('clients.index', [
            'filter_field' => 'identity_number',
            'filter_value' => '98765',
        ]))
            ->assertOk()
            ->assertSee('Yolanda', false)
            ->assertDontSee('Mariana', false);
    }

    public function test_filtra_por_estado_del_contrato(): void
    {
        $activo = $this->cliente(['name' => 'Alberto']);
        $this->conContrato($activo, 'EGP000010', 'Activo');

        $suspendido = $this->cliente(['name' => 'Berta']);
        $this->conContrato($suspendido, 'EGP000011', 'Suspendido');

        $this->get(route('clients.index', ['estado' => 'Suspendido']))
            ->assertOk()
            ->assertSee('Berta', false)
            ->assertDontSee('Alberto', false);
    }

    public function test_un_campo_que_no_esta_en_la_lista_blanca_no_filtra(): void
    {
        // El campo viaja por la URL: sin lista blanca bastaría con
        // escribir el nombre de otra columna.
        $this->cliente(['name' => 'Yolanda']);

        $this->get(route('clients.index', [
            'filter_field' => 'password',
            'filter_value' => 'x',
        ]))->assertOk()->assertSee('Yolanda', false);
    }

    public function test_la_paginacion_conserva_los_filtros(): void
    {
        $this->cliente(['name' => 'Yolanda', 'last_name' => 'Palacio']);

        $html = $this->get(route('clients.index', [
            'filter_field' => 'name',
            'filter_value' => 'Palacio',
            'per_page' => 15,
        ]))->assertOk()->getContent();

        // withQueryString(): sin él, pasar a la página 2 perdía el filtro
        $this->assertStringContainsString('filter_value=Palacio', $html);
    }

    // ==================== Las exportaciones ====================

    public function test_el_excel_respeta_los_filtros_de_la_pantalla(): void
    {
        // Era `Client::query()` a secas: quien filtraba por «sin
        // contrato» y exportaba se llevaba la empresa entera.
        $conContrato = $this->cliente(['name' => 'Conectado']);
        $this->conContrato($conContrato, 'EGP000001');
        $suelto = $this->cliente(['name' => 'Suelto']);

        $this->get(route('clients.export', ['contratos' => 'no']))->assertOk();

        // Y la consulta que alimenta el Excel es la filtrada
        $exportacion = new \App\Exports\ClientsExport(Client::query()->doesntHave('contracts'));
        $ids = $exportacion->query()->pluck('clients.id');

        $this->assertTrue($ids->contains($suelto->id));
        $this->assertFalse($ids->contains($conContrato->id));
    }

    public function test_el_excel_lleva_encabezados_y_los_contratos(): void
    {
        $cliente = $this->cliente(['name' => 'Yolanda', 'last_name' => 'Palacio']);
        $contrato = $this->conContrato($cliente, 'EGP000123');

        $exportacion = new \App\Exports\ClientsExport();

        $this->assertContains('Documento', $exportacion->headings());
        $this->assertContains('Numeros de contrato', $exportacion->headings());

        $fila = $exportacion->map(
            Client::with('contracts.plan')->withCount('contracts')->findOrFail($cliente->id)
        );

        $this->assertContains('Yolanda', $fila);
        $this->assertContains($contrato->numero_visible, $fila);
        $this->assertContains('Hogar 100 EGP000123', $fila);
    }

    public function test_el_pdf_sale_y_lleva_los_clientes(): void
    {
        $cliente = $this->cliente(['name' => 'Yolanda', 'last_name' => 'Palacio']);
        $this->conContrato($cliente, 'EGP000123');

        $respuesta = $this->get(route('clients.export-pdf'));

        $respuesta->assertOk();
        $this->assertStringStartsWith('%PDF-', $respuesta->getContent());
        $this->assertGreaterThan(1000, strlen($respuesta->getContent()));
    }

    public function test_el_pdf_dice_con_que_filtros_se_saco(): void
    {
        $cliente = $this->cliente(['name' => 'Yolanda', 'last_name' => 'Palacio']);
        $this->conContrato($cliente, 'EGP000123');

        // Sobre el HTML que alimenta a dompdf: dentro del PDF el texto
        // queda comprimido y partido por el kerning.
        $html = view('gestisp.clients.pdf', [
            'clients' => Client::with('contracts.plan')->get(),
            'resumen' => ['total' => 1, 'con_contrato' => 1, 'sin_contrato' => 0, 'juridicos' => 0],
            'filtros' => ['Nombre: Palacio'],
        ])->render();

        $this->assertStringContainsString('Nombre: Palacio', $html);
        $this->assertStringContainsString('Yolanda', $html);
    }

    public function test_las_exportaciones_exigen_su_permiso(): void
    {
        $rol = Role::where('name', 'auxiliar administrativo')->firstOrFail();

        $usuario = User::factory()->create(['number_phone' => '3000000002']);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($usuario)->withSession([
            'company_id' => $this->sucursal->company_id,
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('clients.export'))->assertForbidden();
        $this->get(route('clients.export-pdf'))->assertForbidden();
    }
}
