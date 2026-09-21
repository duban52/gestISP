<?php

namespace Tests\Feature\Contracts;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\DiscountType;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\InvoiceType;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\OverdueProcessor;
use App\Models\AditionalCharge;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractCession;
use App\Models\Invoice;
use App\Models\NapBox;
use App\Models\NapPort;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\OpticalNetwork;
use App\Models\Plan;
use App\Models\PonPort;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\Service;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Services\ContractCessionService;
use App\Services\MikrotikApiService;
use App\Services\OdnManager;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cesión de contrato: el servicio pasa a otro titular sin cortarse.
 *
 * LO QUE SE DEFIENDE, EN ORDEN DE GRAVEDAD
 * ----------------------------------------
 * 1. El historial fiscal del cedente NO cambia de dueño. Las facturas
 *    leen al cliente en vivo del contrato: si la cesión tocara el
 *    contrato viejo, sus facturas pasarían a nombre del nuevo titular.
 *
 * 2. No se cobra dos veces el mismo mes. El mes de la cesión lo paga el
 *    cedente; el nuevo titular empieza el mes siguiente.
 *
 * 3. Los equipos cambian de contrato, NO de red. Ni el router ni la OLT
 *    reciben una sola orden.
 *
 * 4. Las reglas del negocio: paz y salvo, cuotas liquidadas al cedente,
 *    y nada de ceder lo que ya terminó.
 */
class ContractCessionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        // Mitad de mes: el caso interesante, donde el prorrateo y el
        // doble cobro se ven.
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        // Estos dos salen de `permissions:sync`, no del seeder: en la
        // base de pruebas hay que crearlos, como en producción los crea
        // la sincronización.
        foreach (['contracts.cede', 'contracts.status'] as $permiso) {
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permiso, 'guard_name' => 'web'],
                ['description' => \App\Support\PermissionLabels::describe($permiso)],
            );
            $rol->givePermissionTo($permiso);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $servicio = Service::factory()->create([
            'base_price' => 80000,
            'tax_percentage' => 0,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);
        $this->plan->services()->attach($servicio->id);

        // La red y el router no pueden recibir ni una orden: la cesión
        // cambia papeles, no equipos.
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldNotReceive('setPppSecretState'));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState')
            ->shouldNotReceive('deleteOnt'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ==================== Apoyo ====================

    private function cliente(array $extra = []): Client
    {
        return Client::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ], $extra));
    }

    private function contrato(Client $cliente, string $estado = 'Activo', array $extra = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'user_id' => $this->admin->id,
            'status' => $estado,
            'activation_date' => '2026-01-10',
            'address' => 'Calle 20 # 19-30',
            'user_pppoe' => 'cliente01',
            'password_pppoe' => 'secreto',
            'cpe_sn' => 'HWTC12345678',
        ], $extra));
    }

    /** El contrato con su puerto NAP, su cuenta PPPoE y su ONT. */
    private function conEquipos(Contract $contrato): Contract
    {
        $red = OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red de prueba',
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'optical_network_id' => $red->id,
            'name' => 'OLT de prueba',
            'ip_address' => '10.0.0.10',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'root', 'password' => 'admin',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        $pon = PonPort::create([
            'optical_network_id' => $red->id,
            'olt_id' => $olt->id,
            'frame' => 0, 'slot' => 1, 'port' => 1,
            'max_onts' => 64,
            'active' => true,
        ]);

        $caja = app(OdnManager::class)->crearCaja($red, [
            'pon_port_id' => $pon->id,
            'capacity' => 8,
            'address' => 'Calle 20 # 19-30',
            'latitude' => 6.2442,
            'longitude' => -75.5812,
            'status' => NapBox::OPERATIVA,
        ]);

        $puerto = NapPort::where('nap_box_id', $caja->id)->orderBy('number')->firstOrFail();

        $contrato->update([
            'nap_port_id' => $puerto->id,
            'nap_port' => $caja->code . ' / P' . $puerto->number,
        ]);

        $router = Router::create([
            'branch_id' => $this->branch->id,
            'name' => 'Router de prueba',
            'ip_address' => '10.0.0.1',
            'username' => 'admin',
            'password' => 'admin',
        ]);

        PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $router->id,
            'contract_id' => $contrato->id,
            'username' => 'cliente01',
            'password' => 'secreto',
            'profile' => 'default',
            'mikrotik_id' => '*1A',
            'disabled' => false,
        ]);

        Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $olt->id,
            'contract_id' => $contrato->id,
            'slot' => '1', 'port' => '2', 'onu_id' => 7,
            'sn' => 'HWTC12345678',
            'admin_enabled' => true,
        ]);

        return $contrato->fresh();
    }

    /** La factura del mes, ya pagada: el cedente está a paz y salvo. */
    private function facturaDelMesPagada(Contract $contrato): Invoice
    {
        $factura = app(InvoiceGenerator::class)
            ->generateForContract($contrato, now(), $this->admin->id)['invoice'];

        $factura->update(['pending_invoice_amount' => 0, 'status' => InvoiceStatus::Pagada->value]);

        return $factura->fresh();
    }

    private function ceder(Contract $origen, Client $cesionario, array $datos = []): ContractCession
    {
        return app(ContractCessionService::class)->ceder(
            $origen,
            $cesionario,
            $datos + ['reason' => 'El titular se muda; el arrendatario se queda con el servicio.'],
            $this->admin->id,
        );
    }

    // ==================== 1. El historial no cambia de dueño ====================

    public function test_la_cesion_crea_un_contrato_nuevo_y_cierra_el_viejo(): void
    {
        $cedente = $this->cliente();
        $cesionario = $this->cliente();
        $origen = $this->contrato($cedente);
        $this->facturaDelMesPagada($origen);

        $cesion = $this->ceder($origen, $cesionario);

        $origen->refresh();
        $nuevo = $cesion->toContract;

        $this->assertSame(ContractStatus::Cedido->value, $origen->status);
        $this->assertSame($cesionario->id, $nuevo->client_id);
        $this->assertNotSame($origen->id, $nuevo->id);
        $this->assertNotNull($nuevo->contract_number);
        $this->assertNotSame($origen->contract_number, $nuevo->contract_number);

        // El registro guarda a los dos, y la razón.
        $this->assertSame($cedente->id, $cesion->from_client_id);
        $this->assertSame($cesionario->id, $cesion->to_client_id);
        $this->assertStringContainsString('arrendatario', $cesion->reason);
    }

    public function test_las_facturas_del_cedente_siguen_a_su_nombre(): void
    {
        // El punto entero de hacer un contrato nuevo: el XML, las notas
        // y los avisos leen al cliente del contrato de la factura.
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);
        $factura = $this->facturaDelMesPagada($origen);

        $this->ceder($origen, $this->cliente());

        $this->assertSame($cedente->id, $factura->fresh()->contract->client_id);
    }

    public function test_el_contrato_nuevo_deja_constancia_del_titular_anterior(): void
    {
        // Con documento Y nombre completo: dos clientes pueden llamarse
        // igual, y es con el documento con lo que se identifica a quien
        // firmó.
        $cedente = $this->cliente([
            'name' => 'Rebeca',
            'last_name' => 'Arango',
            'identity_number' => '1037045539',
            'type_document' => 'CC',
        ]);
        $cesionario = $this->cliente([
            'name' => 'Jaime',
            'last_name' => 'Monsalve',
            'identity_number' => '3573573',
            'type_document' => 'CC',
        ]);
        $origen = $this->contrato($cedente);
        $this->facturaDelMesPagada($origen);

        $nuevo = $this->ceder($origen, $cesionario)->toContract;

        $comentario = \App\Models\ContractComment::where('contract_id', $nuevo->id)->value('body');

        $this->assertStringContainsString('Se realizó cesión del contrato', $comentario);
        $this->assertStringContainsString('Titular anterior: CC 1037045539 — Rebeca Arango', $comentario);

        // Y el viejo dice a quién pasó.
        $this->assertStringContainsString(
            'Titular nuevo: CC 3573573 — Jaime Monsalve',
            \App\Models\ContractComment::where('contract_id', $origen->id)->value('body'),
        );
    }

    // ==================== 2. Sin doble cobro ====================

    public function test_si_el_mes_ya_estaba_facturado_no_se_factura_otra_vez(): void
    {
        $origen = $this->contrato($this->cliente());
        $this->facturaDelMesPagada($origen);

        $cesion = $this->ceder($origen, $this->cliente());

        $this->assertSame(1, Invoice::where('contract_id', $origen->id)->count());
        $this->assertSame(0, Invoice::where('contract_id', $cesion->to_contract_id)->count());
    }

    public function test_si_el_mes_no_estaba_facturado_se_le_factura_al_cedente(): void
    {
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);

        $this->ceder($origen, $this->cliente());

        $factura = Invoice::where('contract_id', $origen->id)
            ->where('billed_year_month', '202609')
            ->first();

        $this->assertNotNull($factura, 'No se le facturó al cedente el mes de la cesión.');
        $this->assertSame($cedente->id, $factura->contract->client_id);
    }

    public function test_el_nuevo_titular_no_se_factura_el_mes_de_la_cesion(): void
    {
        $origen = $this->contrato($this->cliente());
        $this->facturaDelMesPagada($origen);

        $nuevo = $this->ceder($origen, $this->cliente())->toContract;

        // La corrida del mismo mes lo salta...
        $resultado = app(InvoiceGenerator::class)->generateForContract($nuevo->fresh(), now(), $this->admin->id);
        $this->assertFalse($resultado['generated']);

        // ...y la del mes siguiente le factura el mes COMPLETO: no es un
        // contrato recién instalado para prorratear.
        $siguiente = app(InvoiceGenerator::class)
            ->generateForContract($nuevo->fresh(), now()->addMonthNoOverflow(), $this->admin->id);

        $this->assertTrue($siguiente['generated']);
        $this->assertEquals(80000, $siguiente['invoice']->total);
    }

    // ==================== 3. Equipos: de contrato, no de red ====================

    public function test_los_equipos_y_el_puerto_pasan_al_contrato_nuevo(): void
    {
        $origen = $this->conEquipos($this->contrato($this->cliente()));
        $this->facturaDelMesPagada($origen);
        $puertoId = $origen->nap_port_id;

        $nuevo = $this->ceder($origen, $this->cliente())->toContract->fresh();
        $origen->refresh();

        // El puerto: lo tiene el nuevo y lo soltó el viejo.
        $this->assertSame($puertoId, $nuevo->nap_port_id);
        $this->assertNull($origen->nap_port_id);
        $this->assertNull($origen->nap_port);

        // La cuenta PPPoE, con las MISMAS credenciales.
        $cuenta = PppoeAccount::where('username', 'cliente01')->firstOrFail();
        $this->assertSame($nuevo->id, $cuenta->contract_id);
        $this->assertFalse($cuenta->disabled);
        $this->assertSame('cliente01', $nuevo->user_pppoe);

        // La ONT, habilitada como estaba.
        $ont = Ont::where('sn', 'HWTC12345678')->firstOrFail();
        $this->assertSame($nuevo->id, $ont->contract_id);
        $this->assertTrue($ont->admin_enabled);

        // El viejo ya no refleja las credenciales: buscar el usuario
        // PPPoE no puede devolver dos contratos.
        $this->assertNull($origen->user_pppoe);
        $this->assertNull($origen->cpe_sn);
    }

    public function test_el_contrato_nuevo_hereda_los_datos_del_servicio(): void
    {
        $origen = $this->contrato($this->cliente(), 'Activo', [
            'latitude' => 6.2442,
            'longitude' => -75.5812,
            'permanence_clause' => 12,
        ]);
        $this->facturaDelMesPagada($origen);

        $nuevo = $this->ceder($origen, $this->cliente())->toContract;

        $this->assertSame($this->plan->id, $nuevo->plan_id);
        $this->assertSame('Calle 20 # 19-30', $nuevo->address);
        $this->assertEquals(6.2442, (float) $nuevo->latitude);
        // El cesionario asume lo que quedaba de permanencia.
        $this->assertSame(12, (int) $nuevo->permanence_clause);
        $this->assertSame(ContractStatus::Activo->value, $nuevo->status);
    }

    // ==================== 4. Las reglas del negocio ====================

    public function test_no_se_cede_con_deuda(): void
    {
        // Decisión del negocio: paz y salvo.
        $origen = $this->contrato($this->cliente());

        Invoice::create([
            'contract_id' => $origen->id,
            'branch_id' => $this->branch->id,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-21',
            'billed_year_month' => '202608',
            'subtotal' => 80000, 'discount' => 0, 'tax' => 0, 'total' => 80000,
            'pending_invoice_amount' => 80000,
            'status' => InvoiceStatus::Vencida->value,
        ]);

        try {
            $this->ceder($origen, $this->cliente());
            $this->fail('Se cedió un contrato con deuda.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('paz y salvo', $e->getMessage());
        }

        $this->assertSame('Activo', $origen->fresh()->status);
        $this->assertSame(0, ContractCession::count());
    }

    public function test_las_cuotas_pendientes_se_le_liquidan_al_cedente(): void
    {
        $origen = $this->contrato($this->cliente());
        $this->facturaDelMesPagada($origen);

        AditionalCharge::create([
            'contract_id' => $origen->id,
            'user_id' => $this->admin->id,
            'description' => 'Router WiFi',
            'amount' => 300000,
            'tax_percentage' => 0,
            'installments_total' => 6,
            'installments_billed' => 2,
            'status' => 'pendiente',
        ]);

        $nuevo = $this->ceder($origen, $this->cliente())->toContract;

        $liquidacion = Invoice::where('contract_id', $origen->id)
            ->where('type', InvoiceType::Liquidacion->value)
            ->first();

        $this->assertNotNull($liquidacion);
        $this->assertEquals(200000, $liquidacion->total);   // 4 cuotas de 50.000
        $this->assertSame(0, $nuevo->additionalCharges()->count());
    }

    public function test_el_descuento_no_pasa_al_nuevo_titular(): void
    {
        $origen = $this->contrato($this->cliente(), 'Activo', [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 50,
        ]);
        $this->facturaDelMesPagada($origen);

        $nuevo = $this->ceder($origen, $this->cliente())->toContract;

        $this->assertFalse($nuevo->descuentoVigente());
    }

    public function test_un_estado_con_servicio_que_no_factura_nace_activo(): void
    {
        // «Exonerado» era un beneficio de esa persona, no de la casa.
        \App\Models\ContractStatusOption::create([
            'name' => 'Exonerado',
            'bills' => false,
            'auto_bills' => false,
            'has_service' => true,
            'active' => true,
        ]);

        $origen = $this->contrato($this->cliente(), 'Exonerado');

        $nuevo = $this->ceder($origen, $this->cliente())->toContract;

        $this->assertSame(ContractStatus::Activo->value, $nuevo->status);
    }

    public function test_no_se_cede_un_contrato_terminado(): void
    {
        $origen = $this->contrato($this->cliente(), ContractStatus::Retirado->value);

        $this->expectException(RuntimeException::class);
        $this->ceder($origen, $this->cliente());
    }

    public function test_no_se_cede_un_contrato_por_instalar(): void
    {
        $origen = $this->contrato($this->cliente(), ContractStatus::PorInstalar->value);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no hay nada que ceder');
        $this->ceder($origen, $this->cliente());
    }

    public function test_no_se_cede_al_mismo_titular(): void
    {
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);

        $this->expectException(RuntimeException::class);
        $this->ceder($origen, $cedente);
    }

    public function test_no_se_cede_a_un_cliente_de_otra_sucursal(): void
    {
        // La corrida mensual elige por la sucursal del cliente: el
        // contrato se facturaría con las reglas y la numeración de otra.
        $origen = $this->contrato($this->cliente());
        $ajeno = $this->cliente(['branch_id' => Branch::factory()->create()->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('otra sucursal');
        $this->ceder($origen, $ajeno);
    }

    public function test_no_se_cede_con_una_orden_en_curso(): void
    {
        $origen = $this->contrato($this->cliente());

        TechnicalOrder::create([
            'contract_id' => $origen->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            'type' => TechnicalOrder::SERVICIO,
            'detail' => 'Traslado de servicio',
            'status' => 'Pendiente',
            'initial_comment' => 'Traslado',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('orden técnica en curso');
        $this->ceder($origen, $this->cliente());
    }

    public function test_un_contrato_solo_se_cede_una_vez(): void
    {
        $origen = $this->contrato($this->cliente());
        $this->facturaDelMesPagada($origen);

        $this->ceder($origen, $this->cliente());

        // Ya está «Cedido»: es un estado final.
        $this->expectException(RuntimeException::class);
        $this->ceder($origen->fresh(), $this->cliente());
    }

    public function test_si_algo_falla_no_queda_nada_a_medias(): void
    {
        // Un cesionario de otra sucursal falla en la validación, que va
        // DENTRO de la transacción tras bloquear: ni factura del mes, ni
        // contrato nuevo, ni estado cambiado.
        $origen = $this->contrato($this->cliente());
        $ajeno = $this->cliente(['branch_id' => Branch::factory()->create()->id]);

        try {
            $this->ceder($origen, $ajeno);
        } catch (RuntimeException) {
        }

        $this->assertSame('Activo', $origen->fresh()->status);
        $this->assertSame(0, Invoice::where('contract_id', $origen->id)->count());
        $this->assertSame(1, Contract::count());
    }

    // ==================== Lo que viene después ====================

    public function test_la_mora_no_resucita_un_contrato_cedido(): void
    {
        // El cedente no paga sus facturas de cierre. La mora diaria le
        // marca vencidas, pero no puede pasar su contrato a
        // «Suspendido»: parecería de nuevo vivo, con los equipos de otro.
        $origen = $this->contrato($this->cliente());
        $this->ceder($origen, $this->cliente());

        foreach (['202607' => '2026-07-21', '202608' => '2026-08-21'] as $periodo => $vence) {
            Invoice::create([
                'contract_id' => $origen->id,
                'branch_id' => $this->branch->id,
                'issue_date' => Carbon::parse($vence)->subDays(20),
                'due_date' => $vence,
                'billed_year_month' => $periodo,
                'subtotal' => 80000, 'discount' => 0, 'tax' => 0, 'total' => 80000,
                'pending_invoice_amount' => 80000,
                'status' => InvoiceStatus::Vencida->value,
            ]);
        }

        app(OverdueProcessor::class)->refreshContractSuspensions($this->branch->id);

        $this->assertSame(ContractStatus::Cedido->value, $origen->fresh()->status);
    }

    public function test_la_mora_tampoco_suspende_un_retirado(): void
    {
        // El mismo fallo existía para los retirados: dos vencidas y el
        // contrato dado de baja volvía a «Suspendido».
        $retirado = $this->contrato($this->cliente(), ContractStatus::Retirado->value);

        foreach (['202607' => '2026-07-21', '202608' => '2026-08-21'] as $periodo => $vence) {
            Invoice::create([
                'contract_id' => $retirado->id,
                'branch_id' => $this->branch->id,
                'issue_date' => Carbon::parse($vence)->subDays(20),
                'due_date' => $vence,
                'billed_year_month' => $periodo,
                'subtotal' => 80000, 'discount' => 0, 'tax' => 0, 'total' => 80000,
                'pending_invoice_amount' => 80000,
                'status' => InvoiceStatus::Vencida->value,
            ]);
        }

        app(OverdueProcessor::class)->refreshContractSuspensions($this->branch->id);

        $this->assertSame(ContractStatus::Retirado->value, $retirado->fresh()->status);
    }

    public function test_cedido_no_se_ofrece_al_cambiar_el_estado_a_mano(): void
    {
        // Llegar a «Cedido» sin la cesión dispararía la baja definitiva
        // sobre equipos que usa otro.
        $origen = $this->contrato($this->cliente());

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $origen->id,
            'target_contract_status' => ContractStatus::Cedido->value,
            'initial_comment' => 'Intento a mano',
        ])->assertSessionHasErrors('target_contract_status');

        $this->assertSame('Activo', $origen->fresh()->status);
    }

    public function test_la_cesion_no_cuenta_como_alta_ni_como_baja(): void
    {
        // Es el mismo servicio en la misma casa: ni crece la base ni se
        // pierde un cliente.
        $origen = $this->contrato($this->cliente(), 'Activo', ['activation_date' => '2025-01-10']);
        $this->facturaDelMesPagada($origen);

        $this->ceder($origen, $this->cliente());

        $periodo = \App\Reports\Support\ReportPeriod::fromRequest('2026-09-01', '2026-09-30', 'month');

        $resumen = (new \App\Reports\GrowthReport($periodo, $this->branch->id))->resumen();

        $this->assertSame(0, $resumen['altas']);
        $this->assertSame(0, $resumen['bajas']);
    }

    // ==================== La pantalla ====================

    public function test_la_pantalla_avisa_de_lo_que_impide_ceder(): void
    {
        $origen = $this->contrato($this->cliente(), ContractStatus::PorInstalar->value);

        $this->get(route('contracts.cession.create', $origen))
            ->assertOk()
            ->assertSee('No se puede ceder todavía')
            ->assertDontSee('name="client_id"', false);
    }

    public function test_se_cede_desde_la_pantalla_y_lleva_al_contrato_nuevo(): void
    {
        $origen = $this->contrato($this->cliente());
        $this->facturaDelMesPagada($origen);
        $cesionario = $this->cliente();

        $respuesta = $this->post(route('contracts.cession.store', $origen), [
            'client_id' => $cesionario->id,
            'reason' => 'Cambio de arrendatario',
            'confirmar' => 1,
        ]);

        $nuevo = Contract::where('client_id', $cesionario->id)->firstOrFail();

        $respuesta->assertRedirect(route('contracts.show', $nuevo));

        // Las dos fichas cuentan la historia.
        $this->get(route('contracts.show', $nuevo))->assertSee('Recibido por cesión');
        $this->get(route('contracts.show', $origen))->assertSee('Contrato cedido');
    }

    public function test_sin_confirmar_no_se_cede(): void
    {
        $origen = $this->contrato($this->cliente());

        $this->post(route('contracts.cession.store', $origen), [
            'client_id' => $this->cliente()->id,
            'reason' => 'Cambio de arrendatario',
        ])->assertSessionHasErrors('confirmar');

        $this->assertSame('Activo', $origen->fresh()->status);
    }

    public function test_el_buscador_solo_ofrece_clientes_de_la_sucursal_y_no_al_titular(): void
    {
        $cedente = $this->cliente(['name' => 'Rodrigo', 'last_name' => 'Pérez']);
        $origen = $this->contrato($cedente);
        $vecino = $this->cliente(['name' => 'Rodrigo', 'last_name' => 'Gómez']);
        $this->cliente([
            'name' => 'Rodrigo',
            'last_name' => 'Ajeno',
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $resultados = $this->getJson(route('contracts.cession.clients', [$origen, 'q' => 'Rodrigo']))
            ->assertOk()
            ->json('results');

        $this->assertSame([$vecino->id], array_column($resultados, 'id'));
    }

    public function test_sin_permiso_no_se_entra(): void
    {
        $tecnico = User::factory()->create();
        $rol = Role::where('name', 'tecnico')->firstOrFail();
        $tecnico->assignRole($rol);
        $tecnico->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($tecnico)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $origen = $this->contrato($this->cliente());

        $this->get(route('contracts.cession.create', $origen))->assertForbidden();
    }
}
