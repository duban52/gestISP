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
use App\Services\OltStatistics;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Listado y ficha de OLTs.
 *
 * El fallo que se corrige: el listado esperaba a que el servidor
 * abriera una sesión SSH contra CADA OLT antes de pintar la primera
 * fila. Con una OLT apagada había que aguantar su tiempo de espera
 * completo mirando una tabla vacía.
 *
 * Por eso la prueba central es que el listado se dibuje SIN tocar los
 * equipos: si alguien vuelve a meter una consulta SSH ahí, el test
 * falla.
 */
class OltDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Branch $otraBranch;
    private User $admin;
    private Olt $olt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ENG']);
        $this->otraBranch = Branch::factory()->create();

        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->olt = $this->crearOlt($this->branch, 'OLT Gómez Plata');
    }

    private function crearOlt(Branch $branch, string $nombre): Olt
    {
        return Olt::create([
            'branch_id' => $branch->id, 'name' => $nombre,
            'ip_address' => '10.0.0.' . random_int(2, 250), 'ssh_port' => 22,
            'telnet_port' => 23, 'snmp_port' => 161, 'read_snmp_comunity' => 'public',
            'username' => 'admin', 'password' => 'x', 'brand' => 'huawei',
            'model' => 'MA5608T', 'uptime' => '10 days',
        ]);
    }

    private function ont(array $atributos = []): Ont
    {
        return Ont::create(array_merge([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt->id,
            'slot' => 0, 'port' => 1,
            'onu_id' => random_int(1, 120),
            'sn' => 'HWTC' . strtoupper(bin2hex(random_bytes(4))),
            'status' => 1,
        ], $atributos));
    }

    // ==================== El listado ====================

    public function test_el_listado_se_dibuja_sin_consultar_las_olts(): void
    {
        // Si alguien vuelve a meter una consulta SSH en el index, esto
        // falla: es justo el fallo que se corrigió.
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldNotReceive('getOltStatus');
        });

        $this->get(route('olts.index'))
            ->assertOk()
            ->assertSee('OLT Gómez Plata')
            ->assertSee($this->olt->ip_address);
    }

    public function test_el_listado_muestra_cuantas_onts_tiene_cada_olt(): void
    {
        $this->mock(OltSshService::class, fn ($mock) => $mock->shouldNotReceive('getOltStatus'));

        $this->ont();
        $this->ont();
        $this->ont();

        $respuesta = $this->get(route('olts.index'))->assertOk();

        $olts = $respuesta->viewData('olts');

        $this->assertSame(3, $olts->firstWhere('id', $this->olt->id)->onts_count);
        $respuesta->assertSee('ONT(s) registradas');
    }

    public function test_el_listado_solo_muestra_las_olts_de_la_sucursal(): void
    {
        $this->mock(OltSshService::class, fn ($mock) => $mock->shouldNotReceive('getOltStatus'));

        $this->crearOlt($this->otraBranch, 'OLT de otra sede');

        $this->get(route('olts.index'))
            ->assertOk()
            ->assertSee('OLT Gómez Plata')
            ->assertDontSee('OLT de otra sede');
    }

    // ============ Estado en vivo, una OLT a la vez ============

    public function test_el_estado_se_consulta_por_olt_y_guarda_cuando_se_miro(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('getOltStatus')->once()->andReturn([
                'status' => 'Conectado',
                'temperature' => '45 C',
                'uptime' => '30 days',
            ]);
        });

        $respuesta = $this->getJson(route('api.olts.status', $this->olt))->assertOk();

        $this->assertTrue($respuesta->json('conectada'));
        $this->assertSame('45 C', $respuesta->json('temperature'));

        // La marca de tiempo es lo que permite decir si lo que se ve
        // en pantalla es de hace un minuto o de ayer.
        $this->assertNotNull($this->olt->fresh()->status_checked_at);
    }

    public function test_una_olt_que_no_responde_queda_marcada_como_desconectada(): void
    {
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('getOltStatus')->andThrow(new \RuntimeException('timeout'));
        });

        $respuesta = $this->getJson(route('api.olts.status', $this->olt))->assertOk();

        // Responde igual: una OLT caída no puede tumbar la pantalla
        $this->assertFalse($respuesta->json('conectada'));
        $this->assertFalse((bool) $this->olt->fresh()->status);
        $this->assertNotNull($this->olt->fresh()->status_checked_at);
    }

    public function test_no_se_consulta_una_olt_de_otra_sucursal(): void
    {
        $ajena = $this->crearOlt($this->otraBranch, 'OLT ajena');

        $this->mock(OltSshService::class, fn ($mock) => $mock->shouldNotReceive('getOltStatus'));

        $this->getJson(route('api.olts.status', $ajena))->assertStatus(403);
    }

    // ==================== La ficha ====================

    public function test_la_ficha_resume_el_estado_de_las_onts(): void
    {
        $this->ont(['status' => 1, 'rx_power' => -19.5]);
        $this->ont(['status' => 1, 'rx_power' => -21.0]);
        $this->ont(['status' => 0, 'rx_power' => -26.0]);
        $this->ont(['status' => 0, 'admin_enabled' => false]);

        $respuesta = $this->get(route('olts.show', $this->olt))->assertOk();

        $conteos = $respuesta->viewData('resumen')['conteos'];

        $this->assertSame(4, $conteos['total']);
        $this->assertSame(2, $conteos['en_linea']);
        // La deshabilitada a propósito NO cuenta como caída
        $this->assertSame(1, $conteos['caidas']);
        $this->assertSame(1, $conteos['deshabilitadas']);
        // Disponibilidad sobre las que deberían dar servicio: 2 de 3
        $this->assertEqualsWithDelta(66.7, $conteos['disponibilidad'], 0.1);
    }

    public function test_la_ficha_calcula_la_potencia_solo_de_las_onts_en_linea(): void
    {
        $this->ont(['status' => 1, 'rx_power' => -20.0]);
        $this->ont(['status' => 1, 'rx_power' => -22.0]);
        // Apagada: su potencia es una lectura vieja y ensuciaría el promedio
        $this->ont(['status' => 0, 'rx_power' => -40.0]);

        $potencia = $this->get(route('olts.show', $this->olt))
            ->assertOk()
            ->viewData('resumen')['potencia'];

        $this->assertSame(2, $potencia['medidas']);
        $this->assertEqualsWithDelta(-21.0, $potencia['promedio'], 0.01);
        $this->assertEqualsWithDelta(-20.0, $potencia['mejor'], 0.01);
        $this->assertEqualsWithDelta(-22.0, $potencia['peor'], 0.01);
    }

    public function test_la_ficha_clasifica_las_onts_por_calidad_de_senal(): void
    {
        $this->ont(['status' => 1, 'rx_power' => -5.0]);   // saturación
        $this->ont(['status' => 1, 'rx_power' => -18.0]);  // óptima
        $this->ont(['status' => 1, 'rx_power' => -23.5]);  // aceptable
        $this->ont(['status' => 1, 'rx_power' => -26.0]);  // débil
        $this->ont(['status' => 1, 'rx_power' => -29.0]);  // crítica

        $calidad = $this->get(route('olts.show', $this->olt))
            ->assertOk()
            ->viewData('resumen')['calidad'];

        $this->assertSame(1, $calidad['saturacion']['cantidad']);
        $this->assertSame(1, $calidad['optima']['cantidad']);
        $this->assertSame(1, $calidad['aceptable']['cantidad']);
        $this->assertSame(1, $calidad['debil']['cantidad']);
        $this->assertSame(1, $calidad['critica']['cantidad']);
    }

    public function test_la_ficha_muestra_la_ocupacion_de_cada_puerto_pon(): void
    {
        $this->ont(['slot' => 0, 'port' => 1, 'status' => 1]);
        $this->ont(['slot' => 0, 'port' => 1, 'status' => 0]);
        $this->ont(['slot' => 0, 'port' => 2, 'status' => 1]);

        $puertos = $this->get(route('olts.show', $this->olt))
            ->assertOk()
            ->viewData('resumen')['puertos'];

        $this->assertCount(2, $puertos);

        $primero = collect($puertos)->firstWhere('puerto', '0/1');

        $this->assertSame(2, $primero['total']);
        $this->assertSame(1, $primero['en_linea']);
    }

    public function test_la_ficha_lista_las_onts_con_peor_senal_primero(): void
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => Plan::factory()->create([
                'branch_id' => $this->branch->id,
                'user_id' => $this->admin->id,
            ])->id,
            'contract_number' => 'ENG000900',
            'user_id' => $this->admin->id,
        ]);

        $this->ont(['status' => 1, 'rx_power' => -15.0]);
        $mala = $this->ont(['status' => 1, 'rx_power' => -28.5, 'contract_id' => $contrato->id]);

        $peores = $this->get(route('olts.show', $this->olt))
            ->assertOk()
            ->viewData('resumen')['peores'];

        // La peor va primero: es la lista para salir a revisar
        $this->assertSame($mala->id, $peores->first()->id);

        $this->get(route('olts.show', $this->olt))->assertSee('ENG000900');
    }

    public function test_no_se_puede_ver_la_ficha_de_una_olt_de_otra_sucursal(): void
    {
        $ajena = $this->crearOlt($this->otraBranch, 'OLT ajena');

        $this->get(route('olts.show', $ajena))->assertStatus(403);
    }

    // ==================== Umbrales ====================

    public function test_los_umbrales_de_potencia_clasifican_como_manda_la_norma(): void
    {
        // Ópticas clase B+/C+: el receptor deja de funcionar cerca de
        // −28 dBm y se satura por encima de −8.
        $this->assertSame('saturacion', OltStatistics::bandaDe(-5.0));
        $this->assertSame('optima', OltStatistics::bandaDe(-18.0));
        $this->assertSame('aceptable', OltStatistics::bandaDe(-24.0));
        $this->assertSame('debil', OltStatistics::bandaDe(-26.5));
        $this->assertSame('critica', OltStatistics::bandaDe(-30.0));
        $this->assertNull(OltStatistics::bandaDe(null));
    }
}
