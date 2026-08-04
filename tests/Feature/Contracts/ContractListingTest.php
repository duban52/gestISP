<?php

namespace Tests\Feature\Contracts;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\ContractQuery;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Listado de contratos con filtros combinables.
 *
 * El listado tenía un solo filtro (un campo, un valor). Servía para
 * "búsqueme a Juan", pero no para las preguntas con las que se
 * trabaja de verdad: los de tal barrio con dos meses de mora, los
 * activados en marzo que todavía no tienen ONT.
 *
 * Lo que se comprueba aquí es sobre todo que los filtros se
 * ACUMULEN — que combinar dos no anule al otro — y que la
 * exportación reciba exactamente lo mismo que se está viendo.
 */
class ContractListingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Plan $plan;
    private Plan $otroPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ENG']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->plan = Plan::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);
        $this->otroPlan = Plan::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);
    }

    private function contrato(array $atributos = [], array $cliente = []): Contract
    {
        $c = Client::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ], $cliente));

        return Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => $c->id,
            'plan_id' => $this->plan->id,
            'status' => ContractStatus::Activo->value,
            'user_id' => $this->admin->id,
        ], $atributos));
    }

    /**
     * Factura abierta que deja saldo pendiente.
     *
     * Cada llamada usa un período distinto porque el sistema tiene un
     * índice único por (contrato, período): no puede haber dos
     * facturas del mismo mes para el mismo contrato, que es
     * justamente lo que hace que "facturas pendientes" equivalga a
     * "meses de saldo".
     */
    private function facturaPendiente(Contract $contrato, float $saldo): Invoice
    {
        $mes = $contrato->invoices()->count() + 1;
        $periodo = \Illuminate\Support\Carbon::create(2026, $mes, 1);

        return Invoice::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'type' => 'Mensualidad',
            'billed_period' => $periodo->translatedFormat('F Y'),
            'billed_year_month' => $periodo->format('Ym'),
            'issue_date' => $periodo,
            'due_date' => $periodo->copy()->addDays(10),
            'subtotal' => $saldo,
            'total' => $saldo,
            'pending_invoice_amount' => $saldo,
            'status' => InvoiceStatus::Pendiente->value,
        ]);
    }

    /** Ejecuta el listado y devuelve los contratos que salieron. */
    private function listar(array $filtros = [])
    {
        return $this->get(route('contracts.index', $filtros))
            ->assertOk()
            ->viewData('contracts');
    }

    // ==================== Saldo y mora ====================

    public function test_el_listado_muestra_el_saldo_pendiente(): void
    {
        $contrato = $this->contrato(['contract_number' => 'ENG000001']);

        $this->facturaPendiente($contrato, 45000);
        $this->facturaPendiente($contrato, 30000);

        $fila = $this->listar()->firstWhere('id', $contrato->id);

        $this->assertEquals(75000, (float) $fila->saldo_pendiente);
        $this->assertSame(2, $fila->facturas_pendientes);
    }

    public function test_filtra_los_que_llevan_dos_meses_de_saldo(): void
    {
        $alDia = $this->contrato(['contract_number' => 'ENG000010']);

        $unMes = $this->contrato(['contract_number' => 'ENG000011']);
        $this->facturaPendiente($unMes, 40000);

        $dosMeses = $this->contrato(['contract_number' => 'ENG000012']);
        $this->facturaPendiente($dosMeses, 40000);
        $this->facturaPendiente($dosMeses, 40000);

        // Cada factura sin pagar es un mes: es la consulta con la que
        // se arma la lista de cortes.
        $resultado = $this->listar(['facturas_min' => 2]);

        $this->assertCount(1, $resultado);
        $this->assertSame($dosMeses->id, $resultado->first()->id);
    }

    public function test_filtra_por_rango_de_saldo(): void
    {
        $poco = $this->contrato(['contract_number' => 'ENG000020']);
        $this->facturaPendiente($poco, 10000);

        $mucho = $this->contrato(['contract_number' => 'ENG000021']);
        $this->facturaPendiente($mucho, 200000);

        $resultado = $this->listar(['saldo_min' => 100000]);

        $this->assertCount(1, $resultado);
        $this->assertSame($mucho->id, $resultado->first()->id);
    }

    // ==================== Filtros combinados ====================

    public function test_los_filtros_se_acumulan(): void
    {
        // El que cumple TODO
        $buscado = $this->contrato([
            'contract_number' => 'ENG000030',
            'neighborhood' => 'El Porvenir',
            'status' => ContractStatus::Suspendido->value,
            'activation_date' => '2026-03-15',
        ]);
        $this->facturaPendiente($buscado, 50000);
        $this->facturaPendiente($buscado, 50000);

        // Mismo barrio pero activo y sin mora
        $this->contrato([
            'contract_number' => 'ENG000031',
            'neighborhood' => 'El Porvenir',
            'activation_date' => '2026-03-20',
        ]);

        // Suspendido con mora pero de otro barrio
        $otroBarrio = $this->contrato([
            'contract_number' => 'ENG000032',
            'neighborhood' => 'Centro',
            'status' => ContractStatus::Suspendido->value,
            'activation_date' => '2026-03-10',
        ]);
        $this->facturaPendiente($otroBarrio, 50000);
        $this->facturaPendiente($otroBarrio, 50000);

        $resultado = $this->listar([
            'neighborhood' => 'Porvenir',
            'status' => [ContractStatus::Suspendido->value],
            'facturas_min' => 2,
            'activation_from' => '2026-03-01',
            'activation_to' => '2026-03-31',
        ]);

        // Si un filtro anulara a otro, aquí saldrían dos o tres
        $this->assertCount(1, $resultado);
        $this->assertSame($buscado->id, $resultado->first()->id);
    }

    public function test_filtra_por_varios_estados_a_la_vez(): void
    {
        $this->contrato(['contract_number' => 'ENG000040', 'status' => ContractStatus::Activo->value]);
        $this->contrato(['contract_number' => 'ENG000041', 'status' => ContractStatus::Suspendido->value]);
        $this->contrato(['contract_number' => 'ENG000042', 'status' => ContractStatus::PreSuspension->value]);

        $resultado = $this->listar([
            'status' => [ContractStatus::Suspendido->value, ContractStatus::PreSuspension->value],
        ]);

        $this->assertCount(2, $resultado);
    }

    public function test_filtra_por_plan(): void
    {
        $this->contrato(['contract_number' => 'ENG000050', 'plan_id' => $this->plan->id]);
        $delOtro = $this->contrato(['contract_number' => 'ENG000051', 'plan_id' => $this->otroPlan->id]);

        $resultado = $this->listar(['plan_id' => [$this->otroPlan->id]]);

        $this->assertCount(1, $resultado);
        $this->assertSame($delOtro->id, $resultado->first()->id);
    }

    public function test_filtra_por_fecha_de_activacion(): void
    {
        $this->contrato(['contract_number' => 'ENG000060', 'activation_date' => '2026-01-10']);
        $deMarzo = $this->contrato(['contract_number' => 'ENG000061', 'activation_date' => '2026-03-10']);

        $resultado = $this->listar([
            'activation_from' => '2026-03-01',
            'activation_to' => '2026-03-31',
        ]);

        $this->assertCount(1, $resultado);
        $this->assertSame($deMarzo->id, $resultado->first()->id);
    }

    // ==================== Equipos ====================

    public function test_filtra_los_contratos_sin_ont(): void
    {
        $olt = Olt::create([
            'branch_id' => $this->branch->id, 'name' => 'OLT', 'ip_address' => '10.0.0.1',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public', 'username' => 'a', 'password' => 'b',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        $conOnt = $this->contrato(['contract_number' => 'ENG000070']);
        $sinOnt = $this->contrato(['contract_number' => 'ENG000071']);

        Ont::create([
            'branch_id' => $this->branch->id, 'olt_id' => $olt->id,
            'contract_id' => $conOnt->id, 'slot' => 0, 'port' => 1, 'onu_id' => 3,
            'sn' => 'HWTC1234', 'status' => 1,
        ]);

        // Es la consulta que detecta instalaciones a medias
        $resultado = $this->listar(['has_ont' => 'no']);

        $this->assertCount(1, $resultado);
        $this->assertSame($sinOnt->id, $resultado->first()->id);
    }

    public function test_filtra_los_contratos_con_cuenta_pppoe(): void
    {
        $router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router', 'ip_address' => '10.0.0.2',
            'username' => 'admin', 'password' => 'x', 'api_port' => 8728, 'active' => true,
        ]);

        $conCuenta = $this->contrato(['contract_number' => 'ENG000080']);
        $this->contrato(['contract_number' => 'ENG000081']);

        PppoeAccount::create([
            'branch_id' => $this->branch->id, 'router_id' => $router->id,
            'contract_id' => $conCuenta->id, 'mikrotik_id' => '*1',
            'username' => 'usuario.prueba', 'password' => 'x',
            'profile' => 'default', 'service' => 'pppoe', 'disabled' => false,
        ]);

        $resultado = $this->listar(['has_pppoe' => 'si']);

        $this->assertCount(1, $resultado);
        $this->assertSame($conCuenta->id, $resultado->first()->id);
    }

    // ==================== Búsqueda libre ====================

    public function test_la_busqueda_rapida_encuentra_por_varios_campos(): void
    {
        $contrato = $this->contrato([
            'contract_number' => 'ENG000090',
            'address' => 'Calle 19 # 23-46',
            'user_pppoe' => 'pepito.perez',
        ], [
            'identity_number' => '1042770586',
            'name' => 'Pepito',
            'last_name' => 'Pérez',
        ]);

        foreach (['ENG000090', '1042770586', 'Pepito', 'Calle 19', 'pepito.perez'] as $termino) {
            $resultado = $this->listar(['q' => $termino]);

            $this->assertCount(1, $resultado, "La búsqueda de '{$termino}' no encontró el contrato");
            $this->assertSame($contrato->id, $resultado->first()->id);
        }
    }

    // ==================== Columnas ====================

    public function test_por_defecto_muestra_las_columnas_de_siempre(): void
    {
        $this->contrato(['contract_number' => 'ENG000100']);

        $respuesta = $this->get(route('contracts.index'))->assertOk();

        $this->assertSame(
            ContractQuery::columnasPorDefecto(),
            $respuesta->viewData('columnasActivas'),
        );

        // La clave del wifi no sale sin que alguien la pida. Se
        // comprueba sobre las columnas activas y no sobre el HTML,
        // porque el selector de columnas sí lista todos los títulos.
        $this->assertNotContains('password_wifi', $respuesta->viewData('columnasActivas'));
    }

    public function test_el_selector_de_columnas_usa_identificadores_reales(): void
    {
        $this->contrato(['contract_number' => 'ENG000105']);

        $respuesta = $this->get(route('contracts.index'))->assertOk();

        // El selector agrupa las columnas para que se puedan leer. Al
        // agrupar hay que CONSERVAR las claves: sin eso los id salían
        // como col_0, col_1… repetidos en cada grupo, y al pulsar una
        // casilla el navegador marcaba otra —la primera con ese id—.
        // Además el valor enviado era "0" y la selección se descartaba
        // entera al validarla contra el catálogo.
        $respuesta->assertSee('id="col_contract_number"', false);
        $respuesta->assertSee('id="col_password_wifi"', false);
        $respuesta->assertSee('value="social_stratum"', false);
        $respuesta->assertDontSee('id="col_0"', false);
    }

    public function test_el_selector_marca_las_columnas_activas(): void
    {
        $this->contrato(['contract_number' => 'ENG000106']);

        $respuesta = $this->get(route('contracts.index', [
            'columnas' => ['contract_number', 'ssid_wifi'],
        ]))->assertOk();

        // Al abrir el selector deben verse marcadas las que están en
        // uso; con las claves perdidas ninguna aparecía marcada.
        $html = $respuesta->getContent();

        $this->assertMatchesRegularExpression(
            '/id="col_ssid_wifi"[^>]*checked/',
            $html,
            'La columna activa no aparece marcada en el selector',
        );
    }

    public function test_se_pueden_pedir_columnas_tecnicas(): void
    {
        $this->contrato([
            'contract_number' => 'ENG000110',
            'ssid_wifi' => 'MiRedWifi',
            'password_wifi' => 'clave-secreta',
            'cpe_sn' => 'HWTC-AABBCC',
        ]);

        $this->get(route('contracts.index', [
            'columnas' => ['contract_number', 'ssid_wifi', 'password_wifi', 'cpe_sn'],
        ]))
            ->assertOk()
            ->assertSee('MiRedWifi')
            ->assertSee('clave-secreta')
            ->assertSee('HWTC-AABBCC');
    }

    public function test_una_columna_inventada_se_descarta(): void
    {
        // Las claves llegan del navegador: sin filtrarlas contra el
        // catálogo se podría pedir cualquier cosa.
        $columnas = ContractQuery::columnasValidas(['contract_number', 'columna_inventada']);

        $this->assertSame(['contract_number'], $columnas);
    }

    public function test_sin_columnas_elegidas_devuelve_las_de_defecto(): void
    {
        $this->assertSame(
            ContractQuery::columnasPorDefecto(),
            ContractQuery::columnasValidas([]),
        );
    }

    // ==================== Exportación ====================

    public function test_la_exportacion_respeta_los_filtros_y_las_columnas(): void
    {
        $conMora = $this->contrato(['contract_number' => 'ENG000120']);
        $this->facturaPendiente($conMora, 50000);
        $this->facturaPendiente($conMora, 50000);

        $this->contrato(['contract_number' => 'ENG000121']);

        $this->get(route('contracts.export_filtered', [
            'facturas_min' => 2,
            'columnas' => ['contract_number', 'saldo_pendiente'],
        ]))->assertOk();

        // Queda constancia de qué listado se sacó: un Excel con
        // nombres, direcciones y claves wifi es información sensible.
        $this->assertDatabaseHas('audits', ['action' => 'contracts.exported']);

        $registro = \App\Models\Audit::where('action', 'contracts.exported')->firstOrFail();

        $this->assertStringContainsString('1 contrato', $registro->description);
    }

    // ==================== Alcance ====================

    public function test_solo_se_listan_los_contratos_de_la_sucursal(): void
    {
        $otraBranch = Branch::factory()->create();

        $this->contrato(['contract_number' => 'ENG000130']);

        $cliente = Client::factory()->create(['branch_id' => $otraBranch->id, 'user_id' => $this->admin->id]);
        Contract::factory()->create([
            'branch_id' => $otraBranch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'contract_number' => 'OTR000001',
            'user_id' => $this->admin->id,
        ]);

        $resultado = $this->listar();

        $this->assertCount(1, $resultado);
        $this->assertSame('ENG000130', $resultado->first()->contract_number);
    }
}
