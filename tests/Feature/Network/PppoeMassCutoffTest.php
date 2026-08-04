<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\MikrotikApiService;
use App\Services\PppoeMassCutoff;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cortes masivos de servicio PPPoE.
 *
 * Lo que no puede fallar, en orden de gravedad:
 *
 *  1. Que REVISAR no toque el router ni la base. Es el paso que le
 *     permite al operador ver a quién va a dejar sin internet antes
 *     de hacerlo.
 *  2. Que no se corte a nadie de otra sucursal.
 *  3. Que un router caído no impida cortar el resto de la tanda.
 *  4. Que cada corte quede en la trazabilidad con su contrato, que
 *     es lo que responde "¿a mí por qué me cortaron?".
 */
class PppoeMassCutoffTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Branch $otraBranch;
    private User $admin;
    private Plan $plan;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'ENG']);
        $this->otraBranch = Branch::factory()->create(['contract_prefix' => 'OTR']);

        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($role);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->router = Router::create([
            'branch_id' => $this->branch->id, 'name' => 'Router pruebas',
            'ip_address' => '10.0.0.2', 'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    /** Contrato con su cuenta PPPoE, listo para cortar. */
    private function contratoConCuenta(string $numero, array $extra = []): PppoeAccount
    {
        $branchId = $extra['branch_id'] ?? $this->branch->id;

        $cliente = Client::factory()->create([
            'branch_id' => $branchId,
            'user_id' => $this->admin->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $branchId,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'contract_number' => $numero,
            'status' => 'Activo',
            'user_id' => $this->admin->id,
        ]);

        return PppoeAccount::create(array_merge([
            'branch_id' => $branchId,
            'router_id' => $this->router->id,
            'contract_id' => $contrato->id,
            'mikrotik_id' => '*' . strtoupper(bin2hex(random_bytes(2))),
            'username' => strtolower($numero) . '.user',
            'password' => 'clave',
            'profile' => 'default',
            'service' => 'pppoe',
            'disabled' => false,
        ], $extra));
    }

    /** El Mikrotik nunca se toca de verdad en los tests. */
    private function mikrotikQueCorta(): void
    {
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldReceive('setPppSecretState')->andReturnNull();
        });
    }

    // ==================== Listado ====================

    public function test_el_listado_muestra_el_numero_de_contrato(): void
    {
        $cuenta = $this->contratoConCuenta('ENG000500');

        $this->get(route('pppoe.index'))
            ->assertOk()
            // El número que el cliente tiene impreso y que se pega en
            // la pantalla de cortes, no el id interno.
            ->assertSee('ENG000500')
            ->assertSee($cuenta->username)
            ->assertSee('Cortes masivos');
    }

    // ============ Revisar no toca nada ============

    public function test_revisar_no_toca_el_router_ni_la_base(): void
    {
        $cuenta = $this->contratoConCuenta('ENG000001');

        // Si la revisión llamara al router, esto haría fallar el test
        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldNotReceive('setPppSecretState');
            $mock->shouldNotReceive('dropActiveSession');
        });

        $respuesta = $this->postJson(route('pppoe.cutoff.preview'), [
            'lista' => "ENG000001",
        ])->assertOk();

        $this->assertSame('lista', $respuesta->json('filas.0.estado'));
        $this->assertSame(1, $respuesta->json('resumen.cuentas'));

        // La cuenta sigue activa: revisar no corta
        $this->assertFalse($cuenta->fresh()->disabled);
    }

    public function test_reconoce_numeros_de_contrato_y_usuarios_mezclados(): void
    {
        $porContrato = $this->contratoConCuenta('ENG000010');
        $porUsuario = $this->contratoConCuenta('ENG000011');

        $respuesta = $this->postJson(route('pppoe.cutoff.preview'), [
            'lista' => "ENG000010\n{$porUsuario->username}",
        ])->assertOk();

        $this->assertSame(2, $respuesta->json('resumen.cuentas'));
        $this->assertSame('lista', $respuesta->json('filas.0.estado'));
        $this->assertSame('lista', $respuesta->json('filas.1.estado'));
        $this->assertSame($porContrato->username, $respuesta->json('filas.0.cuentas.0.username'));
    }

    public function test_clasifica_lo_que_no_se_puede_cortar(): void
    {
        $this->contratoConCuenta('ENG000020');
        $this->contratoConCuenta('ENG000021', ['disabled' => true]);
        $this->contratoConCuenta('OTR000001', ['branch_id' => $this->otraBranch->id]);

        // Contrato de la sucursal, pero sin cuenta PPPoE
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);
        Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'contract_number' => 'ENG000022',
            'status' => 'Activo',
            'user_id' => $this->admin->id,
        ]);

        $respuesta = $this->postJson(route('pppoe.cutoff.preview'), [
            'lista' => "ENG000020\nENG000021\nENG000022\nOTR000001\nNO-EXISTE",
        ])->assertOk();

        $resumen = $respuesta->json('resumen');

        $this->assertSame(1, $resumen['lista']);
        $this->assertSame(1, $resumen['ya_suspendida']);
        $this->assertSame(1, $resumen['sin_cuenta']);
        $this->assertSame(1, $resumen['otra_sucursal']);
        $this->assertSame(1, $resumen['no_encontrado']);
    }

    public function test_descarta_los_repetidos(): void
    {
        $this->contratoConCuenta('ENG000030');

        $respuesta = $this->postJson(route('pppoe.cutoff.preview'), [
            'lista' => "ENG000030\nENG000030\neng000030",
        ])->assertOk();

        // Una sola vez: cortar dos veces no rompe nada, pero duplica
        // llamadas al router y ensucia el informe.
        $this->assertCount(1, $respuesta->json('identificadores'));
    }

    // ==================== Ejecución ====================

    public function test_el_corte_deshabilita_la_cuenta(): void
    {
        $cuenta = $this->contratoConCuenta('ENG000040');

        $this->mock(MikrotikApiService::class, function ($mock) use ($cuenta) {
            // Se exige el corte real sobre ESA cuenta con disabled=true
            $mock->shouldReceive('setPppSecretState')
                ->once()
                ->withArgs(fn ($router, $account, $disabled) => $account->id === $cuenta->id && $disabled === true)
                ->andReturnNull();
        });

        $respuesta = $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['ENG000040'],
        ])->assertOk();

        $this->assertSame(1, $respuesta->json('cortadas'));
        $this->assertSame(0, $respuesta->json('errores'));
        $this->assertTrue($cuenta->fresh()->disabled);
    }

    public function test_no_corta_cuentas_de_otra_sucursal(): void
    {
        $ajena = $this->contratoConCuenta('OTR000050', ['branch_id' => $this->otraBranch->id]);

        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldNotReceive('setPppSecretState');
        });

        $respuesta = $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['OTR000050'],
        ])->assertOk();

        $this->assertSame(0, $respuesta->json('cortadas'));
        $this->assertFalse($ajena->fresh()->disabled);
    }

    public function test_no_vuelve_a_cortar_una_cuenta_ya_suspendida(): void
    {
        $this->contratoConCuenta('ENG000060', ['disabled' => true]);

        $this->mock(MikrotikApiService::class, function ($mock) {
            $mock->shouldNotReceive('setPppSecretState');
        });

        $respuesta = $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['ENG000060'],
        ])->assertOk();

        $this->assertSame(0, $respuesta->json('cortadas'));
    }

    public function test_un_fallo_del_router_no_detiene_el_resto(): void
    {
        $mala = $this->contratoConCuenta('ENG000070');
        $buena = $this->contratoConCuenta('ENG000071');

        $this->mock(MikrotikApiService::class, function ($mock) use ($mala) {
            $mock->shouldReceive('setPppSecretState')
                ->andReturnUsing(function ($router, $account) use ($mala) {
                    if ($account->id === $mala->id) {
                        throw new \RuntimeException('Router inalcanzable');
                    }
                });
        });

        $respuesta = $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['ENG000070', 'ENG000071'],
        ])->assertOk();

        // La que falló no bloqueó a la otra
        $this->assertSame(1, $respuesta->json('cortadas'));
        $this->assertSame(1, $respuesta->json('errores'));

        $this->assertFalse($mala->fresh()->disabled);
        $this->assertTrue($buena->fresh()->disabled);
    }

    public function test_un_contrato_con_varias_cuentas_las_corta_todas(): void
    {
        $primera = $this->contratoConCuenta('ENG000080');

        $segunda = PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $this->router->id,
            'contract_id' => $primera->contract_id,
            'mikrotik_id' => '*BB',
            'username' => 'eng000080.respaldo',
            'password' => 'clave',
            'profile' => 'default',
            'service' => 'pppoe',
            'disabled' => false,
        ]);

        $this->mikrotikQueCorta();

        $respuesta = $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['ENG000080'],
        ])->assertOk();

        $this->assertSame(2, $respuesta->json('cortadas'));
        $this->assertTrue($primera->fresh()->disabled);
        $this->assertTrue($segunda->fresh()->disabled);
    }

    // ============ Efecto sobre el contrato ============

    public function test_el_corte_deja_el_contrato_suspendido(): void
    {
        $cuenta = $this->contratoConCuenta('ENG000200');
        $this->mikrotikQueCorta();

        $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['ENG000200'],
        ])->assertOk();

        // Es lo que hace que, al pagar, PaymentRegistrar genere sola
        // la orden técnica de reconexión.
        $this->assertSame('Suspendido', $cuenta->contract->fresh()->status);
        $this->assertDatabaseHas('audits', ['action' => 'contracts.suspended']);
    }

    public function test_no_devuelve_a_suspendido_un_contrato_por_reconexion(): void
    {
        $cuenta = $this->contratoConCuenta('ENG000210');

        // Ya pagó y espera la visita del técnico: devolverlo a
        // Suspendido borraría esa señal.
        \App\Models\Contract::whereKey($cuenta->contract_id)
            ->update(['status' => 'Por Reconexión']);

        $this->mikrotikQueCorta();

        $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['ENG000210'],
        ])->assertOk();

        $this->assertSame('Por Reconexión', $cuenta->contract->fresh()->status);
    }

    public function test_una_cuenta_sin_contrato_se_corta_sin_tocar_estados(): void
    {
        $suelta = PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $this->router->id,
            'contract_id' => null,
            'mikrotik_id' => '*CC',
            'username' => 'enlace.sede',
            'password' => 'x',
            'profile' => 'default',
            'service' => 'pppoe',
            'disabled' => false,
        ]);

        $this->mikrotikQueCorta();

        $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['enlace.sede'],
        ])->assertOk();

        $this->assertTrue($suelta->fresh()->disabled);
        // No hay contrato: no hay estado que mover
        $this->assertDatabaseMissing('audits', ['action' => 'contracts.suspended']);
    }

    // ==================== Trazabilidad ====================

    public function test_cada_corte_queda_registrado_con_su_contrato(): void
    {
        $this->contratoConCuenta('ENG000090');
        $this->mikrotikQueCorta();

        $this->postJson(route('pppoe.cutoff.execute'), [
            'identificadores' => ['ENG000090'],
        ])->assertOk();

        // Por cuenta: responde "¿a mí por qué me cortaron?"
        $this->assertDatabaseHas('audits', ['action' => 'pppoe.cut']);

        // De la operación: responde "¿quién ordenó el corte y a cuántos?"
        $this->assertDatabaseHas('audits', ['action' => 'pppoe.mass_cut']);

        $registro = \App\Models\Audit::where('action', 'pppoe.cut')->firstOrFail();

        $this->assertStringContainsString('ENG000090', $registro->description);
    }

    public function test_cargar_un_archivo_queda_registrado(): void
    {
        $this->contratoConCuenta('ENG000100');

        $archivo = UploadedFile::fake()->createWithContent('cortes.txt', "ENG000100\n");

        $this->post(route('pppoe.cutoff.preview'), ['archivo' => $archivo])->assertOk();

        // Si mañana se cortó a quien no era, hay que saber de dónde
        // salió la lista.
        $this->assertDatabaseHas('audits', ['action' => 'pppoe.cutoff_file_loaded']);
    }

    // ==================== Archivos ====================

    public function test_lee_un_txt_con_un_identificador_por_linea(): void
    {
        $this->contratoConCuenta('ENG000110');
        $this->contratoConCuenta('ENG000111');

        $archivo = UploadedFile::fake()->createWithContent(
            'cortes.txt',
            "ENG000110\nENG000111\n\n",
        );

        $respuesta = $this->post(route('pppoe.cutoff.preview'), ['archivo' => $archivo])->assertOk();

        $this->assertCount(2, $respuesta->json('identificadores'));
        $this->assertSame(2, $respuesta->json('resumen.cuentas'));
    }

    public function test_lee_un_csv_con_encabezado_y_varias_columnas(): void
    {
        $this->contratoConCuenta('ENG000120');
        $this->contratoConCuenta('ENG000121');

        // El encabezado "contrato" manda: se ignoran las otras columnas
        $archivo = UploadedFile::fake()->createWithContent(
            'cartera.csv',
            "cliente;contrato;saldo\nJuan Pérez;ENG000120;45000\nAna Gómez;ENG000121;80000\n",
        );

        $respuesta = $this->post(route('pppoe.cutoff.preview'), ['archivo' => $archivo])->assertOk();

        $this->assertSame(['ENG000120', 'ENG000121'], $respuesta->json('identificadores'));
    }

    public function test_sin_encabezado_reconocible_usa_la_primera_columna(): void
    {
        $this->contratoConCuenta('ENG000130');

        $archivo = UploadedFile::fake()->createWithContent(
            'lista.csv',
            "ENG000130,algo,otra cosa\n",
        );

        $respuesta = $this->post(route('pppoe.cutoff.preview'), ['archivo' => $archivo])->assertOk();

        $this->assertSame(['ENG000130'], $respuesta->json('identificadores'));
    }

    public function test_rechaza_archivos_de_otro_tipo(): void
    {
        $archivo = UploadedFile::fake()->create('lista.pdf', 10, 'application/pdf');

        // Se manda con Accept: application/json igual que la pantalla
        // (jQuery lo pone al usar dataType json); sin esa cabecera
        // Laravel responde con una redirección en vez de los errores.
        $this->post(route('pppoe.cutoff.preview'), ['archivo' => $archivo], [
            'Accept' => 'application/json',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('archivo');
    }

    // ==================== Validaciones ====================

    public function test_una_lista_vacia_se_rechaza(): void
    {
        $this->postJson(route('pppoe.cutoff.preview'), ['lista' => "  \n \n"])
            ->assertStatus(422);
    }

    public function test_no_se_admiten_mas_del_maximo_por_tanda(): void
    {
        $lista = collect(range(1, PppoeMassCutoff::MAXIMO + 1))
            ->map(fn ($i) => 'ENG' . str_pad((string) $i, 6, '0', STR_PAD_LEFT))
            ->implode("\n");

        $respuesta = $this->postJson(route('pppoe.cutoff.preview'), ['lista' => $lista])
            ->assertStatus(422);

        $this->assertStringContainsString('máximo por tanda', $respuesta->json('error'));
    }

    public function test_el_corte_masivo_exige_su_propio_permiso(): void
    {
        $rol = Role::where('name', 'superadministrador')->firstOrFail();
        $rol->revokePermissionTo('pppoe.cutoff');

        // Ver el listado de cuentas no habilita a dejar sin internet
        // a media sucursal.
        $this->get(route('pppoe.cutoff'))->assertStatus(403);
        $this->postJson(route('pppoe.cutoff.execute'), ['identificadores' => ['ENG000001']])
            ->assertStatus(403);
    }
}
