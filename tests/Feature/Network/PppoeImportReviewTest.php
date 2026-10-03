<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\MikrotikApiService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Importar las cuentas PPPoE que ya viven en un router.
 *
 * EL FALLO QUE DESTAPÓ ESTA PANTALLA
 * ----------------------------------
 * Un Mikrotik admite dos secrets con el MISMO nombre de usuario.
 * La importación comprobaba los duplicados contra nuestra base pero
 * no contra el propio lote, así que los dos entraban en el mismo
 * `insert()` masivo y la base los rechazaba a los dos —con las otras
 * novecientas detrás—. El operador veía un error 500 con el SQL
 * entero: ni sabía qué corregir, ni que hubiera algo que corregir.
 *
 * Lo que se fija aquí:
 *
 *   · Que la revisión diga QUÉ está repetido antes de escribir nada.
 *   · Que la importación entre igual con lo que sí se puede, en vez
 *     de caerse entera.
 *   · Que lo que queda fuera se diga en el mensaje final.
 */
class PppoeImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ENG']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router pruebas',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    /** Un secret como los que devuelve el Mikrotik. */
    private function secret(string $usuario, array $extra = []): array
    {
        return array_merge([
            'mikrotik_id' => '*' . strtoupper(substr(md5($usuario . rand()), 0, 4)),
            'username' => $usuario,
            'password' => 'clave',
            'profile' => 'PLAN 20M',
            'service' => 'pppoe',
            'remote_address' => null,
            'disabled' => false,
            'comment' => null,
        ], $extra);
    }

    private function elRouterDevuelve(array $secrets): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) use ($secrets) {
            $mock->shouldReceive('getPppSecrets')->andReturn($secrets);
        });
    }

    // ==================== La revisión ====================

    public function test_la_revision_avisa_de_los_usuarios_repetidos_en_el_router(): void
    {
        // Es el caso real: el Mikrotik traía dos «test_cabecera».
        $this->elRouterDevuelve([
            $this->secret('test_cabecera'),
            $this->secret('test_cabecera'),
            $this->secret('jorge_alzate'),
        ]);

        $this->get(route('pppoe.import.review', $this->router))
            ->assertOk()
            ->assertSee('test_cabecera', false)
            ->assertSee('REPETIDO', false)
            ->assertSee('Hay que corregir', false);

        // Y NO ha escrito nada
        $this->assertSame(0, PppoeAccount::count());
    }

    public function test_la_revision_dice_cuantas_entran_y_cuantas_ya_estaban(): void
    {
        PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $this->router->id,
            'username' => 'ya_estaba',
            'password' => 'x',
            'profile' => 'PLAN 20M',
            'service' => 'pppoe',
        ]);

        $this->elRouterDevuelve([
            $this->secret('ya_estaba'),
            $this->secret('nueva_uno'),
            $this->secret('nueva_dos'),
        ]);

        $this->get(route('pppoe.import.review', $this->router))
            ->assertOk()
            ->assertSee('nueva_uno', false)
            ->assertSee('nueva_dos', false)
            ->assertSee('Se van a importar', false);
    }

    public function test_sin_problemas_lo_dice_en_claro(): void
    {
        $this->elRouterDevuelve([
            $this->secret('jorge_alzate'),
            $this->secret('maria_pino'),
        ]);

        $this->get(route('pppoe.import.review', $this->router))
            ->assertOk()
            ->assertSee('Todo en orden', false);
    }

    public function test_un_router_caido_no_revienta_la_pantalla(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('getPppSecrets')
                ->andThrow(new \Exception('Connection refused'));
        });

        $this->get(route('pppoe.import.review', $this->router))
            ->assertOk()
            ->assertSee('No se pudo conectar con el router', false);
    }

    // ==================== La importación ====================

    public function test_los_repetidos_del_router_ya_no_tumban_la_importacion(): void
    {
        // ESTA es la prueba del 500. Antes: UniqueConstraintViolation
        // y ni una sola cuenta guardada.
        $this->elRouterDevuelve([
            $this->secret('test_cabecera'),
            $this->secret('test_cabecera'),
            $this->secret('jorge_alzate'),
            $this->secret('maria_pino'),
        ]);

        $this->post(route('pppoe.import', $this->router))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Entran las buenas Y una sola del usuario repetido
        $this->assertSame(3, PppoeAccount::count());
        $this->assertSame(1, PppoeAccount::where('username', 'test_cabecera')->count());
        $this->assertNotNull(PppoeAccount::where('username', 'jorge_alzate')->first());
        $this->assertNotNull(PppoeAccount::where('username', 'maria_pino')->first());
    }

    public function test_el_mensaje_final_dice_cuantas_quedaron_fuera(): void
    {
        $this->elRouterDevuelve([
            $this->secret('test_cabecera'),
            $this->secret('test_cabecera'),
            $this->secret('jorge_alzate'),
        ]);

        $this->post(route('pppoe.import', $this->router))->assertRedirect();

        $mensaje = session('success');

        $this->assertStringContainsString('2 cuenta(s) importada(s)', $mensaje);
        $this->assertStringContainsString('se quedaron fuera', $mensaje);
    }

    public function test_un_secret_sin_usuario_no_entra_y_se_explica(): void
    {
        $this->elRouterDevuelve([
            $this->secret(''),
            $this->secret('jorge_alzate'),
        ]);

        $this->get(route('pppoe.import.review', $this->router))
            ->assertOk()
            ->assertSee('sin nombre de usuario', false);

        $this->post(route('pppoe.import', $this->router))->assertRedirect();

        $this->assertSame(1, PppoeAccount::count());
    }

    public function test_importar_dos_veces_no_duplica(): void
    {
        $this->elRouterDevuelve([
            $this->secret('jorge_alzate'),
            $this->secret('maria_pino'),
        ]);

        $this->post(route('pppoe.import', $this->router))->assertRedirect();
        $this->post(route('pppoe.import', $this->router))->assertRedirect();

        $this->assertSame(2, PppoeAccount::count());
    }

    public function test_la_revision_exige_su_permiso(): void
    {
        $rol = Role::where('name', 'auxiliar administrativo')->firstOrFail();

        $usuario = User::factory()->create(['number_phone' => '3000000001']);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('pppoe.import.review', $this->router))->assertForbidden();
    }
    public function test_las_cuentas_importadas_quedan_con_su_empresa(): void
    {
        // `insert()` masivo no dispara el gancho de BelongsToCompany,
        // asi que entraban con company_id en NULL y el alcance de
        // empresa las escondia de TODOS los listados. Estaban en la
        // base y no existian para nadie.
        $this->elRouterDevuelve([$this->secret('jorge_alzate')]);

        $this->post(route('pppoe.import', $this->router))->assertRedirect();

        $this->assertDatabaseHas('pppoe_accounts', [
            'username' => 'jorge_alzate',
            'company_id' => $this->branch->company_id,
        ]);

        // Y se ven: la consulta normal del modelo lleva el alcance
        $this->assertSame(1, PppoeAccount::where('username', 'jorge_alzate')->count());
    }

}
