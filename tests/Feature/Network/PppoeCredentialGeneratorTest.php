<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\PppoeCredentialGenerator;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Generación de usuario, contraseña y comentario de una cuenta PPPoE.
 *
 * Las reglas de la casa:
 *   usuario     primernombre_primerapellido_referencia
 *   contraseña  primerapellido_primeroscincodigitosdeidentidad
 *   comentario  Contrato X, CC Y Nombres Apellidos
 *
 * Lo que se comprueba además de las reglas: que el usuario propuesto
 * esté LIBRE en el router. Dos hermanos con el mismo nombre, o un
 * segundo enlace del mismo titular, producen el mismo nombre base y
 * el router no admite duplicados.
 */
class PppoeCredentialGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Router $router;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ENG']);
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($role);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $role->id,
        ]);

        $this->plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router pruebas',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    private function generador(): PppoeCredentialGenerator
    {
        return app(PppoeCredentialGenerator::class);
    }

    private function cuentaExistente(string $username): PppoeAccount
    {
        return PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $this->router->id,
            'mikrotik_id' => '*' . strtoupper(bin2hex(random_bytes(2))),
            'username' => $username,
            'password' => 'x',
            'profile' => 'default',
            'service' => 'pppoe',
            'disabled' => false,
        ]);
    }

    // ==================== Las reglas ====================

    public function test_arma_usuario_clave_y_comentario_con_las_reglas_de_la_casa(): void
    {
        $propuesta = $this->generador()->generar([
            'nombres' => 'Juan Carlos',
            'apellidos' => 'Pérez Gómez',
            'identificacion' => '1042770586',
            'referencia' => 'ENG000123',
        ], $this->router->id);

        // Primer nombre y primer apellido, sin tildes ni mayúsculas
        $this->assertSame('juan_perez_eng000123', $propuesta['username']);

        // Apellido + los primeros cinco dígitos del documento
        $this->assertSame('perez_10427', $propuesta['password']);

        // El comentario sí conserva tildes y mayúsculas: lo leen personas
        $this->assertStringContainsString('ENG000123', $propuesta['comment']);
        $this->assertStringContainsString('CC 1042770586', $propuesta['comment']);
        $this->assertStringContainsString('Juan Carlos Pérez Gómez', $propuesta['comment']);
    }

    public function test_quita_tildes_enes_y_caracteres_raros_del_usuario(): void
    {
        $propuesta = $this->generador()->generar([
            'nombres' => 'Íngrid',
            'apellidos' => "Muñoz-O'Brien",
            'identificacion' => '98.765.432',
            'referencia' => 'YV-000 42',
        ], $this->router->id);

        // Un usuario PPPoE con tildes o comillas da problemas en los
        // equipos: solo letras, números y guiones bajos.
        $this->assertSame('ingrid_munozobrien_yv00042', $propuesta['username']);

        // La identificación se limpia de puntos antes de cortarla
        $this->assertSame('munozobrien_98765', $propuesta['password']);
    }

    public function test_sin_referencia_usa_la_identificacion(): void
    {
        $propuesta = $this->generador()->generar([
            'nombres' => 'Ana',
            'apellidos' => 'Gómez',
            'identificacion' => '43567890',
        ], $this->router->id);

        // Algo tiene que distinguirla de otra Ana Gómez
        $this->assertSame('ana_gomez_43567890', $propuesta['username']);
    }

    public function test_sin_datos_no_propone_usuario(): void
    {
        $propuesta = $this->generador()->generar([], $this->router->id);

        // Es el caso de la cámara o la antena: se escribe a mano
        $this->assertSame('', $propuesta['username']);
        $this->assertSame('', $propuesta['comment']);
    }

    // ==================== El diferenciador ====================

    public function test_si_el_usuario_ya_existe_agrega_un_diferenciador(): void
    {
        $this->cuentaExistente('juan_perez_eng000123');

        $propuesta = $this->generador()->generar([
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'identificacion' => '1042770586',
            'referencia' => 'ENG000123',
        ], $this->router->id);

        $this->assertSame('juan_perez_eng000123_2', $propuesta['username']);
    }

    public function test_el_diferenciador_avanza_hasta_encontrar_uno_libre(): void
    {
        $this->cuentaExistente('juan_perez_eng000123');
        $this->cuentaExistente('juan_perez_eng000123_2');
        $this->cuentaExistente('juan_perez_eng000123_3');

        $propuesta = $this->generador()->generar([
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'referencia' => 'ENG000123',
        ], $this->router->id);

        $this->assertSame('juan_perez_eng000123_4', $propuesta['username']);
    }

    public function test_el_mismo_usuario_en_otro_router_no_estorba(): void
    {
        $otroRouter = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Otro router',
            'ip_address' => '10.0.0.3', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);

        $this->cuentaExistente('juan_perez_eng000123');

        // La unicidad es POR ROUTER: en otro equipo el nombre está libre
        $propuesta = $this->generador()->generar([
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'referencia' => 'ENG000123',
        ], $otroRouter->id);

        $this->assertSame('juan_perez_eng000123', $propuesta['username']);
    }

    public function test_sin_router_no_se_puede_comprobar_y_devuelve_la_base(): void
    {
        $this->cuentaExistente('juan_perez_eng000123');

        $propuesta = $this->generador()->generar([
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'referencia' => 'ENG000123',
        ], null);

        // Sin router no hay contra qué comparar; será el guardado
        // quien avise si choca.
        $this->assertSame('juan_perez_eng000123', $propuesta['username']);
    }

    // ==================== Desde un contrato ====================

    public function test_desde_un_contrato_usa_el_numero_visible_y_no_el_id(): void
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Duban',
            'last_name' => 'Restrepo',
            'identity_number' => '1042770586',
            'user_id' => $this->admin->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'contract_number' => 'ENG000777',
            'user_id' => $this->admin->id,
        ]);

        $propuesta = $this->generador()->paraContrato($contrato, $this->router->id);

        // La regla siempre dijo "numerodecontrato"; el código usaba el
        // id interno porque los números de contrato no existían aún.
        $this->assertSame('duban_restrepo_eng000777', $propuesta['username']);
        $this->assertStringContainsString('Contrato ENG000777', $propuesta['comment']);
        $this->assertStringNotContainsString('Contrato ' . $contrato->id . ',', $propuesta['comment']);
    }

    // ==================== El endpoint ====================

    public function test_el_endpoint_propone_credenciales_para_datos_sueltos(): void
    {
        $respuesta = $this->postJson(route('pppoe.suggest'), [
            'router_id' => $this->router->id,
            'nombres' => 'María',
            'apellidos' => 'Restrepo',
            'identificacion' => '43567890',
            'referencia' => 'CLI-99',
        ])->assertOk();

        $this->assertSame('maria_restrepo_cli99', $respuesta->json('username'));
        $this->assertSame('restrepo_43567', $respuesta->json('password'));
        $this->assertStringContainsString('Ref. CLI-99', $respuesta->json('comment'));
    }

    public function test_el_endpoint_exige_permiso_para_crear_cuentas(): void
    {
        Role::where('name', 'superadministrador')->firstOrFail()->revokePermissionTo('pppoe.create');

        $this->postJson(route('pppoe.suggest'), ['nombres' => 'Ana'])
            ->assertStatus(403);
    }
}
