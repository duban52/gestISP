<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\User;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Service-port de las ONTs importadas.
 *
 * Las ONTs que se importan desde la OLT llegan sin service-port
 * (la OLT no lo expone por SNMP). Como eliminar y mover ejecutan
 * "undo service-port {id}" en el equipo, el sistema debe
 * resolverlo antes: si no, enviaría un comando incompleto.
 */
class OntServicePortTest extends TestCase
{
    use RefreshDatabase;

    private Ont $ont;
    private Olt $olt;

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
            'branch_id' => (string) $branch->id,
            'current_role_id' => (string) $role->id,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $branch->id,
            'name' => 'OLT de pruebas',
            'ip_address' => '10.0.0.1',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'admin', 'password' => 'secret',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        // ONT importada: sin service_port ni contrato
        $this->ont = Ont::create([
            'branch_id' => $branch->id,
            'olt_id' => $this->olt->id,
            'contract_id' => null,
            'slot' => 1,
            'port' => 2,
            'onu_id' => 5,
            'if_index' => 4194304000,
            'sn' => 'HWTC-IMPORTADA',
            'status' => 1,
        ]);
    }

    public function test_resuelve_el_service_port_antes_de_eliminar_una_ont_importada(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            // Primero lo consulta a la OLT...
            $mock->shouldReceive('resolveServicePort')->once()->andReturn(2312);
            // ...y solo entonces elimina
            $mock->shouldReceive('deleteOnt')->once();
        });

        $this->delete(route('onts.destroy', $this->ont))->assertRedirect();

        // Quedó guardado: no habrá que volver a consultarlo
        $this->assertDatabaseMissing('onts', ['sn' => 'HWTC-IMPORTADA']);
    }

    public function test_no_elimina_si_la_olt_no_reporta_el_service_port(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('resolveServicePort')->once()->andReturn(null);
            // deleteOnt NO debe ejecutarse: enviaría un comando
            // incompleto al equipo
            $mock->shouldNotReceive('deleteOnt');
        });

        $this->delete(route('onts.destroy', $this->ont))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('onts', ['sn' => 'HWTC-IMPORTADA']);
    }

    public function test_no_vuelve_a_consultar_si_la_ont_ya_tiene_service_port(): void
    {
        $this->ont->update(['service_port' => 2299]);

        $this->mock(OltSshService::class, function ($mock) {
            // No debe consultarse: el dato ya está
            $mock->shouldNotReceive('resolveServicePort');
            $mock->shouldReceive('deleteOnt')->once();
        });

        $this->delete(route('onts.destroy', $this->ont))->assertRedirect();
    }

    public function test_la_vista_indica_cuando_el_service_port_no_esta_resuelto(): void
    {
        $this->get(route('onts.show', $this->ont))
            ->assertOk()
            ->assertSee('Sin resolver', false);

        $this->ont->update(['service_port' => 2312]);

        $this->get(route('onts.show', $this->ont))
            ->assertOk()
            ->assertSee('2312', false)
            ->assertDontSee('Sin resolver', false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
