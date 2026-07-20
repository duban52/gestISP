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
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vinculación de equipos con contratos.
 *
 * Las ONTs importadas de la OLT y las cuentas importadas del router
 * llegan sin cliente. Aquí se comprueba que asignarlas después
 * respete las reglas del dominio: una sola ONT por contrato, nada
 * entre sucursales, y el serial del contrato en sincronía.
 */
class ContractLinkTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Branch $otraBranch;
    private User $admin;
    private Plan $plan;
    private Olt $olt;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->otraBranch = Branch::factory()->create();

        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($role);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $this->branch->id, 'name' => 'OLT pruebas',
            'ip_address' => '10.0.0.1', 'ssh_port' => 22, 'telnet_port' => 23,
            'snmp_port' => 161, 'read_snmp_comunity' => 'public',
            'username' => 'a', 'password' => 'b', 'brand' => 'huawei', 'uptime' => '0',
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router pruebas',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    private function contrato(array $atributos = []): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $atributos['branch_id'] ?? $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        return Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'cpe_sn' => null,
            'user_id' => $this->admin->id,
        ], $atributos));
    }

    private function ont(array $atributos = []): Ont
    {
        return Ont::create(array_merge([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt->id,
            'contract_id' => null,
            'slot' => 1, 'port' => 1,
            'onu_id' => random_int(1, 120),
            'sn' => 'HWTC-' . strtoupper(bin2hex(random_bytes(4))),
            'status' => 1,
        ], $atributos));
    }

    private function cuenta(array $atributos = []): PppoeAccount
    {
        return PppoeAccount::create(array_merge([
            'branch_id' => $this->branch->id,
            'router_id' => $this->router->id,
            'contract_id' => null,
            'username' => 'usuario' . random_int(1000, 9999),
            'password' => 'clave',
            'profile' => 'PLAN 100M',
            'disabled' => false,
        ], $atributos));
    }

    // ==================== ONT ====================

    public function test_vincula_una_ont_importada_con_un_contrato(): void
    {
        $ont = $this->ont(['sn' => 'HWTC-IMPORTADA']);
        $contrato = $this->contrato();

        $this->post(route('onts.link_contract', $ont), ['contract_id' => $contrato->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($contrato->id, $ont->fresh()->contract_id);
    }

    /**
     * Vincular debe dejar el contrato como si la ONT se hubiera
     * activado desde cero: la activación copia el serial al cpe_sn.
     */
    public function test_copia_el_serial_al_contrato(): void
    {
        $ont = $this->ont(['sn' => 'HWTC-ABC123']);
        $contrato = $this->contrato();

        $this->post(route('onts.link_contract', $ont), ['contract_id' => $contrato->id])
            ->assertRedirect();

        $this->assertSame('HWTC-ABC123', $contrato->fresh()->cpe_sn);
    }

    /**
     * Contract::ont() es hasOne: con dos ONTs el sistema mostraría
     * una cualquiera y la otra quedaría invisible.
     */
    public function test_un_contrato_no_admite_dos_onts(): void
    {
        $contrato = $this->contrato();
        $this->ont(['contract_id' => $contrato->id, 'sn' => 'HWTC-PRIMERA']);

        $segunda = $this->ont(['sn' => 'HWTC-SEGUNDA']);

        $this->post(route('onts.link_contract', $segunda), ['contract_id' => $contrato->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($segunda->fresh()->contract_id);
    }

    public function test_no_vincula_una_ont_que_ya_tiene_contrato(): void
    {
        $primero = $this->contrato();
        $segundo = $this->contrato();

        $ont = $this->ont(['contract_id' => $primero->id]);

        $this->post(route('onts.link_contract', $ont), ['contract_id' => $segundo->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($primero->id, $ont->fresh()->contract_id);
    }

    public function test_no_vincula_con_un_contrato_de_otra_sucursal(): void
    {
        $ont = $this->ont();
        $ajeno = $this->contrato(['branch_id' => $this->otraBranch->id]);

        $this->post(route('onts.link_contract', $ont), ['contract_id' => $ajeno->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($ont->fresh()->contract_id);
    }

    public function test_no_vincula_una_ont_de_otra_sucursal(): void
    {
        $ont = $this->ont(['branch_id' => $this->otraBranch->id]);
        $contrato = $this->contrato();

        $this->post(route('onts.link_contract', $ont), ['contract_id' => $contrato->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($ont->fresh()->contract_id);
    }

    public function test_desvincula_una_ont_y_limpia_el_serial(): void
    {
        $contrato = $this->contrato(['cpe_sn' => 'HWTC-XYZ']);
        $ont = $this->ont(['contract_id' => $contrato->id, 'sn' => 'HWTC-XYZ']);

        $this->delete(route('onts.unlink_contract', $ont))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($ont->fresh()->contract_id);
        $this->assertNull($contrato->fresh()->cpe_sn);
    }

    /**
     * Si el contrato apunta a otro equipo, borrar su serial sería
     * perder un dato que no corresponde a esta ONT.
     */
    public function test_al_desvincular_respeta_el_serial_de_otro_equipo(): void
    {
        $contrato = $this->contrato(['cpe_sn' => 'OTRO-EQUIPO']);
        $ont = $this->ont(['contract_id' => $contrato->id, 'sn' => 'HWTC-DISTINTA']);

        $this->delete(route('onts.unlink_contract', $ont))->assertRedirect();

        $this->assertSame('OTRO-EQUIPO', $contrato->fresh()->cpe_sn);
    }

    // ==================== PPPoE ====================

    public function test_vincula_una_cuenta_pppoe_con_un_contrato(): void
    {
        $cuenta = $this->cuenta();
        $contrato = $this->contrato();

        $this->post(route('pppoe.link_contract', $cuenta), ['contract_id' => $contrato->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($contrato->id, $cuenta->fresh()->contract_id);
    }

    /**
     * A diferencia de las ONTs, el esquema admite varias cuentas por
     * contrato (un cliente con dos servicios): no se bloquea.
     */
    public function test_un_contrato_admite_varias_cuentas_pppoe(): void
    {
        $contrato = $this->contrato();
        $this->cuenta(['contract_id' => $contrato->id]);

        $segunda = $this->cuenta();

        $this->post(route('pppoe.link_contract', $segunda), ['contract_id' => $contrato->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($contrato->id, $segunda->fresh()->contract_id);
    }

    public function test_no_vincula_una_cuenta_con_contrato_de_otra_sucursal(): void
    {
        $cuenta = $this->cuenta();
        $ajeno = $this->contrato(['branch_id' => $this->otraBranch->id]);

        $this->post(route('pppoe.link_contract', $cuenta), ['contract_id' => $ajeno->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($cuenta->fresh()->contract_id);
    }

    public function test_desvincula_una_cuenta_pppoe(): void
    {
        $contrato = $this->contrato();
        $cuenta = $this->cuenta(['contract_id' => $contrato->id]);

        $this->delete(route('pppoe.unlink_contract', $cuenta))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($cuenta->fresh()->contract_id);
    }

    /**
     * Vincular es solo base de datos: no debe tocar el Mikrotik ni
     * tumbar la sesión del cliente, como sí hace editar la cuenta.
     */
    public function test_vincular_no_toca_el_mikrotik(): void
    {
        $this->mock(\App\Services\MikrotikApiService::class, function ($mock) {
            $mock->shouldNotReceive('updatePppSecret');
            $mock->shouldNotReceive('dropActiveSession');
        });

        $cuenta = $this->cuenta();
        $contrato = $this->contrato();

        $this->post(route('pppoe.link_contract', $cuenta), ['contract_id' => $contrato->id])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    // ==================== Pantallas ====================

    public function test_la_vista_de_la_ont_ofrece_vincular_cuando_falta_el_contrato(): void
    {
        $ont = $this->ont();

        $this->get(route('onts.show', $ont))
            ->assertOk()
            ->assertSee('no está vinculada a ningún contrato', false)
            ->assertSee('Vincular contrato');
    }

    public function test_la_vista_de_la_ont_no_ofrece_vincular_si_ya_tiene_contrato(): void
    {
        $ont = $this->ont(['contract_id' => $this->contrato()->id]);

        $this->get(route('onts.show', $ont))
            ->assertOk()
            ->assertDontSee('no está vinculada a ningún contrato', false);
    }

    public function test_la_vista_de_la_cuenta_ofrece_vincular_cuando_falta_el_contrato(): void
    {
        $cuenta = $this->cuenta();

        $this->get(route('pppoe.show', $cuenta))
            ->assertOk()
            ->assertSee('no está vinculada a ningún contrato', false)
            ->assertSee('Vincular contrato');
    }

    /**
     * El buscador debe avisar de lo que el contrato ya tiene para
     * que no se asigne un equipo a un cliente que ya tiene otro.
     */
    public function test_el_buscador_informa_los_equipos_del_contrato(): void
    {
        $contrato = $this->contrato();
        $this->ont(['contract_id' => $contrato->id]);
        $this->cuenta(['contract_id' => $contrato->id]);

        $cliente = $contrato->client;

        $this->getJson(route('contratos.buscar', ['q' => $cliente->identity_number]))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $contrato->id,
                'tiene_ont' => true,
                'cuentas_pppoe' => 1,
            ]);
    }

    public function test_sin_permiso_no_se_puede_vincular(): void
    {
        $sinPermiso = User::factory()->create(['number_phone' => '3011111111']);
        $rol = Role::where('name', 'tecnico')->firstOrFail();
        $sinPermiso->assignRole($rol);
        $sinPermiso->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $ont = $this->ont();
        $contrato = $this->contrato();

        $this->actingAs($sinPermiso)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->post(route('onts.link_contract', $ont), ['contract_id' => $contrato->id])
            ->assertForbidden();

        $this->assertNull($ont->fresh()->contract_id);
    }
}
