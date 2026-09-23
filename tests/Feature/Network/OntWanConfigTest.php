<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Configuración WAN de la ONT por OMCI.
 *
 * QUÉ SE DEFIENDE
 * ---------------
 * 1. Que a la OLT le llegue el comando EXACTO, con la cuenta intacta:
 *    un espacio de más en una contraseña parte la línea y la consola
 *    ejecuta media configuración.
 * 2. Que una ONT incompatible no se reporte como éxito. La OLT acepta
 *    el comando y la ONT lo ignora; si eso se cuenta como bien hecho,
 *    el técnico se va del sitio con el cliente sin servicio.
 * 3. Que un fallo al mandar la WAN durante la activación NO tumbe la
 *    activación: la ONT ya quedó autorizada en el equipo.
 */
class OntWanConfigTest extends TestCase
{
    use RefreshDatabase;

    private Ont $ont;
    private Contract $contrato;
    private Branch $sucursal;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $admin = User::factory()->create(['number_phone' => '3000000000']);
        $admin->assignRole($rol);
        $admin->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($admin)->withSession([
            'branch_id' => $this->sucursal->id,
            'current_role_id' => $rol->id,
        ]);

        $cliente = Client::factory()->create([
            'branch_id' => $this->sucursal->id,
            'number_phone' => '3111111111',
            'aditional_phone' => '3111111112',
            'user_id' => $admin->id,
        ]);

        $plan = Plan::factory()->create(['branch_id' => $this->sucursal->id, 'user_id' => $admin->id]);

        $this->contrato = Contract::factory()->create([
            'branch_id' => $this->sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $admin->id,
        ]);

        $olt = Olt::create([
            'branch_id' => $this->sucursal->id,
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

        $this->router = Router::create([
            'branch_id' => $this->sucursal->id, 'name' => 'Router de pruebas',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);

        $this->ont = Ont::create([
            'branch_id' => $this->sucursal->id,
            'olt_id' => $olt->id,
            'contract_id' => $this->contrato->id,
            'slot' => 3,
            'port' => 13,
            'onu_id' => 11,
            'vlan' => 150,
            'sn' => 'TEST-SN-WAN',
            'status' => 1,
        ]);
    }

    // ==================== El comando que se manda ====================

    public function test_el_comando_pppoe_es_el_que_espera_la_olt(): void
    {
        $this->assertSame(
            'ont ipconfig 13 11 pppoe vlan 150 priority 0'
            . ' user-account username duban_restrepo_egp000005 password restrepo_10427',
            OltSshService::comandoWan($this->ont, [
                'modo' => 'pppoe',
                'vlan' => 150,
                'priority' => 0,
                'username' => 'duban_restrepo_egp000005',
                'password' => 'restrepo_10427',
            ]),
        );
    }

    public function test_en_dhcp_no_viaja_ninguna_cuenta(): void
    {
        $this->assertSame(
            'ont ipconfig 13 11 dhcp vlan 150 priority 0',
            OltSshService::comandoWan($this->ont, ['modo' => 'dhcp', 'vlan' => 150, 'priority' => 0]),
        );
    }

    public function test_una_credencial_con_espacios_no_parte_el_comando(): void
    {
        // Un espacio convertiría el resto de la contraseña en
        // argumentos sueltos y la OLT ejecutaría otra cosa.
        $comando = OltSshService::comandoWan($this->ont, [
            'modo' => 'pppoe',
            'vlan' => 150,
            'priority' => 0,
            'username' => 'juan perez',
            'password' => 'clave " ; reboot',
        ]);

        $this->assertSame(
            'ont ipconfig 13 11 pppoe vlan 150 priority 0 user-account username juanperez password clavereboot',
            $comando,
        );
    }

    public function test_el_comando_estatico_lleva_la_vlan_al_final(): void
    {
        // En estatica la consola pide la VLAN DESPUES del
        // direccionamiento y los DNS; con la VLAN delante rechaza la
        // linea entera.
        $this->assertSame(
            'ont ipconfig 13 11 static ip-address 192.168.150.50 mask 255.255.255.0'
            . ' gateway 192.168.150.1 pri-dns 8.8.8.8 slave-dns 1.1.1.1 vlan 150 priority 0',
            OltSshService::comandoWan($this->ont, [
                'modo' => 'static',
                'vlan' => 150,
                'priority' => 0,
                'ip_address' => '192.168.150.50',
                'mask' => '255.255.255.0',
                'gateway' => '192.168.150.1',
                'pri_dns' => '8.8.8.8',
                'slave_dns' => '1.1.1.1',
            ]),
        );
    }

    public function test_sin_dns_secundario_la_palabra_no_queda_suelta(): void
    {
        // slave-dns sin valor haria que la consola rechazara todo.
        $this->assertSame(
            'ont ipconfig 13 11 static ip-address 192.168.150.50 mask 255.255.255.0'
            . ' gateway 192.168.150.1 pri-dns 8.8.8.8 vlan 150 priority 0',
            OltSshService::comandoWan($this->ont, [
                'modo' => 'static',
                'vlan' => 150,
                'priority' => 0,
                'ip_address' => '192.168.150.50',
                'mask' => '255.255.255.0',
                'gateway' => '192.168.150.1',
                'pri_dns' => '8.8.8.8',
                'slave_dns' => '',
            ]),
        );
    }

    // ==================== El perfil WAN ====================

    public function test_al_activar_se_ata_el_perfil_wan_por_defecto(): void
    {
        $this->cuentaPppoe('egp000005_duban', 'clave123');

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('activateOnt')->andReturn(['ont_id' => 7, 'service_port' => 123]);
            $mock->shouldReceive('getOntIfIndexes')->andReturn([]);
            $mock->shouldReceive('setOntWanConfig')
                ->once()
                ->withArgs(fn ($olt, $ont, $datos) => $datos['profile_id'] === OltSshService::PERFIL_WAN)
                ->andReturn(['aplicado' => true, 'estado' => [], 'aviso' => null]);
        });

        $this->post(route('onts.activate'), $this->datosDeActivacion(['enviar_wan' => 1]))
            ->assertRedirect();
    }

    public function test_el_perfil_wan_se_puede_dejar_vacio(): void
    {
        // Hay OLT donde el servicio ya viene del srv-profile y el
        // comando sobra: mandarlo con un perfil inexistente haría
        // fallar una configuración que sin él habría quedado bien.
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntWanConfig')
                ->once()
                ->withArgs(fn ($olt, $ont, $datos) => empty($datos['profile_id']))
                ->andReturn(['aplicado' => true, 'estado' => [], 'aviso' => null]);
        });

        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'pppoe',
            'vlan' => 150,
            'priority' => 0,
            'profile_id' => '',
            'username' => 'egp000005_duban',
            'password' => 'clave123',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // ==================== Leer lo que la ONT reporta ====================

    public function test_lee_la_configuracion_que_devuelve_la_olt(): void
    {
        $salida = "  Command:\n"
            . "          display ont ipconfig 13 11\n"
            . "  --------------------------------------------------------------------\n"
            . "  ONT IP host index        : 0\n"
            . "  ONT config type          : PPPoE\n"
            . "  ONT IP                   : 192.168.21.189\n"
            . "  ONT subnet mask          : 255.255.255.255\n"
            . "  ONT gateway              : 172.31.20.20\n"
            . "  ONT MAC                  : 80F1-A473-22D6\n"
            . "  ONT manage VLAN          : 150\n"
            . "  PPPoE account mode       : OLT-input\n"
            . "  PPPoE username           : duban_restrepo_egp000005\n"
            . "  --------------------------------------------------------------------\n";

        $estado = OltSshService::parseIpConfig($salida);

        $this->assertSame('PPPoE', $estado['ONT config type']);
        $this->assertSame('192.168.21.189', $estado['ONT IP']);
        $this->assertSame('duban_restrepo_egp000005', $estado['PPPoE username']);
    }

    public function test_la_olt_llama_static_config_a_la_estatica(): void
    {
        // Si se comparara con "static" a secas, toda configuracion
        // estatica se reportaria como ONT incompatible.
        $salida = "  ONT config type          : Static config\n"
            . "  ONT IP                   : 192.168.150.50\n"
            . "  ONT subnet mask          : 255.255.255.0\n";

        $estado = OltSshService::parseIpConfig($salida);

        $this->assertSame('Static config', $estado['ONT config type']);
        $this->assertSame('192.168.150.50', $estado['ONT IP']);
    }

    // ==================== Desde la ficha de la ONT ====================

    public function test_la_ficha_propone_la_cuenta_del_contrato(): void
    {
        $cuenta = $this->cuentaPppoe('egp000005_duban', 'clave123');

        $this->get(route('onts.show', $this->ont))
            ->assertOk()
            ->assertSee($cuenta->username)
            ->assertSee('Solo se aplica a ONT compatibles');
    }

    public function test_propone_la_cuenta_habilitada_y_no_una_vieja_deshabilitada(): void
    {
        $this->cuentaPppoe('cuenta_vieja', 'x', deshabilitada: true);
        $this->cuentaPppoe('cuenta_al_dia', 'y');

        $this->get(route('onts.show', $this->ont))
            ->assertOk()
            ->assertSee('cuenta_al_dia');
    }

    public function test_enviar_la_wan_avisa_cuando_la_ont_la_toma(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntWanConfig')
                ->once()
                ->withArgs(fn ($olt, $ont, $datos) => $datos['username'] === 'egp000005_duban'
                    && $datos['vlan'] == 150)
                ->andReturn([
                    'aplicado' => true,
                    'estado' => ['PPPoE username' => 'egp000005_duban', 'ONT IP' => '192.168.21.189'],
                    'aviso' => null,
                ]);
        });

        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'pppoe',
            'vlan' => 150,
            'priority' => 0,
            'username' => 'egp000005_duban',
            'password' => 'clave123',
        ])->assertRedirect();

        $this->assertStringContainsString('192.168.21.189', session('success'));
    }

    public function test_una_ont_incompatible_no_se_canta_como_exito(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntWanConfig')
                ->andReturn(['aplicado' => false, 'estado' => ['ONT config type' => 'Static'], 'aviso' => null]);
        });

        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'pppoe',
            'vlan' => 150,
            'priority' => 0,
            'username' => 'egp000005_duban',
            'password' => 'clave123',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertStringContainsString('no sea compatible', session('error'));
    }

    public function test_en_dhcp_no_se_exige_cuenta(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntWanConfig')
                ->once()
                ->andReturn(['aplicado' => true, 'estado' => ['ONT config type' => 'DHCP'], 'aviso' => null]);
        });

        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'dhcp',
            'vlan' => 150,
            'priority' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_la_estatica_se_manda_con_su_direccionamiento(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntWanConfig')
                ->once()
                ->withArgs(fn ($olt, $ont, $datos) => $datos['modo'] === 'static'
                    && $datos['ip_address'] === '192.168.150.50'
                    && $datos['gateway'] === '192.168.150.1')
                ->andReturn([
                    'aplicado' => true,
                    'estado' => ['ONT config type' => 'Static config', 'ONT IP' => '192.168.150.50'],
                    'aviso' => null,
                ]);
        });

        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'static',
            'vlan' => 150,
            'priority' => 0,
            'profile_id' => 1,
            'ip_address' => '192.168.150.50',
            'mask' => '255.255.255.0',
            'gateway' => '192.168.150.1',
            'pri_dns' => '8.8.8.8',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertStringContainsString('192.168.150.50', session('success'));
    }

    public function test_una_ip_mal_escrita_no_llega_al_equipo(): void
    {
        // Un direccionamiento torcido deja la ONT incomunicada y
        // obliga a ir hasta el sitio: se para antes de tocar la OLT.
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldNotReceive('setOntWanConfig');
        });

        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'static',
            'vlan' => 150,
            'priority' => 0,
            'ip_address' => '192.168.150',
            'mask' => '255.255.255.0',
            'gateway' => 'la del router',
            'pri_dns' => '8.8.8.8',
        ])->assertSessionHasErrors(['ip_address', 'gateway']);
    }

    public function test_en_estatica_el_direccionamiento_es_obligatorio(): void
    {
        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'static',
            'vlan' => 150,
            'priority' => 0,
        ])->assertSessionHasErrors(['ip_address', 'mask', 'gateway', 'pri_dns']);
    }

    /**
     * El perfil ya atado hace que la OLT responda "Failure".
     *
     * Rendirse ahi dejaria a medias una reconfiguracion que por lo
     * demas iba bien: quien decide es la relectura del final.
     */
    public function test_si_el_perfil_no_se_ata_pero_la_ont_lo_toma_se_avisa_sin_fallar(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('setOntWanConfig')->andReturn([
                'aplicado' => true,
                'estado' => ['ONT config type' => 'DHCP', 'ONT IP' => '10.150.2.247'],
                'aviso' => 'La OLT no ato el perfil WAN 1: Failure: The WAN config already exists',
            ]);
        });

        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'dhcp',
            'vlan' => 150,
            'priority' => 0,
            'profile_id' => 1,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertStringContainsString('no ato el perfil WAN', session('success'));
    }

    public function test_sin_cuenta_pppoe_el_modo_pppoe_se_rechaza(): void
    {
        $this->post(route('onts.wan', $this->ont), [
            'modo' => 'pppoe',
            'vlan' => 150,
            'priority' => 0,
        ])->assertSessionHasErrors(['username', 'password']);
    }

    // ==================== Al activar desde no autorizadas ====================

    public function test_al_activar_se_le_manda_la_cuenta_del_contrato(): void
    {
        $this->cuentaPppoe('egp000005_duban', 'clave123');

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('activateOnt')->andReturn(['ont_id' => 7, 'service_port' => 123]);
            $mock->shouldReceive('getOntIfIndexes')->andReturn([]);
            $mock->shouldReceive('setOntWanConfig')
                ->once()
                ->withArgs(fn ($olt, $ont, $datos) => $datos['modo'] === 'pppoe'
                    && $datos['username'] === 'egp000005_duban'
                    && $datos['password'] === 'clave123')
                ->andReturn(['aplicado' => true, 'estado' => [], 'aviso' => null]);
        });

        $this->post(route('onts.activate'), $this->datosDeActivacion(['enviar_wan' => 1]))
            ->assertRedirect();

        $this->assertStringContainsString('egp000005_duban', session('success'));
    }

    public function test_sin_marcar_la_casilla_no_se_toca_la_wan(): void
    {
        $this->cuentaPppoe('egp000005_duban', 'clave123');

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('activateOnt')->andReturn(['ont_id' => 7, 'service_port' => 123]);
            $mock->shouldReceive('getOntIfIndexes')->andReturn([]);
            $mock->shouldNotReceive('setOntWanConfig');
        });

        $this->post(route('onts.activate'), $this->datosDeActivacion())->assertRedirect();
    }

    /**
     * La ONT YA quedó autorizada en la OLT cuando se manda la WAN.
     *
     * Devolver un error aquí haría creer que la activación no se hizo,
     * y alguien la repetiría contra un equipo que ya está dado de alta.
     */
    public function test_si_la_wan_falla_la_activacion_sigue_siendo_un_exito(): void
    {
        $this->cuentaPppoe('egp000005_duban', 'clave123');

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('activateOnt')->andReturn(['ont_id' => 7, 'service_port' => 123]);
            $mock->shouldReceive('getOntIfIndexes')->andReturn([]);
            $mock->shouldReceive('setOntWanConfig')->andThrow(new \Exception('La OLT rechazó la configuración WAN'));
        });

        $this->post(route('onts.activate'), $this->datosDeActivacion(['enviar_wan' => 1]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Ont::where('sn', 'HWTC-NUEVA-WAN')->first());
        $this->assertStringContainsString('NO se pudo enviar', session('success'));
    }

    public function test_sin_contrato_no_se_intenta_mandar_nada(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('activateOnt')->andReturn(['ont_id' => 7, 'service_port' => 123]);
            $mock->shouldReceive('getOntIfIndexes')->andReturn([]);
            $mock->shouldNotReceive('setOntWanConfig');
        });

        $this->post(route('onts.activate'), $this->datosDeActivacion([
            'enviar_wan' => 1,
            'sin_contrato' => 1,
            'contract_id' => null,
            'description' => 'Repetidor de la empresa',
        ]))->assertRedirect();
    }

    /** @param array<string, mixed> $extra */
    private function datosDeActivacion(array $extra = []): array
    {
        return array_merge([
            'olt_id' => $this->ont->olt_id,
            'ont_sn' => 'HWTC-NUEVA-WAN',
            'ont_location' => '0/3/13',
            'vlan' => 150,
            'ont_lineprofile' => 10,
            'ont_srvprofile' => 10,
            'contract_id' => $this->contrato->id,
            'description' => 'Cliente de prueba',
        ], $extra);
    }

    private function cuentaPppoe(string $usuario, string $clave, bool $deshabilitada = false): PppoeAccount
    {
        return PppoeAccount::create([
            'branch_id' => $this->sucursal->id,
            'router_id' => $this->router->id,
            'contract_id' => $this->contrato->id,
            'mikrotik_id' => '*' . fake()->unique()->numerify('###'),
            'username' => $usuario,
            'password' => $clave,
            'profile' => 'PLAN 150M',
            'service' => 'pppoe',
            'disabled' => $deshabilitada,
        ]);
    }
}
