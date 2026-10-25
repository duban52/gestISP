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
 * El service-port 0 es un service-port.
 *
 * LO QUE PASABA
 * -------------
 * La OLT numera los service-port desde 0, así que la PRIMERA ONT que
 * se da de alta en un equipo nuevo se lleva el índice 0. La ficha lo
 * comprobaba con `@if($ont->service_port)` y el controlador con
 * `!empty($ont->service_port)`: en PHP el 0 es falso, así que ambos
 * concluían «no está resuelto» con el dato perfectamente guardado.
 *
 * Y no se quedaba en un problema de pantalla. `ensureServicePort()`
 * volvía a consultar la OLT en cada operación y acto seguido lanzaba
 * «No se pudo obtener el service-port»: esa ONT no se podía ELIMINAR
 * ni MOVER, para siempre, porque el valor correcto se leía como
 * ausente.
 *
 * Es el caso de una OLT recién instalada, que es justo cuando menos
 * se sospecha del sistema y más se sospecha del equipo.
 */
class OntServicePortCeroTest extends TestCase
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
            'name' => 'OLT Cisneros',
            'ip_address' => '10.0.0.1',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'admin', 'password' => 'secret',
            'brand' => 'HUAWEI', 'uptime' => '0', 'active' => true,
        ]);
    }

    private function ont(?int $servicePort): Ont
    {
        return Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt->id,
            'slot' => 2, 'port' => 2, 'onu_id' => 0,
            'sn' => 'HWTC-27B635BA',
            'service_port' => $servicePort,
            'status' => 'online',
            'admin_enabled' => true,
        ]);
    }

    /**
     * La salida REAL de un MA5800. El índice es el primer número de la
     * línea de datos, y aquí vale 0.
     */
    private const SALIDA_REAL = <<<'TXT'
  Command:
          display service-port port 0/2/2 ont 0
  Switch-Oriented Flow List
  -----------------------------------------------------------------------------
   INDEX VLAN VLAN     PORT F/ S/ P VPI  VCI   FLOW  FLOW       RX   TX   STATE
         ID   ATTR     TYPE                    TYPE  PARA
  -----------------------------------------------------------------------------
       0  201 common   gpon 0/2 /2  0    1     vlan  201        -    -    down
  -----------------------------------------------------------------------------
   Total : 1  (Up/Down :    0/1)
TXT;

    public function test_la_salida_de_la_olt_se_lee_como_cero_y_no_como_nada(): void
    {
        $metodo = new \ReflectionMethod(OltSshService::class, 'parseServicePortId');
        $metodo->setAccessible(true);

        $leido = $metodo->invoke(app(OltSshService::class), self::SALIDA_REAL);

        $this->assertSame(0, $leido, 'El índice 0 se perdió al interpretar la tabla.');
        $this->assertNotNull($leido, 'Se confundió el service-port 0 con «no hay».');
    }

    /**
     * Se mira LA CELDA, no la página entera.
     *
     * Un `assertDontSee('Sin resolver')` daba falso negativo: esa
     * frase aparece también en un comentario del JavaScript de la
     * vista, que sí se envía al navegador. La prueba tiene que mirar
     * el valor pintado en su sitio.
     */
    public function test_la_ficha_ensena_el_service_port_cero(): void
    {
        $html = $this->get(route('onts.show', $this->ont(0)))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/id="celdaServicePort">\s*0\s*</',
            $html,
            'La celda no muestra el service-port 0.',
        );
    }

    public function test_sin_service_port_la_ficha_si_dice_que_falta(): void
    {
        // El otro lado de la moneda: null sigue siendo «sin resolver».
        // Sin esta prueba, «arreglarlo» enseñando siempre el valor
        // taparía las ONTs que de verdad no lo tienen.
        $html = $this->get(route('onts.show', $this->ont(null)))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/id="celdaServicePort">.*?Sin resolver/s',
            $html,
            'Una ONT sin service-port tiene que decirlo.',
        );
    }

    /**
     * Lo que de verdad dolía: con el 0 no se podía borrar la ONT.
     *
     * `ensureServicePort()` lo leía como ausente, iba a la OLT a
     * resolverlo y, al recibir 0 otra vez, lanzaba la excepción. La
     * ONT quedaba atrapada.
     */
    public function test_con_service_port_cero_no_se_vuelve_a_consultar_la_olt(): void
    {
        $ont = $this->ont(0);

        // Si algo intentara resolverlo, esta OLT inalcanzable haría
        // fallar la llamada: el doble se queja si lo llaman.
        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('resolveServicePort')->never();
            $mock->shouldReceive('deleteOnt')->once();
        });

        $this->delete(route('onts.destroy', $ont))->assertRedirect();

        // `onts` no lleva borrado lógico: la fila desaparece.
        $this->assertDatabaseMissing('onts', ['id' => $ont->id]);
    }
}
