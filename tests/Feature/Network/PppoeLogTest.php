<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Router;
use App\Models\User;
use App\Services\Mikrotik\RegistroPppoe;
use App\Services\MikrotikApiService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El registro de PPPoE del router, traducido.
 *
 * PARA QUÉ
 * --------
 * Cuando un cliente llama diciendo «no tengo internet», la respuesta
 * está en el log del Mikrotik. Antes eso exigía entrar por Winbox y
 * saber leer líneas como:
 *
 *     ppp,pppoe,info,account juan_perez authentication failed
 *
 * LO QUE NO PUEDE FALLAR
 * ----------------------
 *  1. Que el mensaje ORIGINAL no se pierda nunca. RouterOS cambia
 *     textos entre versiones: un evento que no se reconoce tiene que
 *     poder leerse igual, o traducir es peor que no hacer nada.
 *  2. Que se distinga «no hay fallos» de «el router no está
 *     registrando». Sin eso la pantalla diría que todo va bien
 *     mientras no se mira nada.
 *  3. Que un router caído no rompa la pantalla.
 *  4. Que no se lea el log de un router de otra sucursal.
 */
class PppoeLogTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'CCR Gómez Plata',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    /** Una línea como las que devuelve RouterOS. */
    private function linea(string $temas, string $mensaje, string $hora = 'oct/20 21:10:05'): array
    {
        return ['.id' => '*1', 'time' => $hora, 'topics' => $temas, 'message' => $mensaje];
    }

    private function elRouterDevuelve(array $lineas): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) use ($lineas) {
            $mock->shouldReceive('getLog')->andReturn($lineas);
        });
    }

    // ==================== La traducción ====================

    /** @dataProvider mensajesReales */
    public function test_traduce_los_mensajes_del_router(string $mensaje, string $tipo, string $contiene): void
    {
        $eventos = app(RegistroPppoe::class)->interpretar([
            $this->linea('ppp,pppoe,info,account', $mensaje),
        ]);

        $this->assertCount(1, $eventos);
        $this->assertSame($tipo, $eventos[0]['tipo']);
        $this->assertStringContainsString($contiene, $eventos[0]['titulo'] . ' ' . $eventos[0]['explicacion']);
    }

    public static function mensajesReales(): array
    {
        return [
            'entró' => ['juan_perez logged in, 10.0.0.5', RegistroPppoe::CONEXION, 'Conectó'],
            'salió' => ['juan_perez logged out, 3600 1234 5678', RegistroPppoe::DESCONEXION, 'desconectó'],
            'clave mala' => ['user juan_perez authentication failed', RegistroPppoe::FALLO, 'contraseña'],
            'no existe' => ['juan_perez: no such user', RegistroPppoe::FALLO, 'no existe'],
            'suspendida' => ['juan_perez: account is disabled', RegistroPppoe::FALLO, 'suspendida'],
            'sin ip' => ['<pppoe-juan_perez>: no more IP addresses available', RegistroPppoe::FALLO, 'direcciones IP'],
            'ya conectado' => ['juan_perez is already active', RegistroPppoe::FALLO, 'sesión abierta'],
            'no responde' => ['<pppoe-juan_perez>: terminating... - peer is not responding',
                RegistroPppoe::DESCONEXION, 'dejó de responder'],
        ];
    }

    /**
     * El mensaje original SIEMPRE, lo reconozca o no.
     *
     * RouterOS cambia textos entre versiones y cada fabricante de CPE
     * provoca los suyos. Un «evento desconocido» sin su texto es peor
     * que no traducir nada.
     */
    public function test_el_mensaje_original_nunca_se_pierde(): void
    {
        $raro = 'algo que RouterOS inventó en la version 7.19';

        $eventos = app(RegistroPppoe::class)->interpretar([
            $this->linea('ppp,info', $raro),
        ]);

        $this->assertSame(RegistroPppoe::OTRO, $eventos[0]['tipo']);
        $this->assertSame($raro, $eventos[0]['mensaje']);
    }

    public function test_saca_el_usuario_de_las_dos_formas_en_que_viene(): void
    {
        $registro = app(RegistroPppoe::class);

        // Al principio de la línea
        $uno = $registro->interpretar([$this->linea('ppp,info,account', 'juan_perez logged in, 10.0.0.5')]);
        $this->assertSame('juan_perez', $uno[0]['usuario']);

        // Dentro del nombre de la interfaz dinámica
        $dos = $registro->interpretar([
            $this->linea('ppp,info', '<pppoe-maria_lopez>: terminating... - peer is not responding'),
        ]);
        $this->assertSame('maria_lopez', $dos[0]['usuario']);
    }

    public function test_descarta_lo_que_no_es_de_pppoe(): void
    {
        // El log del router lo lleva TODO: DHCP, firewall, el reloj.
        $eventos = app(RegistroPppoe::class)->interpretar([
            $this->linea('dhcp,info', 'dhcp1 assigned 192.168.1.50 to AA:BB:CC:DD:EE:FF'),
            $this->linea('firewall,info', 'input: in:ether1 out:(none)'),
            $this->linea('ppp,info,account', 'juan_perez logged in, 10.0.0.5'),
            $this->linea('system,info', 'router rebooted'),
        ]);

        $this->assertCount(1, $eventos);
        $this->assertSame('juan_perez', $eventos[0]['usuario']);
    }

    // ==================== La pantalla ====================

    public function test_la_pantalla_enseña_el_registro(): void
    {
        $this->elRouterDevuelve([
            $this->linea('ppp,pppoe,info,account', 'user juan_perez authentication failed'),
            $this->linea('ppp,info,account', 'maria_lopez logged in, 10.0.0.9'),
        ]);

        $this->get(route('pppoe.log', ['router' => $this->router->id]))
            ->assertOk()
            ->assertSee('juan_perez', false)
            ->assertSee('Usuario o contraseña incorrectos', false)
            ->assertSee('maria_lopez', false)
            ->assertSee('Conectó', false);
    }

    public function test_se_filtra_por_tipo_y_por_usuario(): void
    {
        $this->elRouterDevuelve([
            $this->linea('ppp,pppoe,info', 'user juan_perez authentication failed'),
            $this->linea('ppp,info,account', 'maria_lopez logged in, 10.0.0.9'),
        ]);

        $this->get(route('pppoe.log', ['router' => $this->router->id, 'tipo' => RegistroPppoe::FALLO]))
            ->assertOk()
            ->assertSee('juan_perez', false)
            ->assertDontSee('maria_lopez', false);

        $this->get(route('pppoe.log', ['router' => $this->router->id, 'buscar' => 'maria']))
            ->assertOk()
            ->assertSee('maria_lopez', false)
            ->assertDontSee('juan_perez', false);
    }

    /**
     * «No hay fallos» y «no se está registrando» no son lo mismo.
     *
     * El log de RouterOS solo guarda los temas que alguien haya
     * mandado ahí con una regla de `/system logging`. Sin esa
     * distinción la pantalla diría que todo va bien mientras no se
     * mira nada.
     */
    public function test_avisa_cuando_el_router_no_registra_pppoe(): void
    {
        // El router contesta, pero con un log sin una sola línea de ppp
        $this->elRouterDevuelve([
            $this->linea('system,info', 'router rebooted'),
            $this->linea('dhcp,info', 'dhcp1 assigned 192.168.1.50'),
        ]);

        $this->get(route('pppoe.log', ['router' => $this->router->id]))
            ->assertOk()
            ->assertSee('no está guardando el registro de PPPoE', false)
            ->assertSee('/system logging add topics=ppp', false);
    }

    public function test_un_router_caido_no_rompe_la_pantalla(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('getLog')->andThrow(new \Exception('Connection refused'));
        });

        $this->get(route('pppoe.log', ['router' => $this->router->id]))
            ->assertOk()
            ->assertSee('No se pudo leer el registro', false)
            ->assertSee('Connection refused', false);
    }

    public function test_no_se_lee_el_registro_de_otra_sucursal(): void
    {
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $ajeno = Router::create([
            'branch_id' => $otra->id, 'name' => 'De otra sede',
            'ip_address' => '10.9.9.9', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);

        $this->elRouterDevuelve([$this->linea('ppp,info', 'secreto logged in, 10.0.0.1')]);

        // Pedirlo por la URL no cuela: cae al router del alcance
        $this->get(route('pppoe.log', ['router' => $ajeno->id]))
            ->assertOk()
            ->assertDontSee('De otra sede', false);
    }

    public function test_exige_el_permiso_del_modulo(): void
    {
        $rol = Role::create(['name' => 'sin pppoe']);

        $usuario = User::factory()->create(['number_phone' => '3000000099']);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('pppoe.log'))->assertForbidden();
    }

    public function test_senala_a_los_que_mas_fallan(): void
    {
        // Treinta fallos del mismo usuario en una hora son un CPE
        // reintentando solo, no treinta incidencias.
        $lineas = [];

        for ($i = 0; $i < 5; $i++) {
            $lineas[] = $this->linea('ppp,pppoe,info', 'user terco_cliente authentication failed');
        }

        $lineas[] = $this->linea('ppp,info,account', 'otro logged in, 10.0.0.3');

        $this->elRouterDevuelve($lineas);

        $this->get(route('pppoe.log', ['router' => $this->router->id]))
            ->assertOk()
            ->assertSee('Los que más fallan', false)
            ->assertSee('terco_cliente', false);
    }
}
