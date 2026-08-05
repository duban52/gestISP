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
 * Dos funciones de soporte del módulo de red:
 *
 *  - Reiniciar una ONT desde su ficha (sin tocar su configuración).
 *  - Ver las ONTs de una OLT concreta desde el enlace "ONUs" del
 *    listado de OLTs.
 *
 * El envío real a la OLT se sustituye por un doble: las pruebas no
 * pueden depender de un equipo de la red.
 */
class OntRebootAndFilterTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $this->user->assignRole($this->role);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $this->role->id]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->role->id,
        ]);
    }

    private function olt(array $extra = []): Olt
    {
        return Olt::create(array_merge([
            'name' => 'OLT Pruebas',
            'ip_address' => '10.0.0.1',
            'branch_id' => $this->branch->id,
            'username' => 'admin',
            'password' => 'secreto',
            'ssh_port' => 22,
            'brand' => 'huawei',
            'model' => 'MA5608T',
            'uptime' => '0',
        ], $extra));
    }

    private function ont(Olt $olt, array $extra = []): Ont
    {
        return Ont::create(array_merge([
            'olt_id' => $olt->id,
            'branch_id' => $this->branch->id,
            'sn' => 'HWTC-11112222',
            'slot' => 1,
            'port' => 2,
            'onu_id' => 5,
        ], $extra));
    }

    // ==================== Reinicio ====================

    public function test_la_ficha_de_la_ont_ofrece_el_boton_de_reinicio(): void
    {
        $ont = $this->ont($this->olt());

        $respuesta = $this->get(route('onts.show', $ont));

        $respuesta->assertOk();
        $respuesta->assertSee('Reiniciar ONT');
        $respuesta->assertSee(route('onts.reboot', $ont), false);
    }

    public function test_reiniciar_envia_la_orden_a_la_olt(): void
    {
        $olt = $this->olt();
        $ont = $this->ont($olt);

        $this->mock(OltSshService::class, function ($mock) use ($ont) {
            $mock->shouldReceive('rebootOnt')
                ->once()
                ->withArgs(fn ($oltArg, $ontArg) => $ontArg->id === $ont->id);
        });

        $respuesta = $this->from(route('onts.show', $ont))
            ->post(route('onts.reboot', $ont));

        $respuesta->assertRedirect(route('onts.show', $ont));
        $respuesta->assertSessionHas('success');
    }

    public function test_si_la_olt_falla_se_informa_sin_romper_la_pagina(): void
    {
        $olt = $this->olt();
        $ont = $this->ont($olt);

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('rebootOnt')
                ->once()
                ->andThrow(new \Exception('No se pudo conectar a la OLT'));
        });

        $respuesta = $this->from(route('onts.show', $ont))
            ->post(route('onts.reboot', $ont));

        $respuesta->assertRedirect(route('onts.show', $ont));
        $respuesta->assertSessionHas('error');
    }

    public function test_el_reinicio_no_altera_los_datos_de_la_ont(): void
    {
        $olt = $this->olt();
        $ont = $this->ont($olt, ['admin_enabled' => true]);

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('rebootOnt')->once();
        });

        $this->post(route('onts.reboot', $ont));

        $ont->refresh();
        // Reiniciar NO deshabilita ni reconfigura: la ficha queda igual
        $this->assertTrue((bool) $ont->admin_enabled);
        $this->assertSame(5, (int) $ont->onu_id);
        $this->assertSame('HWTC-11112222', $ont->sn);
    }

    // ============ Enlace "ONUs" del listado de OLTs ============

    public function test_el_listado_filtra_las_onts_de_una_olt(): void
    {
        $olt1 = $this->olt(['name' => 'OLT Norte', 'ip_address' => '10.0.0.1']);
        $olt2 = $this->olt(['name' => 'OLT Sur', 'ip_address' => '10.0.0.2']);

        $this->ont($olt1, ['sn' => 'HWTC-AAAA1111']);
        $this->ont($olt2, ['sn' => 'HWTC-BBBB2222']);

        $respuesta = $this->get(route('onts.authorized', ['olt' => $olt1->id]));

        $respuesta->assertOk();
        $respuesta->assertSee('HWTC-AAAA1111');
        $respuesta->assertDontSee('HWTC-BBBB2222');
        $respuesta->assertSee('OLT Norte');
    }

    public function test_sin_filtro_se_siguen_viendo_todas(): void
    {
        $olt1 = $this->olt(['name' => 'OLT Norte', 'ip_address' => '10.0.0.1']);
        $olt2 = $this->olt(['name' => 'OLT Sur', 'ip_address' => '10.0.0.2']);

        $this->ont($olt1, ['sn' => 'HWTC-AAAA1111']);
        $this->ont($olt2, ['sn' => 'HWTC-BBBB2222']);

        $respuesta = $this->get(route('onts.authorized'));

        $respuesta->assertOk();
        $respuesta->assertSee('HWTC-AAAA1111');
        $respuesta->assertSee('HWTC-BBBB2222');
    }

    public function test_no_deja_ver_las_onts_de_otra_sucursal(): void
    {
        $otraSucursal = Branch::factory()->create();

        $oltAjena = Olt::create([
            'name' => 'OLT Ajena',
            'ip_address' => '10.9.9.9',
            'branch_id' => $otraSucursal->id,
            'username' => 'admin',
            'password' => 'secreto',
            'ssh_port' => 22,
            'brand' => 'huawei',
            'model' => 'MA5608T',
            'uptime' => '0',
        ]);

        Ont::create([
            'olt_id' => $oltAjena->id,
            'branch_id' => $otraSucursal->id,
            'sn' => 'HWTC-SECRETA1',
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
        ]);

        $respuesta = $this->get(route('onts.authorized', ['olt' => $oltAjena->id]));

        $respuesta->assertOk();
        $respuesta->assertDontSee('HWTC-SECRETA1');
    }

    // ============ Cifras y filtros del listado ============

    /**
     * Las cifras se calculan sobre lo FILTRADO.
     *
     * Si alguien está mirando una OLT concreta y la cabecera muestra
     * los números de toda la sucursal, no significan nada: lo que se
     * quiere saber es cómo está ESA OLT.
     */
    public function test_las_cifras_se_calculan_sobre_lo_filtrado(): void
    {
        $olt1 = $this->olt(['name' => 'OLT Norte', 'ip_address' => '10.0.0.1']);
        $olt2 = $this->olt(['name' => 'OLT Sur', 'ip_address' => '10.0.0.2']);

        $this->ont($olt1, ['sn' => 'HWTC-N1', 'onu_id' => 1, 'status' => '1', 'rx_power' => '-19.0']);
        $this->ont($olt1, ['sn' => 'HWTC-N2', 'onu_id' => 2, 'status' => '0']);
        $this->ont($olt2, ['sn' => 'HWTC-S1', 'onu_id' => 1, 'status' => '1', 'rx_power' => '-20.0']);

        $resumen = $this->get(route('onts.authorized', ['olt' => $olt1->id]))
            ->assertOk()
            ->viewData('resumen');

        $this->assertSame(2, $resumen['total']);
        $this->assertSame(1, $resumen['en_linea']);
        $this->assertSame(1, $resumen['caidas']);
        $this->assertSame(50.0, $resumen['disponibilidad']);
    }

    /**
     * Una ONT deshabilitada a propósito no hunde la disponibilidad.
     *
     * Cortar por facturación no es una falla de red. Si contara, un mes
     * de muchos cortes haría parecer que la red se está cayendo.
     */
    public function test_las_deshabilitadas_no_cuentan_como_caidas(): void
    {
        $olt = $this->olt(['ip_address' => '10.0.0.1']);

        $this->ont($olt, ['sn' => 'HWTC-VIVA', 'onu_id' => 1, 'status' => '1', 'admin_enabled' => true]);
        $this->ont($olt, ['sn' => 'HWTC-CORTADA', 'onu_id' => 2, 'status' => '0', 'admin_enabled' => false]);

        $resumen = $this->get(route('onts.authorized'))->assertOk()->viewData('resumen');

        $this->assertSame(1, $resumen['deshabilitadas']);
        $this->assertSame(0, $resumen['caidas']);
        $this->assertSame(100.0, $resumen['disponibilidad']);
    }

    public function test_el_listado_filtra_por_estado(): void
    {
        $olt = $this->olt(['ip_address' => '10.0.0.1']);

        $this->ont($olt, ['sn' => 'HWTC-ARRIBA', 'onu_id' => 1, 'status' => '1', 'admin_enabled' => true]);
        $this->ont($olt, ['sn' => 'HWTC-ABAJO', 'onu_id' => 2, 'status' => '0', 'admin_enabled' => true]);
        $this->ont($olt, ['sn' => 'HWTC-CORTADA', 'onu_id' => 2, 'status' => '0', 'admin_enabled' => false]);

        $this->get(route('onts.authorized', ['estado' => 'caida']))
            ->assertOk()
            ->assertSee('HWTC-ABAJO')
            ->assertDontSee('HWTC-ARRIBA')
            // Una cortada a propósito NO es una caída
            ->assertDontSee('HWTC-CORTADA');

        $this->get(route('onts.authorized', ['estado' => 'deshabilitada']))
            ->assertOk()
            ->assertSee('HWTC-CORTADA')
            ->assertDontSee('HWTC-ABAJO');
    }

    /**
     * El filtro por banda de señal.
     *
     * Es el que convierte "hay 14 críticas" en poder ver cuáles son.
     * Va en memoria y no en SQL porque rx_power es una columna de TEXTO
     * y los rangos son negativos.
     */
    public function test_el_listado_filtra_por_banda_de_senal(): void
    {
        $olt = $this->olt(['ip_address' => '10.0.0.1']);

        $this->ont($olt, ['sn' => 'HWTC-BUENA', 'onu_id' => 1, 'status' => '1', 'rx_power' => '-19.0']);
        $this->ont($olt, ['sn' => 'HWTC-CRITICA', 'onu_id' => 2, 'status' => '1', 'rx_power' => '-28.4']);

        $this->get(route('onts.authorized', ['banda' => 'critica']))
            ->assertOk()
            ->assertSee('HWTC-CRITICA')
            ->assertDontSee('HWTC-BUENA');

        $resumen = $this->get(route('onts.authorized'))->assertOk()->viewData('resumen');

        $this->assertSame(1, $resumen['bandas']['critica']['cantidad']);
        $this->assertSame(1, $resumen['bandas']['optima']['cantidad']);
        $this->assertSame(1, $resumen['con_problema']);
    }

    public function test_el_listado_filtra_las_que_no_tienen_contrato(): void
    {
        $olt = $this->olt(['ip_address' => '10.0.0.1']);

        $suelta = $this->ont($olt, ['sn' => 'HWTC-SUELTA']);
        $suelta->update(['contract_id' => null]);

        $resumen = $this->get(route('onts.authorized', ['contrato' => 'no']))
            ->assertOk()
            ->assertSee('HWTC-SUELTA')
            ->viewData('resumen');

        $this->assertSame(1, $resumen['sin_contrato']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
