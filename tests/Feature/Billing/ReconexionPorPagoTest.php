<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\PaymentRegistrar;
use App\Billing\Services\ServiceReconnection;
use App\Models\AditionalCharge;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\TechnicalOrder;
use App\Services\MikrotikApiService;
use App\Services\OltSshService;
use Mockery;

/**
 * Lo que pasa cuando un contrato cortado se pone al día.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que el cliente vuelva a navegar al pagar, sin esperar a que pase
 *    un técnico: se le habilitan la cuenta PPPoE y la ONT.
 * 2. Que si la red NO responde no se diga que quedó activo: ahí sí va
 *    la orden técnica, con el motivo dentro.
 * 3. Que la reconexión se cobre una sola vez y entre como un renglón
 *    propio de la próxima factura, con el IVA del servicio.
 */
class ReconexionPorPagoTest extends BillingTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ==================== Se devuelve el servicio ====================

    public function test_al_pagar_se_habilitan_la_pppoe_y_la_ont_y_el_contrato_queda_activo(): void
    {
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')
            ->once()
            ->withArgs(fn ($router, $cuenta, $deshabilitar) => $deshabilitar === false));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldReceive('setOntAdminState')
            ->once()
            ->withArgs(fn ($olt, $ont, $habilitar) => $habilitar === true));

        $contrato = $this->cortado();
        $cuenta = $this->cuenta($contrato, deshabilitada: true);
        $ont = $this->ont($contrato, habilitada: false);

        $this->pagarTodo($contrato);

        $this->assertSame('Activo', $contrato->fresh()->status);
        $this->assertFalse((bool) $cuenta->fresh()->disabled);
        $this->assertTrue((bool) $ont->fresh()->admin_enabled);

        // Sin visita: no hay nada que mandar a un técnico.
        $this->assertSame(0, TechnicalOrder::count());
    }

    public function test_si_la_red_no_responde_queda_por_reconexion_con_su_orden(): void
    {
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')
            ->andThrow(new \RuntimeException('El router no responde')));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldReceive('setOntAdminState'));

        $contrato = $this->cortado();
        $this->cuenta($contrato, deshabilitada: true);

        $this->pagarTodo($contrato);

        $this->assertSame('Por Reconexión', $contrato->fresh()->status);

        $orden = TechnicalOrder::firstOrFail();
        $this->assertSame('Reconexión', $orden->detail);
        $this->assertStringContainsString('no responde', $orden->initial_comment);
    }

    public function test_sin_equipos_vinculados_se_manda_al_tecnico(): void
    {
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldNotReceive('setPppSecretState'));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState'));

        $contrato = $this->cortado();

        $this->pagarTodo($contrato);

        $this->assertSame('Por Reconexión', $contrato->fresh()->status);
        $this->assertSame(1, TechnicalOrder::count());
    }

    // ==================== Se cobra la reconexión ====================

    public function test_se_cobra_la_reconexion_al_precio_de_la_sucursal(): void
    {
        $this->sinRed();
        $this->branch->update(['reconnection_price' => 15000]);

        $contrato = $this->cortado(19);
        $this->pagarTodo($contrato);

        $cargo = AditionalCharge::where('contract_id', $contrato->id)->firstOrFail();

        $this->assertSame(ServiceReconnection::DESCRIPCION, $cargo->description);
        $this->assertEquals(15000, $cargo->amount);
        // El IVA del servicio principal: la reconexión de un servicio
        // gravado se cobra gravada, y la de uno excluido, excluida.
        $this->assertEquals(19, $cargo->tax_percentage);
        $this->assertSame('pendiente', $cargo->status);
    }

    public function test_el_cargo_entra_en_la_proxima_factura_como_un_renglon_propio(): void
    {
        $this->sinRed();
        $this->branch->update(['reconnection_price' => 15000]);

        $contrato = $this->cortado(19);
        $this->pagarTodo($contrato);

        $siguiente = app(InvoiceGenerator::class)
            ->generateForContract($contrato->fresh(), now()->addMonthNoOverflow(), $this->admin->id);

        $renglon = $siguiente['invoice']->invoice_items()
            ->where('description', ServiceReconnection::DESCRIPCION)
            ->firstOrFail();

        $this->assertEquals(15000, $renglon->unit_price);
        $this->assertEquals(2850, $renglon->tax);
        $this->assertSame('gravado', $renglon->tax_classification);
    }

    public function test_no_se_cobra_dos_veces_la_misma_reconexion(): void
    {
        $this->sinRed();
        $this->branch->update(['reconnection_price' => 15000]);

        $contrato = $this->cortado();

        // Dos facturas vencidas: al pagarlas, el contrato pasa por
        // «al día» dos veces, pero la reconexión es una sola.
        $this->factura($contrato, '202607');
        $this->pagarTodo($contrato);

        $this->assertSame(1, AditionalCharge::where('contract_id', $contrato->id)->count());
    }

    public function test_sin_precio_en_la_sucursal_no_se_cobra_nada(): void
    {
        $this->sinRed();
        $this->branch->update(['reconnection_price' => 0]);

        $contrato = $this->cortado();
        $this->pagarTodo($contrato);

        $this->assertSame(0, AditionalCharge::where('contract_id', $contrato->id)->count());
    }

    public function test_un_contrato_en_presuspension_no_paga_reconexion(): void
    {
        $this->sinRed();
        $this->branch->update(['reconnection_price' => 15000]);

        // Avisado pero NO cortado: sigue navegando, así que no hay
        // reconexión que cobrar.
        $contrato = $this->cortado();
        $contrato->update(['status' => 'Pre-suspensión']);

        $this->pagarTodo($contrato);

        $this->assertSame('Activo', $contrato->fresh()->status);
        $this->assertSame(0, AditionalCharge::where('contract_id', $contrato->id)->count());
        $this->assertSame(0, TechnicalOrder::count());
    }

    // ==================== Apoyo ====================

    /** Ni el router ni la OLT reciben nada en las pruebas de cobro. */
    private function sinRed(): void
    {
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldNotReceive('setPppSecretState'));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState'));
    }

    private function cortado(float $iva = 0): Contract
    {
        $contrato = $this->createBillableContract(100000, $iva);
        $contrato->update(['status' => 'Suspendido', 'overdue_invoices_count' => 2]);

        return $contrato;
    }

    private function factura(Contract $contrato, string $periodo): Invoice
    {
        return Invoice::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'issue_date' => now()->subMonths(2)->toDateString(),
            'due_date' => now()->subMonth()->toDateString(),
            'billed_year_month' => $periodo,
            'subtotal' => 100000, 'discount' => 0, 'tax' => 0, 'total' => 100000,
            'pending_invoice_amount' => 100000,
            'status' => InvoiceStatus::Vencida->value,
        ]);
    }

    /** Paga todas las facturas abiertas del contrato, como la caja. */
    private function pagarTodo(Contract $contrato): void
    {
        $this->openCashRegister();

        if (!Invoice::where('contract_id', $contrato->id)->exists()) {
            $this->factura($contrato, '202608');
        }

        foreach (Invoice::where('contract_id', $contrato->id)->get() as $factura) {
            app(PaymentRegistrar::class)->register([
                'invoice_id' => $factura->id,
                'amount' => $factura->pending_invoice_amount,
                'payment_method' => 'Efectivo',
                'date' => now()->toDateString(),
            ], $this->admin->id, $this->branch->id);
        }
    }

    private function cuenta(Contract $contrato, bool $deshabilitada): PppoeAccount
    {
        $router = Router::create([
            'branch_id' => $this->branch->id,
            'name' => 'Router de prueba',
            'ip_address' => '10.0.0.1',
            'username' => 'admin',
            'password' => 'secret',
            'api_port' => 8728,
        ]);

        return PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $router->id,
            'contract_id' => $contrato->id,
            'username' => 'cliente01',
            'password' => 'clave',
            'profile' => 'PLAN-100M',
            'mikrotik_id' => '*1',
            'disabled' => $deshabilitada,
        ]);
    }

    private function ont(Contract $contrato, bool $habilitada): Ont
    {
        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'name' => 'OLT de prueba',
            'ip_address' => '10.0.0.10',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'root', 'password' => 'admin',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        return Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $olt->id,
            'contract_id' => $contrato->id,
            'slot' => '1', 'port' => '2', 'onu_id' => 7,
            'sn' => 'HWTC12345678',
            'admin_enabled' => $habilitada,
        ]);
    }
}
