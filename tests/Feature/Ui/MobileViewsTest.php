<?php

namespace Tests\Feature\Ui;

use App\Models\Audit;
use App\Models\Branch;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Adaptación a pantallas pequeñas.
 *
 * Tres módulos se usan de verdad desde el teléfono —cuentas PPPoE,
 * ONT y órdenes técnicas— y su presentación móvil vive en una hoja
 * compartida (public/css/gestisp-movil.css) que cada vista tiene que
 * enlazar, más unas clases que ese CSS necesita encontrar en el HTML
 * (.tabla-movil, .celda-principal, .celda-acciones, data-label).
 *
 * Nada de eso lo comprueba nadie por nosotros: si alguien reescribe
 * una vista y se lleva por delante el enlace a la hoja o la clase de
 * la tabla, la pantalla vuelve a salirse del ancho en el teléfono y
 * en escritorio no se nota absolutamente nada. Estas pruebas son la
 * red que avisa de esa regresión.
 *
 * También se fija aquí la paginación: el panel es AdminLTE sobre
 * Bootstrap 4, y Laravel pagina con marcado de Tailwind si no se le
 * dice lo contrario (de ahí los enlaces sueltos y desalineados que se
 * veían en trazabilidad). Se comprueba que salga el marcado que las
 * hojas del panel sí saben pintar.
 */
class MobileViewsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private User $admin;
    private Role $rolSuper;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create();
        $this->rolSuper = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create([
            'selected_branch_id' => $this->sucursal->id,
        ]);
        $this->admin->assignRole($this->rolSuper);
        $this->admin->branches()->attach($this->sucursal->id, ['role_id' => $this->rolSuper->id]);
    }

    private function comoAdmin(): self
    {
        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $this->rolSuper->id,
        ]);

        return $this;
    }

    // ==================== Paginación ====================

    public function test_la_paginacion_usa_el_marcado_de_bootstrap_4(): void
    {
        // Más registros que el tamaño de página (50) para que el
        // paginador llegue a dibujarse: sin segunda página no hay
        // enlaces que comprobar.
        for ($i = 0; $i < 55; $i++) {
            Audit::create([
                'auditable_type' => Ont::class,
                'auditable_id' => $i + 1,
                'user_id' => $this->admin->id,
                'user_name' => $this->admin->name,
                'branch_id' => $this->sucursal->id,
                'action' => 'created',
                'description' => 'Registro de prueba ' . $i,
                'ip' => '127.0.0.1',
            ]);
        }

        $respuesta = $this->comoAdmin()->get(route('audits.index'));

        $respuesta->assertOk();
        // Clases de Bootstrap 4, que es lo que AdminLTE sabe pintar.
        $respuesta->assertSee('page-link', false);
        // Y NO las de Tailwind, que es el valor por defecto de Laravel
        // y lo que rompía la paginación de esta pantalla.
        $respuesta->assertDontSee('relative inline-flex items-center', false);
    }

    // ==================== Enlace a la hoja compartida ====================

    /**
     * @dataProvider vistasQueDebenSerMoviles
     */
    public function test_la_vista_enlaza_la_hoja_movil(string $ruta): void
    {
        $this->prepararDatos();

        $respuesta = $this->comoAdmin()->get(route($ruta));

        $respuesta->assertOk();
        $respuesta->assertSee('css/gestisp-movil.css', false);
    }

    public static function vistasQueDebenSerMoviles(): array
    {
        return [
            'PPPoE — listado' => ['pppoe.index'],
            'PPPoE — cortes masivos' => ['pppoe.cutoff'],
            'ONT — autorizadas' => ['onts.authorized'],
            'ONT — sin autorizar' => ['onts.no-authorized'],
            'ONT — importar' => ['onts.import.index'],
            'Órdenes técnicas — listado' => ['technicals_orders.index'],
            'Órdenes técnicas — mis órdenes' => ['technicals_orders.my_technical_orders'],
            'Órdenes técnicas — verificación' => ['technicals_orders.verification'],
        ];
    }

    // ==================== Clases que el CSS necesita ====================

    public function test_el_listado_pppoe_marca_la_tabla_y_las_celdas(): void
    {
        $this->prepararDatos();

        $respuesta = $this->comoAdmin()->get(route('pppoe.index'));

        $respuesta->assertOk();
        // La tabla que se convierte en fichas.
        $respuesta->assertSee('tabla-movil', false);
        // Y el rótulo de cada celda, que el CSS saca de data-label.
        $respuesta->assertSee('data-label=', false);
    }

    public function test_el_listado_de_ont_marca_la_tabla_y_las_celdas(): void
    {
        $this->prepararDatos();

        $respuesta = $this->comoAdmin()->get(route('onts.authorized'));

        $respuesta->assertOk();
        $respuesta->assertSee('tabla-movil', false);
        $respuesta->assertSee('celda-principal', false);
        $respuesta->assertSee('celda-acciones', false);
    }

    public function test_la_ficha_de_ont_envuelve_sus_tablas(): void
    {
        $ont = $this->prepararDatos();

        // Las cinco tablas de la ficha no estaban dentro de
        // .table-responsive: en el teléfono desbordaban el ancho y
        // arrastraban la página entera hacia los lados.
        $this->comoAdmin()->get(route('onts.show', $ont))
            ->assertOk()
            ->assertSee('table-responsive', false)
            ->assertSee('css/gestisp-movil.css', false);
    }

    public function test_la_ficha_de_cuenta_pppoe_envuelve_sus_tablas(): void
    {
        $this->prepararDatos();
        $cuenta = PppoeAccount::firstOrFail();

        // Mismo caso que la ficha de ONT: sus tres tablas tampoco
        // estaban envueltas.
        $this->comoAdmin()->get(route('pppoe.show', $cuenta))
            ->assertOk()
            ->assertSee('table-responsive', false)
            ->assertSee('css/gestisp-movil.css', false);
    }

    /**
     * Datos mínimos para que las pantallas tengan algo que pintar.
     */
    private function prepararDatos(): Ont
    {
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

        $router = Router::create([
            'branch_id' => $this->sucursal->id,
            'name' => 'Router de pruebas',
            'ip_address' => '10.0.0.2',
            'username' => 'admin',
            'password' => 'x',
            'api_port' => 8728,
            'active' => true,
        ]);

        PppoeAccount::create([
            'branch_id' => $this->sucursal->id,
            'router_id' => $router->id,
            'mikrotik_id' => '*1',
            'username' => 'usuario.prueba',
            'password' => 'clave.prueba',
            'profile' => 'default',
            'service' => 'pppoe',
            'disabled' => false,
        ]);

        return Ont::create([
            'branch_id' => $this->sucursal->id,
            'olt_id' => $olt->id,
            'slot' => 1,
            'port' => 2,
            'onu_id' => 5,
            'sn' => 'TEST-SN-0001',
            'status' => 1,
        ]);
    }
}
