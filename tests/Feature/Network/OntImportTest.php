<?php

namespace Tests\Feature\Network;

use App\Jobs\ImportOltOnts;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\OntImportRun;
use App\Models\Plan;
use App\Models\User;
use App\Services\OltOntDiscovery;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Importación de ONTs existentes en una OLT.
 *
 * El descubrimiento SNMP se sustituye por un doble: lo que se
 * verifica es la lógica propia del sistema (decodificación de
 * seriales, emparejamiento con contratos, omisión de las ya
 * registradas y control del proceso), no la respuesta del equipo.
 */
class OntImportTest extends TestCase
{
    use RefreshDatabase;

    private Olt $olt;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($role);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $role->id,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $this->branch->id,
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
            'active' => true,
        ]);
    }

    /**
     * Crea un contrato para un cliente con el documento indicado.
     */
    private function contratoPara(string $documento): Contract
    {
        $client = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'identity_number' => $documento,
            'number_phone' => '3111111111',
            'aditional_phone' => '3111111112',
            'user_id' => $this->admin->id,
        ]);

        $plan = Plan::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
        ]);
    }

    /**
     * Sustituye el descubrimiento por una lista fija de ONTs.
     */
    private function simularDescubrimiento(array $onts): void
    {
        $this->mock(OltOntDiscovery::class, function ($mock) use ($onts) {
            $mock->shouldReceive('discover')->andReturn(collect($onts));
            // matchContract conserva su lógica real
            $mock->shouldReceive('matchContract')->passthru();
        })->makePartial();
    }

    private function ontEncontrada(array $overrides = []): array
    {
        return array_merge([
            'sn' => 'HWTC-AAAA0001',
            'if_index' => 4194304000,
            'onu_id' => 1,
            'slot' => 1,
            'port' => 0,
            'description' => '',
            'online' => true,
            'distance' => 500,
        ], $overrides);
    }

    public function test_decodifica_los_seriales_binarios_de_huawei(): void
    {
        $discovery = app(OltOntDiscovery::class);

        // 48575443 = "HWTC" en ASCII + 4 bytes hexadecimales
        $binario = hex2bin('48575443DD5C64C6');
        $this->assertSame('HWTC-DD5C64C6', $discovery->decodeSerial($binario));

        // Otros fabricantes presentes en el equipo real
        $this->assertSame('OEMT-3C1C0454', $discovery->decodeSerial(hex2bin('4F454D543C1C0454')));
        $this->assertSame('XPON-2308E727', $discovery->decodeSerial(hex2bin('58504F4E2308E727')));

        // Ya legible: se respeta
        $this->assertSame('HWTC-DD5C64C6', $discovery->decodeSerial('HWTC-DD5C64C6'));

        // Vacío o inválido
        $this->assertNull($discovery->decodeSerial(''));
        $this->assertNull($discovery->decodeSerial('xx'));
    }

    public function test_empareja_la_ont_con_el_contrato_por_el_documento(): void
    {
        $contrato = $this->contratoPara('94280438');

        $discovery = app(OltOntDiscovery::class);

        // Formato real de las descripciones del equipo
        $encontrado = $discovery->matchContract(
            'BT000353 - 94280438 - JOSE ARGEMIRO MARIN ALV',
            $this->branch->id
        );

        $this->assertSame($contrato->id, $encontrado);
    }

    public function test_no_asigna_contrato_si_el_documento_no_existe(): void
    {
        $this->contratoPara('94280438');

        $discovery = app(OltOntDiscovery::class);

        $this->assertNull($discovery->matchContract('BT000999 - 11111111 - OTRO CLIENTE', $this->branch->id));
        $this->assertNull($discovery->matchContract('', $this->branch->id));
        $this->assertNull($discovery->matchContract('ONT sin datos', $this->branch->id));
    }

    public function test_no_asigna_contrato_cuando_el_cliente_tiene_varios(): void
    {
        $contrato = $this->contratoPara('94280438');

        // Segundo contrato para el mismo cliente: la asignación deja
        // de ser inequívoca y debe quedar en manos de una persona
        Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $contrato->client_id,
            'plan_id' => $contrato->plan_id,
            'user_id' => $this->admin->id,
        ]);

        $this->assertNull(
            app(OltOntDiscovery::class)->matchContract('94280438 - CLIENTE', $this->branch->id)
        );
    }

    public function test_importa_las_ont_nuevas_y_omite_las_existentes(): void
    {
        // Una ONT ya registrada en GestISP
        Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt->id,
            'slot' => 1,
            'port' => 0,
            'onu_id' => 1,
            'sn' => 'HWTC-AAAA0001',
            'status' => 1,
        ]);

        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-AAAA0001']),                        // ya existe
            $this->ontEncontrada(['sn' => 'HWTC-BBBB0002', 'onu_id' => 2]),         // nueva
            $this->ontEncontrada(['sn' => 'OEMT-CCCC0003', 'onu_id' => 3]),         // nueva
            $this->ontEncontrada(['sn' => 'XPON-DDDD0004', 'slot' => null, 'port' => null]), // sin ubicación
        ]);

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        $run->refresh();

        $this->assertSame(OntImportRun::ESTADO_COMPLETADO, $run->status);
        $this->assertSame(4, $run->total_found);
        $this->assertSame(2, $run->imported);
        $this->assertSame(1, $run->skipped_existing);
        $this->assertSame(1, $run->skipped_invalid);

        // La ONT que ya existía no se duplicó
        $this->assertSame(1, Ont::where('sn', 'HWTC-AAAA0001')->count());
        $this->assertSame(3, Ont::where('olt_id', $this->olt->id)->count());
    }

    public function test_asigna_el_contrato_al_importar_cuando_lo_identifica(): void
    {
        $contrato = $this->contratoPara('1042774321');

        $this->simularDescubrimiento([
            $this->ontEncontrada([
                'sn' => 'XPON-23160D60',
                'description' => '1042774321 - DUBAN FERNEY BARRIENTOS',
            ]),
        ]);

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        $ont = Ont::where('sn', 'XPON-23160D60')->firstOrFail();

        $this->assertSame($contrato->id, $ont->contract_id);
        $this->assertSame(1, $run->fresh()->matched_contracts);
    }

    public function test_la_ont_importada_sin_contrato_queda_sin_asignar(): void
    {
        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-EEEE0005', 'description' => '']),
        ]);

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        // contract_id admite null desde la migración de importación
        $ont = Ont::where('sn', 'HWTC-EEEE0005')->firstOrFail();
        $this->assertNull($ont->contract_id);
    }

    public function test_registra_el_fallo_si_la_olt_no_responde(): void
    {
        $this->mock(OltOntDiscovery::class, function ($mock) {
            $mock->shouldReceive('discover')
                ->andThrow(new \RuntimeException('La OLT no responde por SNMP.'));
        });

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        $run->refresh();

        $this->assertSame(OntImportRun::ESTADO_FALLIDO, $run->status);
        $this->assertStringContainsString('no responde', $run->message);
        $this->assertSame(0, Ont::count());
    }

    public function test_la_importacion_se_encola_y_no_bloquea_la_peticion(): void
    {
        Queue::fake();

        $this->post(route('onts.import.store'), ['olt_id' => $this->olt->id])
            ->assertRedirect(route('onts.import.index'))
            ->assertSessionHas('success');

        Queue::assertPushed(ImportOltOnts::class);

        $this->assertDatabaseHas('ont_import_runs', [
            'olt_id' => $this->olt->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);
    }

    public function test_impide_dos_importaciones_simultaneas_de_la_misma_olt(): void
    {
        Queue::fake();

        OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_EJECUTANDO,
        ]);

        $this->post(route('onts.import.store'), ['olt_id' => $this->olt->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_no_permite_importar_una_olt_de_otra_sucursal(): void
    {
        Queue::fake();

        // Misma empresa, otra sede
        $otraSucursal = Branch::factory()->create(['company_id' => $this->branch->company_id]);
        $oltAjena = Olt::create([
            'branch_id' => $otraSucursal->id,
            'name' => 'OLT ajena',
            'ip_address' => '10.9.9.9',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'username' => 'admin', 'password' => 'x', 'uptime' => '0',
        ]);

        $this->post(route('onts.import.store'), ['olt_id' => $oltAjena->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_el_endpoint_de_estado_informa_el_avance(): void
    {
        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_EJECUTANDO,
            'total_found' => 200,
            'processed' => 50,
            'imported' => 45,
        ]);

        $this->getJson(route('onts.import.status', $run))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'en_curso' => true,
                'porcentaje' => 25,
                'imported' => 45,
            ]);
    }

    public function test_la_pantalla_de_importacion_carga(): void
    {
        $this->get(route('onts.import.index'))
            ->assertOk()
            ->assertSee('Importar ONTs', false)
            ->assertSee($this->olt->name, false)
            ->assertSee('solo de lectura', false);
    }

    /**
     * Regresión: el login guarda branch_id como TEXTO (viene de un
     * campo de formulario). Comparar en PHP sin convertirlo
     * ('1' !== 1) hacía que el sistema rechazara con "Esa OLT
     * pertenece a otra sucursal" una OLT que sí era de la sucursal
     * — y que además aparecía en el listado, porque ahí la
     * comparación la hace SQL.
     */
    public function test_funciona_con_la_sucursal_guardada_como_texto_en_sesion(): void
    {
        Queue::fake();

        $role = Role::where('name', 'superadministrador')->firstOrFail();

        // Sesión idéntica a la que arma el ingreso real
        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);

        // El listado debe seguir mostrando la OLT
        $this->get(route('onts.import.index'))
            ->assertOk()
            ->assertSee($this->olt->name, false);

        // Y el análisis NO debe rechazarla
        $this->simularDescubrimiento([$this->ontEncontrada()]);

        $this->postJson(route('onts.import.preview'), ['olt_id' => $this->olt->id])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Ni la importación
        $this->post(route('onts.import.store'), ['olt_id' => $this->olt->id])
            ->assertRedirect(route('onts.import.index'))
            ->assertSessionHas('success');

        Queue::assertPushed(ImportOltOnts::class);
    }

    /**
     * La protección entre sucursales debe seguir funcionando aunque
     * la sesión traiga la sucursal como texto.
     */
    public function test_sigue_bloqueando_olts_de_otra_sucursal_con_sesion_de_texto(): void
    {
        Queue::fake();

        // Misma empresa, otra sede
        $otraSucursal = Branch::factory()->create(['company_id' => $this->branch->company_id]);
        $oltAjena = Olt::create([
            'branch_id' => $otraSucursal->id,
            'name' => 'OLT ajena',
            'ip_address' => '10.9.9.9',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'username' => 'admin', 'password' => 'x', 'uptime' => '0',
        ]);

        $this->withSession(['branch_id' => (string) $this->branch->id]);

        $this->postJson(route('onts.import.preview'), ['olt_id' => $oltAjena->id])
            ->assertStatus(403);

        $this->post(route('onts.import.store'), ['olt_id' => $oltAjena->id])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
    // ==================== Lo que NO se importó ====================

    /**
     * «200 omitidas por datos incompletos» no servia de nada.
     *
     * Ni cuales eran ni que les faltaba, asi que no habia nada que
     * corregir. Ahora cada una dice su serial, su posicion y el
     * motivo.
     */
    public function test_dice_cual_ont_no_entro_y_por_que(): void
    {
        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-BUENA001']),
            $this->ontEncontrada(['sn' => 'HWTC-SINSLOT1', 'slot' => null]),
            $this->ontEncontrada(['sn' => 'HWTC-SINPORT1', 'port' => null]),
        ]);

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        $run->refresh();

        $this->assertSame(1, $run->imported);
        $this->assertSame(2, $run->skipped_invalid);

        $detalle = collect($run->skipped_details);

        $this->assertCount(2, $detalle);
        $this->assertSame(
            ['HWTC-SINSLOT1', 'HWTC-SINPORT1'],
            $detalle->pluck('sn')->all(),
        );

        // Y el motivo distingue QUE falta, no un «datos incompletos»
        $this->assertStringContainsString('tarjeta', $detalle->firstWhere('sn', 'HWTC-SINSLOT1')['motivo']);
        $this->assertStringContainsString('puerto', $detalle->firstWhere('sn', 'HWTC-SINPORT1')['motivo']);
    }

    public function test_el_detalle_se_ve_en_la_pantalla(): void
    {
        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-SINSLOT1', 'slot' => null]),
        ]);

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        $this->get(route('onts.import.index'))
            ->assertOk()
            ->assertSee('HWTC-SINSLOT1', false)
            ->assertSee('que no entraron', false);
    }

    public function test_una_ont_mala_ya_no_se_lleva_por_delante_el_lote(): void
    {
        // Era el origen de los «200 omitidos»: el lote iba en una sola
        // transaccion y, si una fila reventaba, las otras noventa y
        // nueve buenas se contaban como «datos incompletos». El motivo
        // real solo quedaba en el log del servidor.
        //
        // Una ONT con un serial imposiblemente largo revienta su
        // INSERT; las demas tienen que entrar igual.
        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-BUENA001']),
            $this->ontEncontrada(['sn' => str_repeat('X', 600)]),
            $this->ontEncontrada(['sn' => 'HWTC-BUENA002']),
        ]);

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        $run->refresh();

        $this->assertSame(2, $run->imported, 'las buenas entran');
        $this->assertNotNull(Ont::where('sn', 'HWTC-BUENA001')->first());
        $this->assertNotNull(Ont::where('sn', 'HWTC-BUENA002')->first());

        // Y la mala dice por que, en castellano
        $this->assertSame(1, $run->skipped_invalid);
        $this->assertNotEmpty($run->skipped_details);
        $this->assertNotEmpty($run->skipped_details[0]['motivo']);
    }

    /**
     * El ANALISIS dice cuales no entrarian, antes de importar.
     *
     * Es donde sirve de verdad: el detalle de despues explica lo que
     * ya paso, este permite corregir la OLT antes de tocar nada.
     */
    public function test_el_analisis_lista_las_ont_que_no_entrarian(): void
    {
        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-BUENA001']),
            $this->ontEncontrada(['sn' => 'HWTC-SINSLOT1', 'slot' => null, 'description' => 'BT000353 - VIEJA']),
        ]);

        $respuesta = $this->postJson(route('onts.import.preview'), ['olt_id' => $this->olt->id])
            ->assertOk()
            ->json();

        $this->assertTrue($respuesta['ok']);

        // Las que entran NO cuentan a la que le falta la ubicacion
        $this->assertSame(1, $respuesta['nuevas']);
        $this->assertSame(1, $respuesta['sin_ubicacion']);

        $this->assertCount(1, $respuesta['problemas']);
        $this->assertSame('HWTC-SINSLOT1', $respuesta['problemas'][0]['sn']);
        $this->assertStringContainsString('tarjeta', $respuesta['problemas'][0]['motivo']);
        $this->assertSame('BT000353 - VIEJA', $respuesta['problemas'][0]['descripcion']);

        // Y la muestra solo trae las importables
        $this->assertCount(1, $respuesta['muestra']);
        $this->assertSame('HWTC-BUENA001', $respuesta['muestra'][0]['sn']);

        // Sin escribir nada
        $this->assertSame(0, Ont::count());
    }

    public function test_el_analisis_no_inventa_problemas_cuando_no_los_hay(): void
    {
        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-BUENA001']),
            $this->ontEncontrada(['sn' => 'HWTC-BUENA002']),
        ]);

        $respuesta = $this->postJson(route('onts.import.preview'), ['olt_id' => $this->olt->id])
            ->assertOk()
            ->json();

        $this->assertSame(2, $respuesta['nuevas']);
        $this->assertSame(0, $respuesta['sin_ubicacion']);
        $this->assertSame([], $respuesta['problemas']);
    }

    // ==================== Lo que la OLT devuelve no siempre es UTF-8 ====================

    /**
     * Una descripcion en Latin-1 ya no tumba la importacion.
     *
     * Paso en produccion: la corrida murio al 77 % con «Unable to
     * encode attribute [skipped_details] ... Malformed UTF-8
     * characters». Las descripciones las escribe quien configura la
     * OLT, a veces desde una consola en Latin-1, y la columna del
     * detalle es JSON: `json_encode` se niega ante esos bytes. Lo que
     * mato la importacion fue justamente la lista que existe para
     * explicar los fallos.
     */
    public function test_una_descripcion_que_no_es_utf8_no_tumba_la_importacion(): void
    {
        // «JOSE MUNOZ» con la E y la N acentuadas en Latin-1.
        // Se arma con chr() y no con un literal: escrito a mano, el
        // propio editor lo guardaria como UTF-8 y la prueba dejaria
        // de probar lo que dice probar.
        $latin1 = "BT000353 - JOS" . chr(0xC9) . " MU" . chr(0xD1) . "OZ";

        $this->assertFalse(mb_check_encoding($latin1, 'UTF-8'), 'la prueba necesita bytes invalidos');

        $this->simularDescubrimiento([
            $this->ontEncontrada(['sn' => 'HWTC-BUENA001', 'description' => $latin1]),
            $this->ontEncontrada(['sn' => 'HWTC-SINSLOT1', 'slot' => null, 'description' => $latin1]),
        ]);

        $run = OntImportRun::create([
            'olt_id' => $this->olt->id,
            'branch_id' => $this->branch->id,
            'status' => OntImportRun::ESTADO_PENDIENTE,
        ]);

        dispatch_sync(new ImportOltOnts($run->id));

        $run->refresh();

        // La importacion TERMINA
        $this->assertSame(OntImportRun::ESTADO_COMPLETADO, $run->status);
        $this->assertSame(1, $run->imported);

        // Y el detalle se guardo, legible
        $this->assertNotEmpty($run->skipped_details);
        $this->assertTrue(mb_check_encoding(json_encode($run->skipped_details), 'UTF-8'));
    }

    public function test_el_saneador_respeta_lo_que_ya_es_utf8(): void
    {
        // Una descripcion con tildes BIEN escritas no se toca
        $this->assertSame(
            'BT000353 - JOSÉ MUÑOZ',
            \App\Services\OltOntDiscovery::textoSeguro('BT000353 - JOSÉ MUÑOZ'),
        );

        // Y la que no es UTF-8 sale convertida, no vacia
        $convertida = \App\Services\OltOntDiscovery::textoSeguro("JOSÉ");

        $this->assertTrue(mb_check_encoding($convertida, 'UTF-8'));
        $this->assertNotSame('', $convertida);
    }

}
