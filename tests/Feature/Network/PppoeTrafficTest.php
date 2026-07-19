<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\PppoeAccount;
use App\Models\PppoeSessionMetric;
use App\Models\Router;
use App\Models\User;
use App\Services\PppoePoller;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tráfico de las cuentas PPPoE.
 *
 * Las respuestas del router se simulan con Http::fake() para
 * probar el sistema sin depender de un Mikrotik real: lo que se
 * verifica es el reparto de contadores entre cuentas, el cálculo
 * de velocidad y los endpoints.
 */
class PppoeTrafficTest extends TestCase
{
    use RefreshDatabase;

    private PppoeAccount $account;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $admin = User::factory()->create(['number_phone' => '3000000000']);
        $admin->assignRole($role);
        $admin->branches()->attach($branch->id, ['role_id' => $role->id]);

        $this->actingAs($admin)->withSession([
            'branch_id' => $branch->id,
            'current_role_id' => $role->id,
        ]);

        $this->router = Router::create([
            'branch_id' => $branch->id,
            'name' => 'Router de pruebas',
            'ip_address' => '10.0.0.2',
            'api_port' => 80,
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $this->account = PppoeAccount::create([
            'branch_id' => $branch->id,
            'router_id' => $this->router->id,
            'username' => 'cliente.prueba',
            'password' => 'clave',
            'profile' => '10M',
        ]);
    }

    /**
     * Simula la respuesta de /interface del router.
     */
    private function fakeInterfaces(int $rxBytes, int $txBytes): void
    {
        Http::fake([
            '*/rest/interface' => Http::response([
                ['name' => 'ether1', 'rx-byte' => '999', 'tx-byte' => '999', 'running' => 'true'],
                [
                    'name' => '<pppoe-cliente.prueba>',
                    'rx-byte' => (string) $rxBytes,
                    'tx-byte' => (string) $txBytes,
                    'running' => 'true',
                ],
            ], 200),
        ]);
    }

    public function test_reparte_los_contadores_del_router_entre_las_cuentas(): void
    {
        $this->fakeInterfaces(rxBytes: 1_000_000, txBytes: 5_000_000);

        $result = app(PppoePoller::class)->poll($this->router);

        $this->assertSame(1, $result['accounts']);
        $this->assertSame(1, $result['connected']);
        $this->assertTrue($result['reachable']);

        $metric = PppoeSessionMetric::firstOrFail();
        $this->assertSame(1_000_000, $metric->in_octets);
        $this->assertSame(5_000_000, $metric->out_octets);
        $this->assertTrue($metric->connected);

        // La primera muestra no tiene con qué comparar
        $this->assertNull($metric->in_bps);
    }

    public function test_calcula_la_velocidad_con_la_segunda_muestra(): void
    {
        // Muestra previa de hace 60 segundos
        PppoeSessionMetric::create([
            'pppoe_account_id' => $this->account->id,
            'in_octets' => 1_000_000,
            'out_octets' => 5_000_000,
            'connected' => true,
            'measured_at' => now()->subSeconds(60),
        ]);

        // Ahora: +750.000 B de subida y +7.500.000 B de bajada
        $this->fakeInterfaces(rxBytes: 1_750_000, txBytes: 12_500_000);

        app(PppoePoller::class)->poll($this->router);

        $metric = PppoeSessionMetric::latest('id')->firstOrFail();

        $this->assertSame(100000, $metric->in_bps);    // 750.000 B en 60 s
        $this->assertSame(1000000, $metric->out_bps);  // 7.500.000 B en 60 s
    }

    public function test_descarta_el_pico_falso_al_reconectarse_la_sesion(): void
    {
        PppoeSessionMetric::create([
            'pppoe_account_id' => $this->account->id,
            'in_octets' => 9_000_000,
            'out_octets' => 9_000_000,
            'connected' => true,
            'measured_at' => now()->subSeconds(60),
        ]);

        // La sesión se reconectó: RouterOS crea una interfaz nueva
        // y los contadores vuelven a empezar
        $this->fakeInterfaces(rxBytes: 1000, txBytes: 2000);

        app(PppoePoller::class)->poll($this->router);

        $metric = PppoeSessionMetric::latest('id')->firstOrFail();

        $this->assertNull($metric->in_bps, 'Un contador reiniciado no debe generar velocidad');
        $this->assertNull($metric->out_bps);
    }

    public function test_registra_la_desconexion_cuando_no_hay_interfaz(): void
    {
        // El router responde sin la interfaz dinámica del cliente:
        // la sesión está caída
        Http::fake([
            '*/rest/interface' => Http::response([
                ['name' => 'ether1', 'rx-byte' => '999', 'tx-byte' => '999', 'running' => 'true'],
            ], 200),
        ]);

        $result = app(PppoePoller::class)->poll($this->router);

        $this->assertSame(0, $result['connected']);

        $metric = PppoeSessionMetric::firstOrFail();
        $this->assertFalse($metric->connected);
        $this->assertNull($metric->in_octets);
    }

    public function test_informa_cuando_el_router_no_responde(): void
    {
        Http::fake(['*/rest/interface' => Http::response('', 500)]);

        $result = app(PppoePoller::class)->poll($this->router);

        $this->assertFalse($result['reachable']);
        $this->assertSame(0, PppoeSessionMetric::count());
    }

    public function test_el_endpoint_de_historial_resume_el_periodo(): void
    {
        foreach ([[3, 500000, 2000000], [2, 800000, 8000000], [1, 300000, 5000000]] as [$h, $in, $out]) {
            PppoeSessionMetric::create([
                'pppoe_account_id' => $this->account->id,
                'in_bps' => $in,
                'out_bps' => $out,
                'connected' => true,
                'measured_at' => now()->subHours($h),
            ]);
        }

        $response = $this->getJson(route('pppoe.metrics_history', $this->account) . '?hours=24')
            ->assertOk();

        $response->assertJson([
            'ok' => true,
            'count' => 3,
            'has_traffic' => true,
            'peak_in_bps' => 800000,
            'peak_out_bps' => 8000000,
            'avg_out_bps' => 5000000,
        ]);
    }

    public function test_la_vista_de_detalle_incluye_la_grafica(): void
    {
        $this->get(route('pppoe.show', $this->account))
            ->assertOk()
            ->assertSee('trafficChart', false)
            ->assertSee('Ancho de banda', false)
            ->assertSee('Velocidad actual', false);
    }

    public function test_el_poller_limpia_el_historial_antiguo(): void
    {
        PppoeSessionMetric::create([
            'pppoe_account_id' => $this->account->id,
            'connected' => true,
            'measured_at' => now()->subDays(45),
        ]);

        PppoeSessionMetric::create([
            'pppoe_account_id' => $this->account->id,
            'connected' => true,
            'measured_at' => now()->subDays(5),
        ]);

        $this->assertSame(1, app(PppoePoller::class)->pruneOldMetrics(30));
        $this->assertSame(1, PppoeSessionMetric::count());
    }
}
