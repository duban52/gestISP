<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\User;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Eliminar una ONT autorizada.
 *
 * LO QUE PASABA
 * -------------
 * Se pulsaba, salía el modal de progreso, la página recargaba… y la
 * ONT seguía ahí. Sin un solo mensaje.
 *
 * El controlador SÍ devolvía el motivo en `session('error')`. Lo que
 * no había era quien lo pintara: las tres vistas de ONTs tenían una
 * cadena de avisos con `success`, `success-update` y `success-delete`,
 * y ningún bloque para `error`. El fallo viajaba hasta la pantalla y
 * ahí se evaporaba, y desde fuera eso se ve igual que un botón roto.
 *
 * Y HABÍA UN SEGUNDO FALLO, EL CONTRARIO Y PEOR
 * ---------------------------------------------
 * `deleteOnt()` mandaba los comandos a la OLT y no miraba la
 * respuesta. Si el equipo los rechazaba, el servicio devolvía
 * normalidad, el controlador borraba la fila y la ONT se quedaba
 * VIVA en la OLT —dando servicio— pero sin existir en gestISP: un
 * equipo fantasma que no aparece en ningún listado y que nadie
 * vuelve a cobrar. El resto de operaciones de escritura de esa clase
 * ya comprobaban la respuesta; esta se había quedado sin hacerlo.
 */
class OntBorradoTest extends TestCase
{
    use RefreshDatabase;

    private Olt $olt;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $admin = User::factory()->create(['number_phone' => '3000000000']);
        $admin->assignRole($rol);
        $admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $this->branch->id,
            'name' => 'OLT de pruebas',
            'ip_address' => '10.0.0.1',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'admin', 'password' => 'secret',
            'brand' => 'HUAWEI', 'uptime' => '0', 'active' => true,
        ]);
    }

    private function ont(): Ont
    {
        return Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt->id,
            'slot' => 2, 'port' => 1, 'onu_id' => 7,
            'sn' => 'HWTC-ABCD1234',
            'service_port' => 120,
            'status' => 'online',
            'admin_enabled' => true,
        ]);
    }

    // ==================== El fallo se cuenta ====================

    public function test_si_la_olt_falla_la_ont_no_se_borra_y_se_avisa(): void
    {
        $ont = $this->ont();

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('deleteOnt')
                ->andThrow(new \Exception('La OLT rechazó eliminar la ONT 7 del puerto 0/2/1: Failure: ONT does not exist'));
        });

        $this->delete(route('onts.destroy', $ont))
            ->assertRedirect()
            ->assertSessionHas('error');

        // Lo más importante: NO se borró. Una fila eliminada aquí con
        // el equipo todavía configurado allá es un cliente dando
        // servicio que el sistema ya no conoce.
        $this->assertDatabaseHas('onts', ['id' => $ont->id]);
    }

    public function test_el_aviso_dice_cual_es_la_ont_y_que_sigue_ahi(): void
    {
        $ont = $this->ont();

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('deleteOnt')->andThrow(new \Exception('La OLT no responde.'));
        });

        $this->delete(route('onts.destroy', $ont));

        $aviso = session('error');

        $this->assertStringContainsString('HWTC-ABCD1234', $aviso);
        $this->assertStringContainsString('sigue registrada', $aviso);
        $this->assertStringContainsString('La OLT no responde', $aviso);
    }

    /**
     * El agujero real: el motivo llegaba a la pantalla y no se
     * pintaba. Se comprueba en las TRES vistas desde las que se puede
     * acabar tras un borrado fallido.
     *
     * @dataProvider pantallasDeOnts
     */
    public function test_la_pantalla_ensena_el_motivo(string $ruta): void
    {
        $this->withSession(['error' => 'La OLT rechazó eliminar la ONT.'])
            ->get($ruta)
            ->assertOk()
            ->assertSee('La OLT rechazó eliminar la ONT.', escape: false);
    }

    public static function pantallasDeOnts(): array
    {
        return [
            'autorizadas' => ['/onts/authorized'],
            'sin autorizar' => ['/onts/no-authorized'],
        ];
    }

    public function test_la_ficha_de_la_ont_tambien_ensena_el_motivo(): void
    {
        $ont = $this->ont();

        $this->withSession(['error' => 'La OLT rechazó eliminar la ONT.'])
            ->get(route('onts.show', $ont))
            ->assertOk()
            ->assertSee('La OLT rechazó eliminar la ONT.', escape: false);
    }

    // ==================== «Ya no existe» no es un fallo ====================

    /**
     * El caso que mi propia corrección estuvo a punto de romper.
     *
     * Al empezar a comprobar la respuesta de la OLT, un «The ONT does
     * not exist» pasaba a ser error y bloqueaba el borrado. Pero ese
     * es justo el estado al que se quiere llegar: pasa cuando el
     * equipo se desmontó a mano, o cuando un intento anterior sí llegó
     * a quitarlo. Tratarlo como fallo dejaba la ficha atrapada en
     * gestISP para siempre, sin forma de quitarla desde el panel.
     *
     * Se comprueba sobre el SERVICIO de verdad, no sobre un doble: lo
     * que se defiende es cómo se interpreta el texto del equipo.
     */
    public function test_si_la_olt_dice_que_la_ont_ya_no_existe_se_da_por_borrada(): void
    {
        $metodo = new \ReflectionMethod(OltSshService::class, 'laOltDiceQueYaNoEsta');
        $metodo->setAccessible(true);
        $servicio = app(OltSshService::class);

        foreach ([
            '  Failure: The ONT does not exist',
            'Failure: no such ONT',
            '  % Error: ONT not found',
        ] as $respuesta) {
            $this->assertTrue(
                $metodo->invoke($servicio, $respuesta),
                "No se reconoció como «ya no está»: {$respuesta}",
            );
        }

        // Y un fallo de verdad sigue siendo un fallo: si esto diera
        // true, cualquier rechazo se tragaría y volveríamos al equipo
        // fantasma.
        $this->assertFalse($metodo->invoke($servicio, 'Failure: The ONT is in use by a service port'));
    }

    // ==================== Sin service-port también se borra ====================

    /**
     * EL CASO QUE BLOQUEABA EL BORRADO EN PRODUCCIÓN.
     *
     * «No se pudo obtener el service-port de esta ONT en la OLT» era
     * el final del camino: la ONT quedaba atrapada en el sistema sin
     * forma de quitarla desde el panel.
     *
     * Pero el service-port es solo la primera limpieza; lo que quita
     * la ONT del equipo es `ont delete`. Exigirlo dejaba encalladas
     * justo las ONTs que peor estaban: las que nunca llegaron a
     * tenerlo.
     */
    public function test_sin_service_port_el_borrado_sigue_adelante(): void
    {
        $ont = $this->ont();
        $ont->update(['service_port' => null]);

        $this->mock(OltSshService::class, function ($mock) {
            // La OLT no sabe decir el service-port...
            $mock->shouldReceive('resolveServicePort')->andReturn(null);
            // ...y aun así se intenta borrar la ONT, que es el paso
            // que de verdad importa.
            $mock->shouldReceive('deleteOnt')->once()->andReturn(OltSshService::BORRADA_EN_LA_OLT);
        });

        $this->delete(route('onts.destroy', $ont))
            ->assertRedirect()
            ->assertSessionHas('success-delete');

        $this->assertDatabaseMissing('onts', ['id' => $ont->id]);
    }

    public function test_si_ni_se_puede_preguntar_el_service_port_tampoco_se_detiene(): void
    {
        $ont = $this->ont();
        $ont->update(['service_port' => null]);

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('resolveServicePort')->andThrow(new \Exception('timeout'));
            $mock->shouldReceive('deleteOnt')->once()->andReturn(OltSshService::BORRADA_EN_LA_OLT);
        });

        $this->delete(route('onts.destroy', $ont))->assertSessionHas('success-delete');

        $this->assertDatabaseMissing('onts', ['id' => $ont->id]);
    }

    /**
     * Si la OLT no la tiene, se quita de la base y SE DICE.
     *
     * No es lo mismo que un borrado normal: significa que el sistema
     * y el equipo llevaban tiempo descuadrados, y el operador querrá
     * saberlo porque puede haber más ONTs igual.
     */
    public function test_si_la_olt_no_la_tiene_se_borra_de_la_base_y_se_explica(): void
    {
        $ont = $this->ont();

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('deleteOnt')->once()->andReturn(OltSshService::BORRADA_NO_ESTABA);
        });

        $this->delete(route('onts.destroy', $ont))->assertRedirect();

        $this->assertDatabaseMissing('onts', ['id' => $ont->id]);
        $this->assertStringContainsString('ya no la tenía configurada', session('success-delete'));
    }

    /**
     * Mover SÍ sigue exigiendo el service-port.
     *
     * Allí se borra para volver a crearlo en el puerto nuevo: sin él,
     * el cliente se quedaría sin servicio en el destino. Relajar esa
     * comprobación «por simetría» con el borrado sería un error caro.
     */
    public function test_mover_sigue_exigiendo_el_service_port(): void
    {
        $ont = $this->ont();
        $ont->update(['service_port' => null]);

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('resolveServicePort')->andReturn(null);
            $mock->shouldNotReceive('moveOnt');
        });

        $this->post(route('onts.relocate', $ont), [
            'ont_location' => '0/2/3',
            'vlan' => 201,
            'ont_lineprofile' => 1,
            'ont_srvprofile' => 1,
        ])->assertSessionHas('error');

        $this->assertDatabaseHas('onts', ['id' => $ont->id]);
    }

    // ==================== El camino bueno sigue igual ====================

    public function test_cuando_la_olt_acepta_la_ont_se_borra(): void
    {
        $ont = $this->ont();

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('deleteOnt')->once();
        });

        $this->delete(route('onts.destroy', $ont))
            ->assertRedirect()
            ->assertSessionHas('success-delete');

        $this->assertDatabaseMissing('onts', ['id' => $ont->id]);
    }
}
