<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\OntMetric;
use App\Models\Plan;
use App\Models\User;
use App\Services\OltSnmpService;
use App\Services\OntPoller;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Métricas de ONTs por SNMP.
 *
 * No hay una OLT real en el entorno de pruebas, así que se
 * verifica la lógica propia del sistema: normalización de valores
 * crudos, cálculo de ancho de banda por diferencia de contadores,
 * historial y endpoints. La validación de los OIDs contra el
 * equipo real se hace con "php artisan olt:snmp-probe".
 */
class OntMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Ont $ont;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($role);
        $this->admin->branches()->attach($branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $branch->id,
            'current_role_id' => $role->id,
        ]);

        $client = Client::factory()->create([
            'branch_id' => $branch->id,
            'number_phone' => '3111111111',
            'aditional_phone' => '3111111112',
            'user_id' => $this->admin->id,
        ]);

        $plan = Plan::factory()->create(['branch_id' => $branch->id, 'user_id' => $this->admin->id]);

        $contract = Contract::factory()->create([
            'branch_id' => $branch->id,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
        ]);

        $olt = Olt::create([
            'branch_id' => $branch->id,
            'name' => 'OLT de pruebas',
            'ip_address' => '10.0.0.1',
            'ssh_port' => 22,
            'telnet_port' => 23,
            'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'admin',
            'password' => 'secret',
            'brand' => 'huawei',
            'uptime' => '0',
        ]);

        $this->ont = Ont::create([
            'branch_id' => $branch->id,
            'olt_id' => $olt->id,
            'contract_id' => $contract->id,
            'slot' => 1,
            'port' => 2,
            'onu_id' => 5,
            'if_index' => 4194304000,
            'sn' => 'TEST-SN-0001',
            'status' => 1,
        ]);
    }

    public function test_los_indices_snmp_se_guardan_al_asignarlos(): void
    {
        // if_index y traffic_if_index NO estaban en el fillable: se
        // descartaban en silencio y la ONT quedaba sin poder
        // consultarse por SNMP
        $ont = new Ont();
        $ont->fill(['if_index' => 123456, 'traffic_if_index' => 654321]);

        $this->assertSame(123456, $ont->if_index);
        $this->assertSame(654321, $ont->traffic_if_index);
    }

    public function test_normaliza_los_valores_crudos_del_equipo(): void
    {
        $service = app(OltSnmpService::class);
        $method = new \ReflectionMethod($service, 'normalize');
        $method->setAccessible(true);

        $definition = config('olt_snmp.brands.huawei.ont_metrics.rx_power');

        // Valor típico: -2150 centésimas de dBm → -21.5 dBm
        $ok = $method->invoke($service, 'INTEGER: -2150', $definition);
        $this->assertSame(-21.5, $ok['value']);
        $this->assertSame('dBm', $ok['unit']);

        // Centinela de "sin dato"
        $invalid = $method->invoke($service, '2147483647', $definition);
        $this->assertNull($invalid['value'], 'El valor centinela debe descartarse');

        // Fuera del rango plausible (-40 a 5 dBm)
        $absurd = $method->invoke($service, '-9000', $definition);
        $this->assertNull($absurd['value'], 'Una lectura imposible debe descartarse');

        // Sin respuesta del equipo
        $empty = $method->invoke($service, null, $definition);
        $this->assertNull($empty['value']);
    }

    public function test_traduce_los_estados_operativos(): void
    {
        $service = app(OltSnmpService::class);
        $method = new \ReflectionMethod($service, 'normalize');
        $method->setAccessible(true);

        $definition = config('olt_snmp.brands.huawei.ont_metrics.run_status');

        $this->assertSame('online', $method->invoke($service, '1', $definition)['value']);
        $this->assertSame('offline', $method->invoke($service, '2', $definition)['value']);
    }

    public function test_calcula_el_ancho_de_banda_por_diferencia_de_contadores(): void
    {
        $poller = app(OntPoller::class);
        $method = new \ReflectionMethod($poller, 'calculateRates');
        $method->setAccessible(true);

        // Muestra anterior: hace 60 segundos
        OntMetric::create([
            'ont_id' => $this->ont->id,
            'in_octets' => 1_000_000,
            'out_octets' => 5_000_000,
            'measured_at' => now()->subSeconds(60),
        ]);

        // Ahora: +7.5 MB de bajada en 60 s = 1 Mbps
        $rates = $method->invoke($poller, $this->ont, 1_750_000, 12_500_000, now());

        $this->assertSame(100000, $rates['in_bps']);   // 750.000 B en 60 s
        $this->assertSame(1000000, $rates['out_bps']); // 7.500.000 B en 60 s
    }

    public function test_descarta_el_reinicio_del_contador(): void
    {
        $poller = app(OntPoller::class);
        $method = new \ReflectionMethod($poller, 'calculateRates');
        $method->setAccessible(true);

        OntMetric::create([
            'ont_id' => $this->ont->id,
            'in_octets' => 9_000_000,
            'out_octets' => 9_000_000,
            'measured_at' => now()->subSeconds(60),
        ]);

        // El equipo se reinició: los contadores volvieron a cero.
        // No debe reportarse un pico negativo ni absurdo.
        $rates = $method->invoke($poller, $this->ont, 1000, 2000, now());

        $this->assertNull($rates['in_bps']);
        $this->assertNull($rates['out_bps']);
    }

    public function test_el_endpoint_de_historial_devuelve_las_muestras(): void
    {
        // Tres muestras en las últimas horas
        foreach ([3, 2, 1] as $hoursAgo) {
            OntMetric::create([
                'ont_id' => $this->ont->id,
                'rx_power' => -21.5 - $hoursAgo,
                'olt_rx_power' => -19.2,
                'in_bps' => 500000,
                'out_bps' => 2000000,
                'measured_at' => now()->subHours($hoursAgo),
            ]);
        }

        // Una muestra vieja que debe quedar fuera del rango
        OntMetric::create([
            'ont_id' => $this->ont->id,
            'rx_power' => -30,
            'measured_at' => now()->subDays(5),
        ]);

        $response = $this->getJson(route('onts.metrics_history', $this->ont) . '?hours=24')
            ->assertOk();

        $response->assertJson(['ok' => true, 'count' => 3, 'has_traffic' => true]);

        $samples = $response->json('samples');
        $this->assertCount(3, $samples);

        // Vienen ordenadas de más antigua a más reciente
        $this->assertSame(-24.5, $samples[0]['rx']);
        $this->assertSame(-22.5, $samples[2]['rx']);
        $this->assertSame(2000000, $samples[0]['out_bps']);
    }

    public function test_el_historial_respeta_el_rango_solicitado(): void
    {
        OntMetric::create([
            'ont_id' => $this->ont->id,
            'rx_power' => -22,
            'measured_at' => now()->subHours(10),
        ]);

        $this->getJson(route('onts.metrics_history', $this->ont) . '?hours=6')
            ->assertOk()
            ->assertJson(['count' => 0]);

        $this->getJson(route('onts.metrics_history', $this->ont) . '?hours=24')
            ->assertOk()
            ->assertJson(['count' => 1]);
    }

    public function test_la_vista_de_detalle_carga_con_las_graficas(): void
    {
        $this->get(route('onts.show', $this->ont))
            ->assertOk()
            ->assertSee('opticalChart', false)
            ->assertSee('trafficChart', false)
            ->assertSee('Historial', false);
    }

    public function test_informa_cuando_la_ont_no_tiene_indice_snmp(): void
    {
        $this->ont->update(['if_index' => null]);

        $response = $this->getJson(route('onts.realtime', $this->ont))->assertOk();

        $response->assertJson(['ok' => false]);
        $this->assertStringContainsString('if_index', $response->json('message'));
    }

    public function test_el_poller_limpia_el_historial_antiguo(): void
    {
        OntMetric::create([
            'ont_id' => $this->ont->id,
            'rx_power' => -22,
            'measured_at' => now()->subDays(45),
        ]);

        OntMetric::create([
            'ont_id' => $this->ont->id,
            'rx_power' => -21,
            'measured_at' => now()->subDays(5),
        ]);

        $deleted = app(OntPoller::class)->pruneOldMetrics(30);

        $this->assertSame(1, $deleted);
        $this->assertSame(1, OntMetric::count());
    }
}
