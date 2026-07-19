<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\User;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CATV y estado administrativo de la ONT.
 *
 * Las operaciones sobre el equipo van por consola (SSH), así que
 * el servicio se sustituye por un doble: lo que se verifica es
 * que el sistema ordene lo correcto y registre el estado
 * resultante para poder mostrarlo al instante.
 */
class OntCatvAndAdminTest extends TestCase
{
    use RefreshDatabase;

    private Ont $ont;

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

        $client = Client::factory()->create([
            'branch_id' => $branch->id,
            'number_phone' => '3111111111',
            'aditional_phone' => '3111111112',
            'user_id' => $admin->id,
        ]);

        $plan = Plan::factory()->create(['branch_id' => $branch->id, 'user_id' => $admin->id]);

        $contract = Contract::factory()->create([
            'branch_id' => $branch->id,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'user_id' => $admin->id,
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
            'sn' => 'TEST-SN-CATV',
            'status' => 1,
        ]);
    }

    public function test_deshabilitar_la_ont_ordena_el_corte_y_registra_el_estado(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntAdminState')
                ->once()
                ->withArgs(fn ($olt, $ont, $enable) => $enable === false);
        });

        $this->post(route('onts.disable', $this->ont))->assertRedirect();

        $this->assertFalse($this->ont->fresh()->admin_enabled);
    }

    public function test_habilitar_la_ont_restablece_el_estado(): void
    {
        $this->ont->update(['admin_enabled' => false]);

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntAdminState')
                ->once()
                ->withArgs(fn ($olt, $ont, $enable) => $enable === true);
        });

        $this->post(route('onts.enable', $this->ont))->assertRedirect();

        $this->assertTrue($this->ont->fresh()->admin_enabled);
    }

    public function test_si_la_olt_rechaza_el_cambio_no_se_registra_el_estado(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntAdminState')
                ->andThrow(new \Exception('La OLT rechazó el comando'));
        });

        $this->post(route('onts.disable', $this->ont))
            ->assertRedirect()
            ->assertSessionHas('error');

        // El estado NO debe cambiar si el equipo no lo aplicó
        $this->assertNotFalse($this->ont->fresh()->admin_enabled);
    }

    public function test_apagar_catv_guarda_el_estado_y_la_fecha(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setCatvPort')
                ->once()
                ->withArgs(fn ($olt, $ont, $enable) => $enable === false);
        });

        $this->post(route('onts.catv.disable', $this->ont))->assertRedirect();

        $ont = $this->ont->fresh();
        $this->assertFalse($ont->catv_enabled);
        $this->assertNotNull($ont->catv_checked_at);
    }

    public function test_encender_catv_guarda_el_estado(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setCatvPort')
                ->once()
                ->withArgs(fn ($olt, $ont, $enable) => $enable === true);
        });

        $this->post(route('onts.catv.enable', $this->ont))->assertRedirect();

        $this->assertTrue($this->ont->fresh()->catv_enabled);
    }

    public function test_verificar_catv_consulta_la_olt_y_actualiza_el_estado(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('getCatvPortState')->once()->andReturn('on');
        });

        $this->getJson(route('onts.catv.state', $this->ont))
            ->assertOk()
            ->assertJson(['ok' => true, 'catv_enabled' => true]);

        $ont = $this->ont->fresh();
        $this->assertTrue($ont->catv_enabled);
        $this->assertNotNull($ont->catv_checked_at);
    }

    public function test_verificar_catv_informa_si_la_olt_no_responde(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('getCatvPortState')
                ->andThrow(new \Exception('Tiempo de espera agotado'));
        });

        $this->getJson(route('onts.catv.state', $this->ont))
            ->assertOk()
            ->assertJson(['ok' => false]);

        // Sin respuesta del equipo, el estado guardado no se toca
        $this->assertNull($this->ont->fresh()->catv_enabled);
    }

    public function test_la_vista_muestra_el_control_de_la_ont_y_la_tarjeta_catv(): void
    {
        $response = $this->get(route('onts.show', $this->ont))->assertOk();

        $response->assertSee('Control de la ONT', false)
            ->assertSee('Deshabilitar ONT', false)
            ->assertSee('catvCard', false)
            ->assertSee('btnCheckCatv', false);
    }

    public function test_la_vista_ofrece_habilitar_cuando_la_ont_esta_deshabilitada(): void
    {
        $this->ont->update(['admin_enabled' => false]);

        $this->get(route('onts.show', $this->ont))
            ->assertOk()
            ->assertSee('Habilitar ONT', false)
            ->assertSee('Deshabilitada', false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
