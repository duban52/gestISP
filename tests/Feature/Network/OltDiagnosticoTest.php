<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Olt;
use App\Models\User;
use App\Services\DiagnosticoDeOlt;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Por qué no se conecta con una OLT.
 *
 * LO QUE PASABA
 * -------------
 * La ficha decía «Desconectado» y se acababa ahí. El controlador
 * atrapaba la excepción con un `catch (\Exception $e)` que no miraba
 * `$e`, así que el motivo —el único dato útil— se tiraba. Con los
 * datos de acceso aparentemente correctos en pantalla, no había forma
 * de distinguir un firewall de una contraseña mal escrita.
 *
 * LO QUE SE DEFIENDE AQUÍ
 * -----------------------
 *  1. Que el motivo se GUARDE cuando falla, y se BORRE cuando vuelve:
 *     un motivo viejo junto a una OLT que ya responde manda a buscar
 *     un problema que no existe.
 *  2. Que el diagnóstico separe las capas. «No llega el paquete» y
 *     «la contraseña no vale» se arreglan en sitios distintos, y un
 *     diagnóstico que no los distingue no sirve de nada.
 *  3. Que se PARE en el primer fallo de la cadena SSH: si el puerto no
 *     responde, probar la contraseña no dice nada y encima hace
 *     esperar otro tiempo de espera completo.
 *  4. Que un equipo inalcanzable no reviente la pantalla — que es
 *     justo el caso en el que se usa esto.
 *  5. Que no se diagnostique una OLT de otra sucursal.
 *
 * Las pruebas apuntan a 127.0.0.1 con puertos cerrados: así el fallo
 * es inmediato y real, sin inventar un doble del sistema operativo ni
 * esperar tiempos de espera de red.
 */
class OltDiagnosticoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($this->rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    /**
     * Una OLT en un puerto cerrado de la propia máquina: el fallo es
     * inmediato y no depende de que haya red.
     */
    private function oltInalcanzable(?Branch $sucursal = null): Olt
    {
        return Olt::create([
            'branch_id' => ($sucursal ?? $this->branch)->id,
            'name' => 'OLT Cisneros',
            'ip_address' => '127.0.0.1',
            'ssh_port' => 9,          // puerto cerrado: nadie escucha
            'telnet_port' => 23,
            'snmp_port' => 161,
            // Sin community: el paso de SNMP falla al momento, sin
            // esperar un tiempo de espera UDP que haría lenta la suite.
            'read_snmp_comunity' => null,
            'username' => 'admin',
            'password' => 'secret',
            'brand' => 'HUAWEI',
            'uptime' => '0',
            'active' => true,
        ]);
    }

    // ==================== El diagnóstico ====================

    public function test_dice_en_que_capa_falla(): void
    {
        $resultado = app(DiagnosticoDeOlt::class)->ejecutar($this->oltInalcanzable());

        $pasos = collect($resultado['pasos'])->keyBy('titulo');

        $this->assertFalse($resultado['conecta']);
        $this->assertSame(DiagnosticoDeOlt::FALLO, $pasos['Puerto SSH']['estado']);
        $this->assertStringContainsString('Puerto SSH', $resultado['resumen']);
    }

    public function test_no_prueba_la_contrasena_si_el_puerto_no_responde(): void
    {
        // Probar credenciales contra un puerto muerto no dice nada y
        // cuesta otro tiempo de espera completo. Además induce a error:
        // un «fallo» en la línea de la contraseña hace que alguien la
        // cambie buscando arreglar un firewall.
        $resultado = app(DiagnosticoDeOlt::class)->ejecutar($this->oltInalcanzable());

        $pasos = collect($resultado['pasos'])->keyBy('titulo');

        $this->assertSame(DiagnosticoDeOlt::OMITIDO, $pasos['Sesión SSH']['estado']);
        $this->assertSame(DiagnosticoDeOlt::OMITIDO, $pasos['Responde a comandos']['estado']);
    }

    public function test_el_snmp_se_mira_aunque_el_ssh_falle(): void
    {
        // Van por puertos y protocolos distintos: una OLT puede tener
        // el SSH caído y el SNMP vivo, y entonces sigue dando las
        // potencias. Si el fallo del SSH cortara aquí, nadie lo sabría.
        $resultado = app(DiagnosticoDeOlt::class)->ejecutar($this->oltInalcanzable());

        $titulos = collect($resultado['pasos'])->pluck('titulo');

        $this->assertTrue($titulos->contains('SNMP'));
    }

    public function test_cada_fallo_explica_que_hacer(): void
    {
        // Un diagnóstico que dice «falló» sin decir dónde mirar deja a
        // quien lo lee igual que estaba.
        $resultado = app(DiagnosticoDeOlt::class)->ejecutar($this->oltInalcanzable());

        foreach ($resultado['pasos'] as $paso) {
            if ($paso['estado'] === DiagnosticoDeOlt::FALLO) {
                $this->assertNotEmpty($paso['consejo'], "El paso «{$paso['titulo']}» falla sin decir qué hacer.");
            }
        }
    }

    public function test_el_objeto_de_conexion_no_sale_del_servicio(): void
    {
        // Se usa internamente para encadenar los pasos, pero acaba en
        // un JSON: un recurso de red ahí dentro revienta al serializar.
        $resultado = app(DiagnosticoDeOlt::class)->ejecutar($this->oltInalcanzable());

        foreach ($resultado['pasos'] as $paso) {
            $this->assertArrayNotHasKey('ssh', $paso);
        }

        $this->assertIsString(json_encode($resultado));
    }

    // ==================== La pantalla ====================

    public function test_una_olt_inalcanzable_no_revienta_la_pantalla(): void
    {
        $olt = $this->oltInalcanzable();

        $this->post(route('olts.diagnose', $olt))
            ->assertOk()
            ->assertJsonStructure(['pasos', 'conecta', 'resumen']);
    }

    public function test_guarda_el_motivo_para_que_la_ficha_lo_ensene(): void
    {
        $olt = $this->oltInalcanzable();

        $this->post(route('olts.diagnose', $olt))->assertOk();

        $this->assertNotNull($olt->fresh()->last_error);
    }

    public function test_la_ficha_ensena_el_ultimo_fallo(): void
    {
        $olt = $this->oltInalcanzable();
        $olt->update(['last_error' => 'El puerto 18622 no responde.']);

        $this->get(route('olts.show', $olt))
            ->assertOk()
            ->assertSee('Último fallo', escape: false)
            ->assertSee('El puerto 18622 no responde.', escape: false);
    }

    public function test_no_se_diagnostica_una_olt_de_otra_sucursal(): void
    {
        $ajena = $this->oltInalcanzable(Branch::factory()->create());

        $this->post(route('olts.diagnose', $ajena))->assertForbidden();
    }

    // ==================== El motivo del sondeo automático ====================

    public function test_el_motivo_se_guarda_al_consultar_el_estado(): void
    {
        // Este es el agujero original: el motivo llegaba hasta el
        // `catch` y ahí se perdía.
        $olt = $this->oltInalcanzable();

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('getOltStatus')
                ->andThrow(new \Exception('No se pudo autenticar con la OLT OLT Cisneros'));
        });

        $this->get(route('api.olts.status', $olt))->assertOk();

        $this->assertStringContainsString('No se pudo autenticar', $olt->fresh()->last_error);
    }

    public function test_al_volver_a_conectar_se_borra_el_motivo_viejo(): void
    {
        // Un motivo de ayer junto a una OLT que hoy responde manda a
        // buscar un problema que ya no existe.
        $olt = $this->oltInalcanzable();
        $olt->update(['last_error' => 'Algo que ya se arregló']);

        $this->mock(OltSshService::class, function ($mock) {
            $mock->shouldReceive('getOltStatus')->andReturn([
                'status' => 'Conectado',
                'temperature' => '45',
                'uptime' => '10 días',
            ]);
        });

        $this->get(route('api.olts.status', $olt))->assertOk();

        $this->assertNull($olt->fresh()->last_error);
    }
}
