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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
