<?php

namespace Tests\Feature\TechnicalOrders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Services\OltSnmpService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Control de calidad al verificar una orden: la potencia con la que
 * quedo el servicio. El MISMO dato que el listado de ONTs autorizadas
 * (onts.rx_power) y las mismas bandas y colores (OltStatistics::bandaDe).
 */
class VerificationOpticalPowerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Contract $contrato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => Client::factory()->create([
                'branch_id' => $this->branch->id,
                'user_id' => $this->admin->id,
            ])->id,
            'plan_id' => Plan::create([
                'name' => 'Plan 100M',
                'user_id' => $this->admin->id,
                'branch_id' => $this->branch->id,
            ])->id,
            'status' => 'Activo',
            'user_id' => $this->admin->id,
        ]);

        TechnicalOrder::create([
            'contract_id' => $this->contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->admin->id,
            'created_by' => $this->admin->id,
            'type' => TechnicalOrder::SERVICIO,
            'detail' => 'Instalacion de servicio',
            'status' => 'Prefinalizada',
            'initial_comment' => 'Instalacion',
        ]);
    }

    /** La ONT del contrato, con la potencia que le dejo el sondeo. */
    private function ontConLectura(?float $rxPower): Ont
    {
        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'name' => 'OLT de prueba',
            'ip_address' => '10.0.0.10',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'root', 'password' => 'admin',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        // Sin historial en ont_metrics a proposito: el listado de
        // autorizadas no lo usa, y una ONT sin historial mostraba
        // «sin lecturas» aqui mientras alli tenia potencia.
        return Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $olt->id,
            'contract_id' => $this->contrato->id,
            'slot' => '1', 'port' => '2', 'onu_id' => 7,
            'sn' => 'HWTC12345678',
            'rx_power' => $rxPower,
            'status' => $rxPower !== null ? 1 : 0,
        ]);
    }

    public function test_muestra_la_potencia_con_el_color_de_su_banda(): void
    {
        // −26,1 dBm es «Débil»: amarillo, no el verde de una buena señal.
        $this->ontConLectura(-26.1);

        $this->get(route('technicals_orders.verification'))
            ->assertOk()
            ->assertSee('Potencia óptica del servicio')
            ->assertSee('-26.10 dBm')
            ->assertSee('badge-warning', false)
            ->assertSee('Débil');
    }

    public function test_una_ont_caida_lo_dice(): void
    {
        $this->ontConLectura(null);

        $this->get(route('technicals_orders.verification'))
            ->assertOk()
            ->assertSee('Caída')
            ->assertSee('La ONT no está en línea.');
    }

    public function test_leer_ahora_devuelve_la_banda_calculada_en_el_servidor(): void
    {
        $ont = $this->ontConLectura(-26.1);

        // El SNMP simulado deja la potencia que leeria del equipo.
        $this->mock(OltSnmpService::class, fn ($mock) => $mock->shouldReceive('syncSingleOntPower')
            ->andReturnUsing(function ($olt, $o) {
                $o->update(['rx_power' => -19.66, 'status' => 1]);

                return true;
            }));

        $this->get(route('technicals_orders.verification'))
            ->assertSee(route('onts.sync-power', $ont), false);

        $this->postJson(route('onts.sync-power', $ont))
            ->assertOk()
            ->assertJson(['ok' => true, 'color' => 'success', 'etiqueta' => 'Óptima']);
    }

    public function test_sin_ont_vinculada_no_aparece_la_seccion(): void
    {
        $this->get(route('technicals_orders.verification'))
            ->assertOk()
            ->assertDontSee('Potencia óptica del servicio');
    }
}
