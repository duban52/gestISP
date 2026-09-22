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
 * Cesión de contrato: el MISMO contrato pasa a otro titular.
 *
 * LO QUE SE DEFIENDE, EN ORDEN DE GRAVEDAD
 * ----------------------------------------
 * 1. El historial fiscal del cedente NO cambia de dueño. Las facturas
 *    guardan su titular: aunque el contrato ya sea de otro, las viejas
 *    siguen a nombre de quien las compró.
 *
 * 2. No se cobra dos veces el mismo mes ni se le pasa una deuda al
 *    nuevo titular: el cierre se le factura al cedente y lo paga antes.
 *
 * 3. Los equipos NO se tocan. Ni el router ni la OLT reciben una sola
 *    orden: el contrato, con todo lo suyo, cambia de dueño.
 *
 * 4. Las reglas del negocio: paz y salvo, cuotas liquidadas al cedente,
 *    el estado se conserva y nada de ceder lo que ya terminó.
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

    // ==================== 1. El mismo contrato, otro titular ====================

    public function test_la_cesion_cambia_el_titular_del_mismo_contrato(): void
    {
        $cedente = $this->cliente();
        $cesionario = $this->cliente();
        $origen = $this->contrato($cedente);
        $this->facturaDelMesPagada($origen);
        $numero = $origen->contract_number;

        $cesion = $this->ceder($origen, $cesionario);

        $contrato = $origen->fresh();

        // Mismo id, mismo número, mismo estado: solo cambia el titular.
        $this->assertSame($cesionario->id, $contrato->client_id);
        $this->assertSame($numero, $contrato->contract_number);
        $this->assertSame('Activo', $contrato->status);
        $this->assertSame(1, Contract::count());

        // El registro guarda a los dos, y la razón.
        $this->assertSame($origen->id, $cesion->from_contract_id);
        $this->assertSame($origen->id, $cesion->to_contract_id);
        $this->assertSame($cedente->id, $cesion->from_client_id);
        $this->assertSame($cesionario->id, $cesion->to_client_id);
        $this->assertStringContainsString('arrendatario', $cesion->reason);
    }

    public function test_las_facturas_del_cedente_siguen_a_su_nombre(): void
    {
        // Lo que hace segura la cesión en el mismo contrato: el XML, el
        // PDF, las notas y los avisos leen el titular de la FACTURA.
        $cedente = $this->cliente(['identity_number' => '1037045539']);
        $cesionario = $this->cliente(['identity_number' => '3573573']);
        $origen = $this->contrato($cedente);
        $factura = $this->facturaDelMesPagada($origen);

        $this->ceder($origen, $cesionario);

        $this->assertSame($cedente->id, $factura->fresh()->titular()->id);

        $this->get(route('invoices.show', $factura))
            ->assertOk()
            ->assertSee('1037045539')
            ->assertDontSee('3573573');
    }

    public function test_las_facturas_siguientes_son_del_nuevo_titular(): void
    {
        $origen = $this->contrato($this->cliente());
        $this->facturaDelMesPagada($origen);
        $cesionario = $this->cliente();

        $this->ceder($origen, $cesionario);

        // El mes de la cesión ya está facturado: la corrida lo salta...
        $mismoMes = app(InvoiceGenerator::class)->generateForContract($origen->fresh(), now(), $this->admin->id);
        $this->assertFalse($mismoMes['generated']);

        // ...y el siguiente es el primero del nuevo titular, completo.
        $siguiente = app(InvoiceGenerator::class)
            ->generateForContract($origen->fresh(), now()->addMonthNoOverflow(), $this->admin->id);

        $this->assertTrue($siguiente['generated']);
        $this->assertEquals(80000, $siguiente['invoice']->total);
        $this->assertSame($cesionario->id, $siguiente['invoice']->client_id);
    }

    public function test_el_contrato_deja_constancia_del_titular_anterior(): void
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

        $this->ceder($origen, $cesionario);

        $comentario = \App\Models\ContractComment::where('contract_id', $origen->id)->value('body');

        $this->assertStringContainsString('Se realizó cesión del contrato', $comentario);
        $this->assertStringContainsString('Titular anterior: CC 1037045539 — Rebeca Arango', $comentario);
        $this->assertStringContainsString('Titular nuevo: CC 3573573 — Jaime Monsalve', $comentario);
    }

    public function test_el_estado_no_cambia(): void
    {
        \App\Models\ContractStatusOption::create([
            'name' => 'Exonerado',
            'bills' => false,
            'auto_bills' => false,
            'has_service' => true,
            'active' => true,
        ]);

        $origen = $this->contrato($this->cliente(), 'Exonerado');

        $this->ceder($origen, $this->cliente());

        $this->assertSame('Exonerado', $origen->fresh()->status);
    }

    public function test_los_equipos_y_el_puerto_no_se_tocan(): void
    {
        // Ni el router ni la OLT reciben una orden (ver setUp), y todo
        // sigue colgado del mismo contrato.
        $origen = $this->conEquipos($this->contrato($this->cliente()));
        $this->facturaDelMesPagada($origen);
        $puertoId = $origen->nap_port_id;

        $this->ceder($origen, $this->cliente());

        $contrato = $origen->fresh();

        $this->assertSame($puertoId, $contrato->nap_port_id);
        $this->assertSame('cliente01', $contrato->user_pppoe);
        $this->assertSame('HWTC12345678', $contrato->cpe_sn);

        $cuenta = PppoeAccount::where('username', 'cliente01')->firstOrFail();
        $this->assertSame($origen->id, $cuenta->contract_id);
        $this->assertFalse($cuenta->disabled);

        $ont = Ont::where('sn', 'HWTC12345678')->firstOrFail();
        $this->assertSame($origen->id, $ont->contract_id);
        $this->assertTrue($ont->admin_enabled);
    }

    // ==================== 2. El cierre del cedente ====================

    public function test_sin_las_facturas_de_cierre_no_se_cede(): void
    {
        // El mes todavía no se le facturó al cedente.
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);

        try {
            $this->ceder($origen, $this->cliente());
            $this->fail('Se cedió sin facturarle el mes al cedente.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('facturas de cierre', $e->getMessage());
        }

        $this->assertSame($cedente->id, $origen->fresh()->client_id);
    }

    public function test_el_cierre_le_factura_al_cedente_y_despues_de_pagarlo_se_cede(): void
    {
        $cedente = $this->cliente();
        $cesionario = $this->cliente();
        $origen = $this->contrato($cedente);

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

        $hechos = app(ContractCessionService::class)->emitirCierre($origen, $this->admin->id);
        $this->assertCount(2, $hechos);

        $delMes = Invoice::where('contract_id', $origen->id)->where('billed_year_month', '202609')->firstOrFail();
        $liquidacion = Invoice::where('contract_id', $origen->id)
            ->where('type', InvoiceType::Liquidacion->value)
            ->firstOrFail();

        // Las dos son del cedente. La del mes ya cobró la tercera cuota,
        // así que la liquidación trae las tres que quedaban.
        $this->assertSame($cedente->id, $delMes->client_id);
        $this->assertSame($cedente->id, $liquidacion->client_id);
        $this->assertEquals(150000, $liquidacion->total);

        // Emitidas, falta que las pague.
        try {
            $this->ceder($origen, $cesionario);
            $this->fail('Se cedió con las facturas de cierre sin pagar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('paz y salvo', $e->getMessage());
        }

        Invoice::where('contract_id', $origen->id)
            ->update(['pending_invoice_amount' => 0, 'status' => InvoiceStatus::Pagada->value]);

        $this->ceder($origen->fresh(), $cesionario);

        $this->assertSame($cesionario->id, $origen->fresh()->client_id);
        $this->assertSame(0, $origen->additionalCharges()->where('status', 'pendiente')->count());
    }

    public function test_no_se_cede_con_deuda(): void
    {
        // Decisión del negocio: paz y salvo. Con el mismo contrato es,
        // además, lo que impide que la mora le corte al nuevo titular.
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);
        $this->facturaDelMesPagada($origen);

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

        $this->assertSame($cedente->id, $origen->fresh()->client_id);
        $this->assertSame(0, ContractCession::count());
    }

    public function test_el_descuento_no_pasa_al_nuevo_titular(): void
    {
        $origen = $this->contrato($this->cliente(), 'Activo', [
            'discount_type' => DiscountType::Porcentaje->value,
            'discount_value' => 50,
        ]);
        $this->facturaDelMesPagada($origen);

        $cesion = $this->ceder($origen, $this->cliente());

        $this->assertFalse($origen->fresh()->descuentoVigente());
        $this->assertStringContainsString('descuento', implode(' ', $cesion->summary['hechos']));
    }

    public function test_se_avisa_del_saldo_a_favor_que_queda_en_el_contrato(): void
    {
        $origen = $this->contrato($this->cliente());
        $this->facturaDelMesPagada($origen);

        app(\App\Billing\Services\CreditBalanceService::class)
            ->abonar($origen, 50000, \App\Models\AccountCredit::ORIGEN_ANTICIPO, 'Pago por adelantado');

        $this->assertEquals(50000, app(ContractCessionService::class)->revisar($origen)['saldo_a_favor']);

        $cesion = $this->ceder($origen, $this->cliente());

        $this->assertStringContainsString('saldo a favor de $50.000,00', implode(' ', $cesion->summary['avisos']));
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
        $this->facturaDelMesPagada($origen);

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

    public function test_un_contrato_se_puede_ceder_mas_de_una_vez(): void
    {
        $primero = $this->cliente();
        $segundo = $this->cliente();
        $tercero = $this->cliente();
        $origen = $this->contrato($primero);
        $this->facturaDelMesPagada($origen);

        $this->ceder($origen, $segundo);
        $this->ceder($origen->fresh(), $tercero);

        $this->assertSame($tercero->id, $origen->fresh()->client_id);
        $this->assertSame(
            [[$primero->id, $segundo->id], [$segundo->id, $tercero->id]],
            $origen->cambiosDeTitular()->get()->map(fn ($c) => [$c->from_client_id, $c->to_client_id])->all(),
        );
    }

    public function test_si_algo_falla_no_queda_nada_a_medias(): void
    {
        // Un cesionario de otra sucursal falla en la validación, que va
        // DENTRO de la transacción tras bloquear.
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);
        $this->facturaDelMesPagada($origen);
        $ajeno = $this->cliente(['branch_id' => Branch::factory()->create()->id]);

        try {
            $this->ceder($origen, $ajeno);
        } catch (RuntimeException) {
        }

        $this->assertSame($cedente->id, $origen->fresh()->client_id);
        $this->assertSame(0, ContractCession::count());
        $this->assertSame(0, \App\Models\ContractComment::where('contract_id', $origen->id)->count());
    }

    // ==================== Lo que viene después ====================

    public function test_la_mora_no_resucita_un_contrato_cedido_de_los_de_antes(): void
    {
        // Las cesiones de antes dejaban el contrato viejo «Cedido». Si el
        // cedente no pagó su cierre, la mora no puede devolverlo a
        // «Suspendido»: parecería de nuevo vivo, con los equipos de otro.
        $origen = $this->contrato($this->cliente(), ContractStatus::Cedido->value);

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
        // Llegar a «Cedido» dispararía la baja definitiva sobre equipos
        // que siguen dando servicio.
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

        $resumen = $this->crecimientoDeSeptiembre();

        $this->assertSame(0, $resumen['altas']);
        $this->assertSame(0, $resumen['bajas']);
    }

    public function test_un_alta_del_mes_sigue_contando_aunque_se_ceda(): void
    {
        // Las cesiones de antes excluían de las altas el contrato que
        // NACÍA de una cesión. Hoy el contrato es el mismo: su alta fue
        // real y no puede desaparecer del informe por cederlo.
        $origen = $this->contrato($this->cliente(), 'Activo', ['activation_date' => '2026-09-05']);
        $this->facturaDelMesPagada($origen);

        $this->ceder($origen, $this->cliente());

        $this->assertSame(1, $this->crecimientoDeSeptiembre()['altas']);
    }

    private function crecimientoDeSeptiembre(): array
    {
        $periodo = \App\Reports\Support\ReportPeriod::fromRequest('2026-09-01', '2026-09-30', 'month');

        return (new \App\Reports\GrowthReport($periodo, $this->branch->id))->resumen();
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

    public function test_desde_la_pantalla_se_emiten_las_facturas_de_cierre(): void
    {
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);

        $this->get(route('contracts.cession.create', $origen))
            ->assertOk()
            ->assertSee('Emitir las facturas de cierre al titular actual');

        $this->post(route('contracts.cession.closing', $origen))
            ->assertSessionHas('success');

        $this->assertSame(
            $cedente->id,
            Invoice::where('contract_id', $origen->id)->where('billed_year_month', '202609')->value('client_id'),
        );
    }

    public function test_se_cede_desde_la_pantalla_y_vuelve_al_mismo_contrato(): void
    {
        $cedente = $this->cliente(['name' => 'Rebeca', 'last_name' => 'Arango']);
        $origen = $this->contrato($cedente);
        $this->facturaDelMesPagada($origen);
        $cesionario = $this->cliente(['name' => 'Jaime', 'last_name' => 'Monsalve']);

        $this->post(route('contracts.cession.store', $origen), [
            'client_id' => $cesionario->id,
            'reason' => 'Cambio de arrendatario',
            'confirmar' => 1,
        ])->assertRedirect(route('contracts.show', $origen));

        $this->assertSame($cesionario->id, $origen->fresh()->client_id);

        // La ficha cuenta la historia.
        $this->get(route('contracts.show', $origen))
            ->assertOk()
            ->assertSee('Contrato cedido')
            ->assertSee('de Rebeca Arango')
            ->assertSee('a Jaime Monsalve');
    }

    public function test_sin_confirmar_no_se_cede(): void
    {
        $cedente = $this->cliente();
        $origen = $this->contrato($cedente);
        $this->facturaDelMesPagada($origen);

        $this->post(route('contracts.cession.store', $origen), [
            'client_id' => $this->cliente()->id,
            'reason' => 'Cambio de arrendatario',
        ])->assertSessionHasErrors('confirmar');

        $this->assertSame($cedente->id, $origen->fresh()->client_id);
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
