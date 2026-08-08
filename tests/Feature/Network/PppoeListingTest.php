<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\PppoeSessionMetric;
use App\Models\Router;
use App\Models\User;
use App\Services\PppoeQuery;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Listado de cuentas PPPoE: filtros, cifras y exportación.
 *
 * LA DISTINCIÓN QUE SE PROTEGE AQUÍ
 * ---------------------------------
 * Una cuenta puede estar HABILITADA y DESCONECTADA a la vez: la
 * primera es una decisión de la empresa (si se cortó o no), la segunda
 * depende de si el cliente tiene el equipo encendido. Confundirlas
 * hace que un listado de "caídos" incluya a los cortados por
 * facturación, y entonces nadie lo usa.
 *
 * De la exportación importa sobre todo que contenga EXACTAMENTE lo que
 * el usuario acaba de ver filtrado: un archivo con más filas de las
 * esperadas se lleva contraseñas de clientes que nadie pidió.
 */
class PppoeListingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Router $router;
    private Router $otroRouter;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'PPP']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3009998877']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router Centro',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);

        $this->otroRouter = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router Norte',
            'ip_address' => '10.0.0.3', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    private function cuenta(array $extra = [], ?Contract $contrato = null): PppoeAccount
    {
        return PppoeAccount::create(array_merge([
            'branch_id' => $this->branch->id,
            'router_id' => $this->router->id,
            'contract_id' => $contrato?->id,
            'mikrotik_id' => '*' . fake()->unique()->numerify('###'),
            'username' => fake()->unique()->userName(),
            'password' => 'clave-' . fake()->unique()->numerify('####'),
            'profile' => 'PLAN 150M',
            'service' => 'pppoe',
            'disabled' => false,
        ], $extra));
    }

    private function contrato(array $cliente = [], array $extra = []): Contract
    {
        $c = Client::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ], $cliente));

        // El número va explícito: sin él numero_visible cae al id, y
        // buscar "3" acabaría coincidiendo con cualquier cosa.
        return Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => $c->id,
            'plan_id' => $this->plan->id,
            'contract_number' => 'PPP' . fake()->unique()->numerify('######'),
            'user_id' => $this->admin->id,
        ], $extra));
    }

    /** Deja una muestra del poller para esa cuenta. */
    private function muestra(PppoeAccount $cuenta, bool $conectada, ?string $cuando = null): void
    {
        PppoeSessionMetric::create([
            'pppoe_account_id' => $cuenta->id,
            'connected' => $conectada,
            'address' => $conectada ? '10.20.30.40' : null,
            'measured_at' => $cuando ? now()->parse($cuando) : now(),
        ]);
    }

    // ==================== Cifras ====================

    /**
     * @test
     *
     * Habilitada y conectada son cosas distintas.
     *
     * Una cuenta cortada por facturación NO es un cliente caído, y una
     * habilitada con el equipo apagado SÍ. Es la cifra que decide a
     * quién se llama y a quién se le cobra.
     */
    public function distingue_las_suspendidas_de_las_simplemente_desconectadas(): void
    {
        $arriba = $this->cuenta(['username' => 'arriba']);
        $caida = $this->cuenta(['username' => 'caida']);
        $cortada = $this->cuenta(['username' => 'cortada', 'disabled' => true]);

        $this->muestra($arriba, true);
        $this->muestra($caida, false);
        $this->muestra($cortada, false);

        $resumen = $this->get(route('pppoe.index'))->assertOk()->viewData('resumen');

        $this->assertSame(3, $resumen['total']);
        $this->assertSame(1, $resumen['conectadas']);
        $this->assertSame(1, $resumen['suspendidas']);
        // La cortada NO cuenta como caída: está así a propósito
        $this->assertSame(1, $resumen['caidas']);
    }

    /** @test */
    public function las_cifras_se_calculan_sobre_lo_filtrado(): void
    {
        $this->cuenta(['username' => 'centro1']);
        $this->cuenta(['username' => 'centro2']);
        $this->cuenta(['username' => 'norte1', 'router_id' => $this->otroRouter->id]);

        $resumen = $this->get(route('pppoe.index', ['router_id' => $this->router->id]))
            ->assertOk()
            ->viewData('resumen');

        $this->assertSame(2, $resumen['total']);
    }

    // ==================== Filtros ====================

    /** @test */
    public function filtra_por_router_estado_y_perfil(): void
    {
        $this->cuenta(['username' => 'aaa', 'profile' => 'PLAN 100M']);
        $this->cuenta(['username' => 'bbb', 'disabled' => true, 'profile' => 'PLAN 300M']);
        $this->cuenta(['username' => 'ccc', 'router_id' => $this->otroRouter->id]);

        $this->get(route('pppoe.index', ['estado' => 'suspendida']))
            ->assertOk()->assertSee('bbb')->assertDontSee('aaa');

        $this->get(route('pppoe.index', ['profile' => 'PLAN 100M']))
            ->assertOk()->assertSee('aaa')->assertDontSee('bbb');

        $this->get(route('pppoe.index', ['router_id' => $this->otroRouter->id]))
            ->assertOk()->assertSee('ccc')->assertDontSee('aaa');
    }

    /** @test */
    public function filtra_por_estado_de_conexion(): void
    {
        $conectada = $this->cuenta(['username' => 'conectada']);
        $desconectada = $this->cuenta(['username' => 'desconectada']);
        $this->cuenta(['username' => 'nuncavista']);

        $this->muestra($conectada, true);
        $this->muestra($desconectada, false);

        $this->get(route('pppoe.index', ['conexion' => 'conectada']))
            ->assertOk()->assertSee('conectada')->assertDontSee('nuncavista');

        // "Nunca conectadas" son las que no tienen NI UNA muestra con
        // conexión: suelen ser instalaciones que quedaron a medias.
        $this->get(route('pppoe.index', ['conexion' => 'nunca']))
            ->assertOk()->assertSee('nuncavista')->assertDontSee('>conectada<', false);
    }

    /** @test */
    public function el_buscador_encuentra_por_contrato_y_por_cliente(): void
    {
        $contrato = $this->contrato(['name' => 'Marta', 'last_name' => 'Ospina', 'identity_number' => '43567890']);
        $this->cuenta(['username' => 'marta.ospina'], $contrato);
        $this->cuenta(['username' => 'otro.cliente']);

        $this->get(route('pppoe.index', ['q' => '43567890']))
            ->assertOk()->assertSee('marta.ospina')->assertDontSee('otro.cliente');

        $this->get(route('pppoe.index', ['q' => $contrato->numero_visible]))
            ->assertOk()->assertSee('marta.ospina')->assertDontSee('otro.cliente');
    }

    /** @test */
    public function filtra_las_cuentas_sin_contrato(): void
    {
        $this->cuenta(['username' => 'suelta']);
        $this->cuenta(['username' => 'concontrato'], $this->contrato());

        $resumen = $this->get(route('pppoe.index', ['contrato' => 'no']))
            ->assertOk()
            ->assertSee('suelta')
            ->assertDontSee('concontrato')
            ->viewData('resumen');

        $this->assertSame(1, $resumen['sin_contrato']);
    }

    /** @test */
    public function no_se_ven_las_cuentas_de_otra_sucursal(): void
    {
        $otra = Branch::factory()->create();

        $routerAjeno = Router::create([
            'branch_id' => $otra->id, 'name' => 'Ajeno',
            'ip_address' => '10.9.9.9', 'username' => 'a', 'password' => 'b',
            'api_port' => 8728, 'active' => true,
        ]);

        PppoeAccount::create([
            'branch_id' => $otra->id,
            'router_id' => $routerAjeno->id,
            'mikrotik_id' => '*999',
            'username' => 'secreta.ajena',
            'password' => 'x',
            'profile' => 'PLAN 150M',
            'service' => 'pppoe',
            'disabled' => false,
        ]);

        $this->get(route('pppoe.index'))->assertOk()->assertDontSee('secreta.ajena');
    }

    // ==================== Última conexión ====================

    /** @test */
    public function la_ultima_conexion_es_la_de_la_ultima_muestra_conectada(): void
    {
        $cuenta = $this->cuenta(['username' => 'intermitente']);

        // Estuvo conectada anteayer, y desde ayer aparece desconectada:
        // la última conexión es la de anteayer, no la muestra más nueva.
        $this->muestra($cuenta, true, now()->subDays(2)->toDateTimeString());
        $this->muestra($cuenta, false, now()->subDay()->toDateTimeString());

        $cuentas = app(PppoeQuery::class)->construir([])->get();
        $encontrada = $cuentas->firstWhere('username', 'intermitente');

        $this->assertNotNull($encontrada->ultimaConexion());
        $this->assertSame(
            now()->subDays(2)->toDateString(),
            $encontrada->ultimaConexion()->toDateString(),
        );
        $this->assertFalse($encontrada->estaConectada());
    }

    // ==================== Exportación ====================

    /** @test */
    public function la_exportacion_descarga_un_excel(): void
    {
        Excel::fake();

        $this->cuenta(['username' => 'exportable']);

        $this->get(route('pppoe.export'))->assertOk();

        Excel::assertDownloaded('cuentas-pppoe-' . now()->format('Y-m-d') . '.xlsx');
    }

    /**
     * @test
     *
     * El archivo lleva EXACTAMENTE lo filtrado.
     *
     * Si se llevara todo, un usuario que filtró un router acabaría con
     * las contraseñas de clientes que no pidió.
     */
    public function la_exportacion_respeta_los_filtros(): void
    {
        $this->cuenta(['username' => 'delcentro']);
        $this->cuenta(['username' => 'delnorte', 'router_id' => $this->otroRouter->id]);

        $filas = app(PppoeQuery::class)
            ->construir(['router_id' => $this->router->id])
            ->get();

        $this->assertCount(1, $filas);
        $this->assertSame('delcentro', $filas->first()->username);
    }

    /** @test */
    public function el_archivo_trae_la_contrasena_el_estado_y_la_ultima_conexion(): void
    {
        $contrato = $this->contrato(['name' => 'Pedro', 'last_name' => 'Gomez']);
        $cuenta = $this->cuenta([
            'username' => 'pedro.gomez',
            'password' => 'clave-secreta-123',
            'disabled' => true,
        ], $contrato);

        $this->muestra($cuenta, true, now()->subHours(5)->toDateTimeString());

        $cuentas = app(PppoeQuery::class)->construir([])->get();
        $filas = (new \App\Exports\PppoeAccountsExport($cuentas))->collection()->all();

        $fila = $filas[0];

        $this->assertSame('pedro.gomez', $fila[0]);
        $this->assertSame('clave-secreta-123', $fila[1], 'El archivo debe traer la contraseña.');
        $this->assertSame('Suspendida', $fila[4]);
        $this->assertStringContainsString(now()->subHours(5)->format('d/m/Y'), $fila[6]);
        $this->assertSame($contrato->numero_visible, $fila[9]);
        $this->assertStringContainsString('Pedro', $fila[11]);
    }

    /** @test */
    public function una_cuenta_que_nunca_se_conecto_lo_dice_en_el_archivo(): void
    {
        $this->cuenta(['username' => 'nueva']);

        $cuentas = app(PppoeQuery::class)->construir([])->get();
        $fila = (new \App\Exports\PppoeAccountsExport($cuentas))->collection()->first();

        $this->assertSame('Nunca', $fila[6]);
        $this->assertSame('Sin datos', $fila[5]);
    }

    /**
     * @test
     *
     * La descarga queda anotada.
     *
     * Un archivo con las claves de todos los clientes sale del sistema
     * y se puede reenviar: tiene que quedar constancia de quién lo sacó
     * y de cuántos registros se llevó.
     */
    public function la_exportacion_queda_en_la_trazabilidad(): void
    {
        Excel::fake();

        $this->cuenta();
        $this->cuenta();

        $this->get(route('pppoe.export'))->assertOk();

        $registro = \App\Models\Audit::where('action', 'pppoe.exported')->first();

        $this->assertNotNull($registro);
        $this->assertSame('red', $registro->category);
        $this->assertStringContainsString('2 cuenta(s)', $registro->description);
    }

    /**
     * @test
     *
     * La exportación exige permiso PROPIO.
     *
     * Poder ver el listado en pantalla no es lo mismo que llevarse un
     * archivo con las contraseñas de todos los clientes.
     */
    public function exportar_exige_su_propio_permiso(): void
    {
        $rol = Role::create(['name' => 'consulta-pppoe']);
        $rol->givePermissionTo('pppoe.index');

        $usuario = User::factory()->create(['number_phone' => '3001110000']);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->get(route('pppoe.index'))->assertOk();
        $this->get(route('pppoe.export'))->assertForbidden();
    }
}
