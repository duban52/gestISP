<?php

namespace Tests\Feature\System;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Gestión de errores: que nada falle en silencio.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 *  1. **Que borrar un cliente no se lleve su historial.** En la base,
 *     `clients → contracts` es ON DELETE CASCADE y de ahí cuelgan
 *     facturas, órdenes y saldos. `Client` no usa SoftDeletes, así
 *     que `$client->delete()` borraba todo eso EN SILENCIO —incluidas
 *     facturas electrónicas ya transmitidas a la DIAN, que son
 *     documentos fiscales—. Esta es la prueba más importante del
 *     archivo.
 *
 *  2. **Que un fallo inesperado deje un rastro que se pueda buscar.**
 *     El identificador del incidente tiene que estar en el log Y en
 *     la pantalla: es lo único que ata «me salió un error» con la
 *     línea exacta del servidor.
 *
 *  3. **Que una petición AJAX reciba JSON y no una página de error.**
 *     Devolver HTML a quien espera JSON deja al JavaScript sin poder
 *     leer la respuesta, y el usuario ve un indicador girando sin
 *     fin: el error ocurrió y en pantalla no pasó nada.
 */
class GestionDeErroresTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

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
    }

    private function cliente(): Client
    {
        return Client::factory()->create([
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);
    }

    private function contratoDe(Client $cliente): Contract
    {
        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => Plan::factory()->create([
                'branch_id' => $this->branch->id,
                'user_id' => $this->admin->id,
            ])->id,
            'user_id' => $this->admin->id,
        ]);
    }

    // ============ Integridad: borrar no puede arrastrar ============

    /**
     * LA PRUEBA QUE MÁS IMPORTA DE TODO EL ARCHIVO.
     *
     * Si esta falla, borrar un cliente destruye su facturación.
     */
    public function test_no_se_puede_eliminar_un_cliente_con_contratos(): void
    {
        $cliente = $this->cliente();
        $contrato = $this->contratoDe($cliente);

        $this->delete(route('clients.destroy', $cliente))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('clients', ['id' => $cliente->id]);
        $this->assertDatabaseHas('contracts', ['id' => $contrato->id]);
    }

    public function test_el_aviso_dice_cuantos_contratos_lo_impiden(): void
    {
        $cliente = $this->cliente();
        $this->contratoDe($cliente);
        $this->contratoDe($cliente);

        $this->delete(route('clients.destroy', $cliente));

        $this->assertStringContainsString('2 contratos', session('error'));
    }

    /**
     * Sin contratos pero con facturas: son documentos fiscales.
     *
     * Pasa con un contrato que se cedió — las facturas se quedan con
     * su titular original (ver Invoice::titular()).
     */
    public function test_no_se_puede_eliminar_un_cliente_con_facturas(): void
    {
        $cliente = $this->cliente();
        $otro = $this->cliente();
        $contrato = $this->contratoDe($otro);

        Invoice::factory()->create([
            'contract_id' => $contrato->id,
            'client_id' => $cliente->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->delete(route('clients.destroy', $cliente))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('clients', ['id' => $cliente->id]);
    }

    public function test_un_cliente_sin_nada_asociado_si_se_elimina(): void
    {
        // La otra cara: la comprobación no puede bloquear el caso
        // legítimo, que es borrar un alta hecha por error.
        $cliente = $this->cliente();

        $this->delete(route('clients.destroy', $cliente))
            ->assertRedirect()
            ->assertSessionHas('success-delete');

        $this->assertDatabaseMissing('clients', ['id' => $cliente->id]);
    }

    // ============ Que el fallo deje rastro ============

    public function test_un_fallo_inesperado_queda_con_su_identificador(): void
    {
        $capturado = null;

        Log::listen(function ($mensaje) use (&$capturado) {
            if (str_contains($mensaje->message, 'ERR-')) {
                $capturado = $mensaje;
            }
        });

        // Una ruta que revienta a propósito, solo durante la prueba.
        \Illuminate\Support\Facades\Route::get('/_boom', function () {
            throw new \RuntimeException('Algo se rompió');
        })->middleware('web');

        $this->get('/_boom');

        $this->assertNotNull($capturado, 'El fallo no se registró con identificador.');
        $this->assertMatchesRegularExpression('/ERR-[A-Z0-9]{8}/', $capturado->message);

        // Y el contexto tiene con qué diagnosticar.
        foreach (['incidente', 'excepcion', 'archivo', 'url'] as $clave) {
            $this->assertArrayHasKey($clave, $capturado->context, "Falta «{$clave}» en el contexto.");
        }
    }

    /**
     * El log NO es un depósito de datos personales.
     *
     * Si algún día alguien «mejora» el contexto metiéndole la entrada
     * completa del formulario, esta prueba lo frena: ahí van cédulas,
     * direcciones y, en otros formularios, contraseñas.
     */
    public function test_el_registro_no_guarda_la_entrada_del_usuario(): void
    {
        $capturado = null;

        Log::listen(function ($mensaje) use (&$capturado) {
            if (str_contains($mensaje->message, 'ERR-')) {
                $capturado = $mensaje;
            }
        });

        \Illuminate\Support\Facades\Route::post('/_boom-post', function () {
            throw new \RuntimeException('Algo se rompió');
        })->middleware('web');

        $this->post('/_boom-post', [
            'password' => 'la-contrasena-secreta',
            'identity_number' => '1234567890',
        ]);

        $this->assertNotNull($capturado);

        $contexto = json_encode($capturado->context);

        $this->assertStringNotContainsString('la-contrasena-secreta', $contexto);
        $this->assertStringNotContainsString('1234567890', $contexto);
    }

    public function test_una_peticion_ajax_recibe_json_y_no_una_pagina(): void
    {
        \Illuminate\Support\Facades\Route::get('/_boom-ajax', function () {
            throw new \RuntimeException('Algo se rompió');
        })->middleware('web');

        $respuesta = $this->getJson('/_boom-ajax');

        $respuesta->assertStatus(500)->assertJsonStructure(['ok', 'incidente', 'message']);

        $this->assertMatchesRegularExpression('/ERR-[A-Z0-9]{8}/', $respuesta->json('incidente'));
    }

    // ============ Las pantallas ya no son mudas ============

    /**
     * @dataProvider pantallasQueRecibenErrores
     */
    public function test_las_pantallas_ensenan_el_motivo(string $ruta): void
    {
        $this->withSession(['error' => 'Un motivo concreto que explica el fallo'])
            ->get($ruta)
            ->assertOk()
            ->assertSee('Un motivo concreto que explica el fallo', escape: false);
    }

    public static function pantallasQueRecibenErrores(): array
    {
        return [
            'OLTs' => ['/gestisp/olts'],
            'acciones masivas' => ['/gestion/acciones-masivas'],
        ];
    }

    // ============ El código llega a la pantalla ============

    /**
     * La página de error enseña el código, y nada más que el código.
     *
     * Es la mitad que cierra el círculo: el identificador que queda en
     * el log tiene que ser el mismo que lee el usuario, o no sirve
     * para atar una cosa con la otra. Y lo que NO puede aparecer es el
     * mensaje técnico: eso es información interna.
     */
    public function test_la_pagina_de_error_muestra_el_codigo_y_no_el_detalle_tecnico(): void
    {
        // En producción APP_DEBUG va apagado; con él encendido Laravel
        // pinta su pantalla de depuración en vez de la nuestra.
        config(['app.debug' => false]);

        $registrado = null;

        Log::listen(function ($mensaje) use (&$registrado) {
            if (str_contains($mensaje->message, 'ERR-')) {
                $registrado = $mensaje->context['incidente'] ?? null;
            }
        });

        \Illuminate\Support\Facades\Route::get('/_boom-pagina', function () {
            throw new \RuntimeException('SQLSTATE secreto: tabla interna rota');
        })->middleware('web');

        $respuesta = $this->get('/_boom-pagina')->assertStatus(500);

        $this->assertNotNull($registrado);
        $respuesta->assertSee($registrado, escape: false);
        $respuesta->assertDontSee('SQLSTATE secreto', escape: false);
        $respuesta->assertDontSee('RuntimeException', escape: false);
    }

    // ============ Cada módulo, su archivo ============

    /**
     * Un incidente de clientes queda TAMBIÉN en el log de clientes.
     *
     * Se comprueba contra el archivo de verdad y no contra un doble:
     * lo que importa es que en el servidor exista un archivo que se
     * pueda abrir con `tail`, con el código dentro.
     */
    public function test_un_incidente_de_clientes_queda_en_su_propio_archivo(): void
    {
        $archivo = storage_path('logs/gestisp-clients-' . now()->format('Y-m-d') . '.log');
        @unlink($archivo);

        Client::creating(function () {
            throw new \RuntimeException('Fallo simulado al guardar el cliente');
        });

        $this->post(route('clients.store'), [
            'document_type_code' => '13',
            'identity_number' => '1042772330',
            'name' => 'Juan',
            'last_name' => 'Pérez',
            'type_client' => 'Residencial',
            'number_phone' => '3001234567',
        ])->assertStatus(500);

        $this->assertFileExists($archivo, 'No se creó el log del módulo de clientes.');

        $contenido = file_get_contents($archivo);
        @unlink($archivo);

        $this->assertMatchesRegularExpression('/ERR-[A-Z0-9]{8}/', $contenido);
        // Módulo, controlador y método: lo que permite ir directo al
        // código sin leer la traza.
        $this->assertStringContainsString('"modulo":"clientes"', $contenido);
        $this->assertStringContainsString('ClientController@store', $contenido);
    }

    public function test_un_incidente_de_otro_modulo_no_ensucia_el_log_de_clientes(): void
    {
        $archivo = storage_path('logs/gestisp-clients-' . now()->format('Y-m-d') . '.log');
        @unlink($archivo);

        \Illuminate\Support\Facades\Route::get('/_boom-general', function () {
            throw new \RuntimeException('Algo ajeno a clientes');
        })->middleware('web');

        $this->get('/_boom-general');

        $this->assertFileDoesNotExist($archivo);
    }
}
