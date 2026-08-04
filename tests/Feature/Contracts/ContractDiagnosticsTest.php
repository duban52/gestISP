<?php

namespace Tests\Feature\Contracts;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\MikrotikApiService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Diagnóstico de la conexión desde la ficha del contrato.
 *
 * Es para quien contesta el teléfono. Cuando el cliente llama
 * diciendo "no tengo internet", en la ficha tiene que poder ver en
 * segundos si la cuenta está conectada, con qué IP y cómo está la
 * ONT — sin entrar a otros tres módulos.
 *
 * Lo que se comprueba con más cuidado: que un router caído NO tumbe
 * la ficha del contrato, porque el resto de la información (cliente,
 * facturas, órdenes) tiene que seguir estando ahí.
 */
class ContractDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Contract $contrato;
    private Router $router;
    private Olt $olt;

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

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => Plan::factory()->create([
                'branch_id' => $this->branch->id,
                'user_id' => $this->admin->id,
            ])->id,
            'contract_number' => 'ENG000300',
            'user_id' => $this->admin->id,
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router San José',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $this->branch->id, 'name' => 'OLT Gómez Plata',
            'ip_address' => '10.0.0.1', 'ssh_port' => 22, 'telnet_port' => 23,
            'snmp_port' => 161, 'read_snmp_comunity' => 'public',
            'username' => 'a', 'password' => 'b', 'brand' => 'huawei', 'uptime' => '0',
        ]);
    }

    private function cuenta(array $extra = []): PppoeAccount
    {
        return PppoeAccount::create(array_merge([
            'branch_id' => $this->branch->id,
            'router_id' => $this->router->id,
            'contract_id' => $this->contrato->id,
            'mikrotik_id' => '*1',
            'username' => 'pepito.perez',
            'password' => 'x',
            'profile' => 'PLAN 150M',
            'service' => 'pppoe',
            'disabled' => false,
        ], $extra));
    }

    private function ont(array $extra = []): Ont
    {
        return Ont::create(array_merge([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt->id,
            'contract_id' => $this->contrato->id,
            'slot' => 0, 'port' => 1, 'onu_id' => 9,
            'sn' => 'HWTC-DD5BB00A',
            'status' => 1,
            'rx_power' => -19.5,
        ], $extra));
    }

    private function diagnosticar()
    {
        return $this->getJson(route('contracts.diagnostics', $this->contrato))->assertOk();
    }

    // ==================== PPPoE ====================

    public function test_informa_la_ip_de_la_conexion_activa(): void
    {
        $this->cuenta();

        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('getActiveSession')->andReturn([
                'mikrotik_id' => '*A',
                'username' => 'pepito.perez',
                'address' => '10.99.40.130',
                'uptime' => '3d4h',
                'caller_id' => 'AA:BB:CC',
            ]);
        });

        $respuesta = $this->diagnosticar();

        $this->assertTrue($respuesta->json('pppoe.conectada'));
        // La IP es lo que permite entrar a administrar el equipo
        $this->assertSame('10.99.40.130', $respuesta->json('pppoe.ip'));
        $this->assertSame('3d4h', $respuesta->json('pppoe.uptime'));
    }

    public function test_distingue_la_cuenta_sin_sesion_activa(): void
    {
        $this->cuenta();

        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('getActiveSession')->andReturnNull();
        });

        $respuesta = $this->diagnosticar();

        $this->assertTrue($respuesta->json('pppoe.consulta_ok'));
        $this->assertFalse($respuesta->json('pppoe.conectada'));
        $this->assertNull($respuesta->json('pppoe.ip'));
    }

    public function test_avisa_cuando_la_cuenta_esta_deshabilitada(): void
    {
        $this->cuenta(['disabled' => true]);

        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('getActiveSession')->andReturnNull();
        });

        // Corte administrativo: es la primera explicación que hay que
        // descartar antes de culpar a la red.
        $this->assertFalse($this->diagnosticar()->json('pppoe.habilitada'));
    }

    public function test_un_router_caido_no_tumba_el_diagnostico(): void
    {
        $this->cuenta();

        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('getActiveSession')->andThrow(new \RuntimeException('sin respuesta'));
        });

        $respuesta = $this->diagnosticar();

        // Responde igual, diciendo que no se pudo consultar
        $this->assertFalse($respuesta->json('pppoe.consulta_ok'));
        $this->assertStringContainsString('sin respuesta', $respuesta->json('pppoe.mensaje'));
    }

    // ==================== ONT ====================

    public function test_informa_el_estado_y_la_potencia_de_la_ont(): void
    {
        $this->ont();

        $respuesta = $this->diagnosticar();

        $this->assertSame('HWTC-DD5BB00A', $respuesta->json('ont.sn'));
        $this->assertSame('OLT Gómez Plata', $respuesta->json('ont.olt'));
        $this->assertSame('0/1/9', $respuesta->json('ont.ubicacion'));
        $this->assertTrue($respuesta->json('ont.en_linea'));
        $this->assertEqualsWithDelta(-19.5, $respuesta->json('ont.potencia'), 0.01);
        // La banda es lo que convierte un número en un diagnóstico
        $this->assertSame('optima', $respuesta->json('ont.banda'));
        $this->assertSame('Óptima', $respuesta->json('ont.banda_etiqueta'));
    }

    public function test_marca_como_critica_una_potencia_muy_baja(): void
    {
        $this->ont(['rx_power' => -29.0]);

        $respuesta = $this->diagnosticar();

        $this->assertSame('critica', $respuesta->json('ont.banda'));
        $this->assertSame('danger', $respuesta->json('ont.banda_color'));
    }

    public function test_avisa_cuando_la_ont_esta_deshabilitada(): void
    {
        $this->ont(['admin_enabled' => false, 'status' => 0]);

        $respuesta = $this->diagnosticar();

        $this->assertFalse($respuesta->json('ont.habilitada'));
        $this->assertFalse($respuesta->json('ont.en_linea'));
    }

    public function test_no_consulta_la_olt_para_diagnosticar(): void
    {
        $this->ont();

        // La potencia sale de la base (la mantiene onts:sync-power):
        // preguntarle a la OLT en cada llamada de soporte añadiría
        // medio minuto de espera por una lectura que es la misma.
        $this->mock(\App\Services\OltSshService::class, function ($mock) {
            $mock->shouldNotReceive('getOntStatus');
        });

        $this->diagnosticar();
    }

    // ==================== Sin equipos ====================

    public function test_un_contrato_sin_equipos_devuelve_vacio(): void
    {
        $respuesta = $this->diagnosticar();

        $this->assertNull($respuesta->json('pppoe'));
        $this->assertNull($respuesta->json('ont'));
    }

    // ==================== Alcance ====================

    public function test_no_se_diagnostica_un_contrato_de_otra_sucursal(): void
    {
        $otraBranch = Branch::factory()->create();

        $ajeno = Contract::factory()->create([
            'branch_id' => $otraBranch->id,
            'client_id' => Client::factory()->create([
                'branch_id' => $otraBranch->id,
                'user_id' => $this->admin->id,
            ])->id,
            'plan_id' => $this->contrato->plan_id,
            'user_id' => $this->admin->id,
        ]);

        $this->getJson(route('contracts.diagnostics', $ajeno))->assertStatus(403);
    }

    public function test_la_ficha_del_contrato_incluye_el_bloque_de_diagnostico(): void
    {
        $this->get(route('contracts.show', $this->contrato))
            ->assertOk()
            ->assertSee('Consultando el estado de la conexión', false);
    }
}
