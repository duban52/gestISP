<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\LineProfile;
use App\Models\Olt;
use App\Models\SrvProfile;
use App\Models\User;
use App\Models\VlanOlt;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «Sincronizar con la OLT»: VLANs y perfiles leidos del equipo.
 *
 * Las salidas son recortes de una OLT Huawei real.
 */
class OltCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private const VLANS = <<<'TXT'
olt_interya(config)#display vlan all
{ <cr>|vlanattr<K>|vlantype<E><mux,standard,smart,super> }:

  Command:
          display vlan all
  -----------------------------------------------------------------------
  VLAN   Type      Attribute  STND-Port NUM   SERV-Port NUM  VLAN-Con NUM
  -----------------------------------------------------------------------
     1   smart     common                 6               0             -
   201   smart     common                 1             725             -
  3333   smart     common                 6               0             -
  -----------------------------------------------------------------------
  Total: 3
  Note : STND-Port--standard port, SERV-Port--service virtual port,
         VLAN-Con--vlan-connect
TXT;

    private const LINE = <<<'TXT'
olt_interya(config)#display ont-lineprofile gpon all
  -----------------------------------------------------------------------------
  Profile-ID  Profile-name                                Binding times
  -----------------------------------------------------------------------------
  0           line-profile_default_0                      0
  1           SMARTOLT_FLEXIBLE_GPON                      1473
  200         line_profile_desemax                        4661
  -----------------------------------------------------------------------------
  Total: 3
TXT;

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
    }

    /** La OLT responde con este catalogo. */
    private function oltConCatalogo(array $catalogo): void
    {
        $this->mock(OltSshService::class, fn ($mock) => $mock->shouldReceive('getCatalogo')->andReturn($catalogo));
    }

    // ---------------------------------------------------------------- lectura

    public function test_lee_las_vlans(): void
    {
        $this->assertSame(
            [1 => 'smart', 201 => 'smart', 3333 => 'smart'],
            OltSshService::parseVlans(self::VLANS),
        );
    }

    public function test_lee_los_perfiles(): void
    {
        $this->assertSame(
            [0 => 'line-profile_default_0', 1 => 'SMARTOLT_FLEXIBLE_GPON', 200 => 'line_profile_desemax'],
            OltSshService::parsePerfiles(self::LINE),
        );
    }

    public function test_la_fila_que_sigue_a_la_paginacion_no_se_pierde(): void
    {
        // Al pulsar espacio la OLT borra el «More» con codigos de
        // terminal y escribe la fila siguiente en esa misma linea.
        $salida = "  81          OP156                                       0\n"
            . "  ---- More ( Press 'Q' to break ) ----\e[37D                                     \e[37D"
            . "  82          TPLINK123                                   0\n";

        $this->assertSame([81 => 'OP156', 82 => 'TPLINK123'], OltSshService::parsePerfiles($salida));
    }

    // ---------------------------------------------------------- sincronizar

    public function test_sincronizar_deja_el_catalogo_igual_al_de_la_olt(): void
    {
        VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => '201', 'name' => 'Internet residencial']);
        VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => '300', 'name' => 'Ya no existe']);
        LineProfile::create(['olt_id' => $this->olt->id, 'id_line_profile' => '200', 'name' => 'nombre viejo']);

        $this->oltConCatalogo([
            'vlan' => [1 => 'smart', 201 => 'smart'],
            'lineProfile' => [0 => 'line-profile_default_0', 200 => 'line_profile_desemax'],
            'srvProfile' => [200 => 'service_profile_desemax'],
        ]);

        $this->post(route('olts.sync_catalog', $this->olt))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'retiradas por no estar en la OLT: 300'));

        // Lo que faltaba se crea; lo que ya no esta en el equipo se retira.
        $this->assertEqualsCanonicalizing(
            ['1', '201'],
            VlanOlt::where('olt_id', $this->olt->id)->pluck('id_vlan')->all(),
        );
        $this->assertSame(1, SrvProfile::where('olt_id', $this->olt->id)->count());

        // La OLT no nombra las VLANs: el nombre puesto aqui se respeta.
        $this->assertSame('Internet residencial', VlanOlt::where('id_vlan', '201')->value('name'));
        $this->assertSame('VLAN 1', VlanOlt::where('id_vlan', '1')->value('name'));

        // Los perfiles SI: manda el nombre del equipo.
        $this->assertSame('line_profile_desemax', LineProfile::where('id_line_profile', '200')->value('name'));
    }

    public function test_una_lectura_vacia_no_borra_el_catalogo(): void
    {
        // Toda OLT tiene la VLAN 1: vacio es que la lectura fallo.
        VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => '201', 'name' => 'Internet']);

        $this->oltConCatalogo(['vlan' => [], 'lineProfile' => [0 => 'x'], 'srvProfile' => [0 => 'y']]);

        $this->post(route('olts.sync_catalog', $this->olt))->assertSessionHas('success');

        $this->assertSame(1, VlanOlt::where('olt_id', $this->olt->id)->count());
    }

    public function test_si_la_olt_no_responde_no_se_toca_nada(): void
    {
        VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => '201', 'name' => 'Internet']);

        $this->mock(OltSshService::class, fn ($mock) => $mock->shouldReceive('getCatalogo')
            ->andThrow(new \Exception('No se pudo autenticar')));

        $this->post(route('olts.sync_catalog', $this->olt))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No se pudo autenticar'));

        $this->assertSame(1, VlanOlt::where('olt_id', $this->olt->id)->count());
    }

    public function test_la_pantalla_de_la_olt_ofrece_sincronizar(): void
    {
        $this->get(route('olts.edit', $this->olt))
            ->assertOk()
            ->assertSee('Sincronizar con la OLT')
            ->assertSee(route('olts.sync_catalog', $this->olt), false)
            // el «procesando»: sin él la página queda un minuto quieta
            ->assertSee("form[data-procesando]", false);
    }
}
