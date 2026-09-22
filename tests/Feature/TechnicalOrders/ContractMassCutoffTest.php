<?php

namespace Tests\Feature\TechnicalOrders;

use App\Billing\Enums\InvoiceStatus;
use App\Jobs\CortarContratoPorMora;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractCutoff;
use App\Models\ContractCutoffItem;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Services\ContractMassCutoff;
use App\Services\MikrotikApiService;
use App\Services\OltSshService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cortes masivos por mora.
 *
 * LO QUE SE DEFIENDE, EN ORDEN DE GRAVEDAD
 * ----------------------------------------
 * 1. Solo se corta a quien debe lo que exige la sucursal (dos facturas
 *    vencidas por defecto), y se vuelve a mirar al llegar su turno:
 *    cortarle a quien acaba de pagar es lo peor que puede hacer esto.
 * 2. Revisar no toca nada.
 * 3. Se corta en los dos sitios: el contrato queda suspendido con su
 *    orden administrativa, y la cuenta PPPoE y la ONT deshabilitadas.
 * 4. Si un equipo no responde, se dice: el contrato no puede parecer
 *    cortado mientras sigue navegando.
 */
class ContractMassCutoffTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Plan $plan;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-22 10:00:00');
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        // Sale de `permissions:sync`, no del seeder.
        \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'technicals_orders.cutoff', 'guard_name' => 'web'],
            ['description' => \App\Support\PermissionLabels::describe('technicals_orders.cutoff')],
        );
        $rol->givePermissionTo('technicals_orders.cutoff');

        $this->admin = User::factory()->create();
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->plan = Plan::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ==================== 1. Solo a quien debe ====================

    public function test_revisar_no_corta_nada_y_dice_que_pasaria_con_cada_uno(): void
    {
        $this->sinRed();

        $this->deber($this->conEquipos($this->contrato('C-001')), 2);
        $this->deber($this->contrato('C-002'), 1);
        $this->deber($this->contrato('C-003', 'Retirado'), 3);
        $this->deber($this->contrato('C-004', 'Suspendido'), 3);
        // Otra sucursal de la MISMA empresa: una de otra empresa ni se ve.
        $this->contrato('C-999', 'Activo', Branch::factory()->create(['company_id' => $this->branch->company_id])->id);

        $this->post(route('technicals_orders.cutoffs.preview'), [
            'lista' => "C-001\nC-002\nC-003\nC-004\nNO-EXISTE\nC-999",
        ])
            ->assertOk()
            ->assertSeeInOrder(['C-001', 'Se corta'])
            ->assertSee('se corta con 2')
            ->assertSee('ya terminó')
            ->assertSee('Ya está «Suspendido»')
            ->assertSee('No hay ningún contrato con ese número')
            ->assertSee('otra sucursal');

        $this->assertSame('Activo', Contract::where('contract_number', 'C-001')->value('status'));
        $this->assertSame(0, ContractCutoff::count());
        $this->assertSame(0, TechnicalOrder::count());
    }

    public function test_no_se_corta_a_quien_no_debe_dos_meses(): void
    {
        $this->sinRed();

        $debe = $this->deber($this->contrato('C-001'), 2);
        $alDia = $this->deber($this->contrato('C-002'), 1);

        $this->cortar(['C-001', 'C-002']);

        $this->assertSame('Suspendido', $debe->fresh()->status);
        $this->assertSame('Activo', $alDia->fresh()->status);

        $omitido = ContractCutoffItem::where('contract_number', 'C-002')->firstOrFail();
        $this->assertSame(ContractCutoffItem::OMITIDO, $omitido->status);
        $this->assertStringContainsString('se corta con 2', $omitido->message);
    }

    public function test_una_factura_que_todavia_no_vence_no_cuenta(): void
    {
        $contrato = $this->deber($this->contrato('C-001'), 1);

        Invoice::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'billed_year_month' => '202609',
            'subtotal' => 80000, 'discount' => 0, 'tax' => 0, 'total' => 80000,
            'pending_invoice_amount' => 80000,
            'status' => InvoiceStatus::Pendiente->value,
        ]);

        $fila = app(ContractMassCutoff::class)->resolver(['C-001'], $this->branch->id)[0];

        $this->assertSame('sin_mora', $fila['estado']);
        $this->assertSame(1, $fila['vencidas']);
    }

    public function test_si_paga_antes_de_su_turno_no_se_corta(): void
    {
        $this->sinRed();
        Queue::fake();

        $contrato = $this->deber($this->contrato('C-001'), 2);
        $tanda = app(ContractMassCutoff::class)
            ->crearTanda(['C-001'], $this->branch->id, $this->admin->id, 'Cartera de septiembre', 'Lista manual');

        Queue::assertPushed(CortarContratoPorMora::class, 1);

        // Paga mientras espera en la cola.
        Invoice::where('contract_id', $contrato->id)
            ->update(['pending_invoice_amount' => 0, 'status' => InvoiceStatus::Pagada->value]);

        $item = $tanda->items()->firstOrFail();
        (new CortarContratoPorMora($item->id))->handle(app(ContractMassCutoff::class));

        $this->assertSame('Activo', $contrato->fresh()->status);
        $this->assertSame(ContractCutoffItem::OMITIDO, $item->fresh()->status);
        $this->assertStringContainsString('Al llegar su turno', $item->fresh()->message);
    }

    // ==================== 2 y 3. En el sistema y en la red ====================

    public function test_corta_en_el_sistema_y_en_la_red(): void
    {
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')
            ->once()
            ->withArgs(fn ($router, $cuenta, $deshabilitar) => $deshabilitar === true));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldReceive('setOntAdminState')
            ->once()
            ->withArgs(fn ($olt, $ont, $habilitar) => $habilitar === false));

        $contrato = $this->deber($this->conEquipos($this->contrato('C-001')), 2);

        $respuesta = $this->cortar(['C-001']);

        $tanda = ContractCutoff::firstOrFail();
        $respuesta->assertRedirect(route('technicals_orders.cutoffs.show', $tanda));

        $this->assertSame('Suspendido', $contrato->fresh()->status);
        $this->assertTrue(PppoeAccount::where('contract_id', $contrato->id)->value('disabled'));
        $this->assertFalse(Ont::where('contract_id', $contrato->id)->value('admin_enabled'));

        // Queda la orden administrativa en el historial del contrato.
        $item = $tanda->items()->firstOrFail();
        $this->assertSame(ContractCutoffItem::CORTADO, $item->status);

        $orden = TechnicalOrder::findOrFail($item->technical_order_id);
        $this->assertSame(TechnicalOrder::ADMINISTRATIVA, $orden->type);
        $this->assertSame(ContractMassCutoff::DETALLE, $orden->detail);
        $this->assertSame('Cerrada', $orden->status);
        $this->assertStringContainsString('Cartera de septiembre', $orden->initial_comment);
    }

    public function test_un_suspendido_que_sigue_navegando_se_corta_en_la_red(): void
    {
        // La mora de la madrugada solo cambia el estado: el contrato
        // figura suspendido pero su cuenta sigue habilitada.
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')->once());
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState'));

        $contrato = $this->contrato('C-001', 'Suspendido');
        $this->cuenta($contrato);
        $this->deber($contrato, 2);

        $this->cortar(['C-001']);

        $this->assertTrue(PppoeAccount::where('contract_id', $contrato->id)->value('disabled'));
        $this->assertSame(ContractCutoffItem::CORTADO, ContractCutoffItem::firstOrFail()->status);
    }

    public function test_si_la_olt_no_responde_queda_incompleto(): void
    {
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldReceive('setPppSecretState')->once());
        $this->mock(OltSshService::class, fn ($m) => $m->shouldReceive('setOntAdminState')
            ->andThrow(new \RuntimeException('Tiempo de espera agotado')));

        $contrato = $this->deber($this->conEquipos($this->contrato('C-001')), 2);

        $this->cortar(['C-001']);

        $item = ContractCutoffItem::firstOrFail();
        $this->assertSame(ContractCutoffItem::INCOMPLETO, $item->status);
        $this->assertStringContainsString('PENDIENTE', $item->message);
        $this->assertTrue(Ont::where('contract_id', $contrato->id)->value('admin_enabled'));

        $this->get(route('technicals_orders.cutoffs.show', $item->contract_cutoff_id))
            ->assertOk()
            ->assertSee('siguen con servicio');
    }

    public function test_la_tanda_guarda_toda_la_lista(): void
    {
        $this->sinRed();

        $this->deber($this->contrato('C-001'), 2);
        $this->contrato('C-002');

        $this->cortar(['C-001', 'C-002', 'NO-EXISTE']);

        $tanda = ContractCutoff::firstOrFail();

        $this->assertSame(3, $tanda->items()->count());
        $this->assertSame('Lista manual', $tanda->source);
        $this->assertSame(2, $tanda->threshold);
        $this->assertSame('No hay ningún contrato con ese número.',
            $tanda->items()->where('contract_number', 'NO-EXISTE')->value('message'));

        $this->get(route('technicals_orders.cutoffs.show', $tanda))
            ->assertOk()
            ->assertSee('Cortado')
            ->assertSee('No se cortó');
    }

    // ==================== La entrada ====================

    public function test_se_lee_un_archivo_con_encabezado(): void
    {
        $this->sinRed();
        $this->deber($this->contrato('C-001'), 2);

        $archivo = UploadedFile::fake()->createWithContent('cartera.csv', "Contrato,Nombre\nC-001,Pepe\n");

        $this->post(route('technicals_orders.cutoffs.preview'), ['archivo' => $archivo])
            ->assertOk()
            ->assertSee('Archivo cartera.csv')
            ->assertSee('Cortar 1 contrato(s)');
    }

    public function test_sin_motivo_no_se_corta(): void
    {
        $this->sinRed();
        $contrato = $this->deber($this->contrato('C-001'), 2);

        $this->post(route('technicals_orders.cutoffs.store'), [
            'numeros' => ['C-001'],
            'origen' => 'Lista manual',
            'confirmar' => 1,
        ])->assertSessionHasErrors('reason');

        $this->assertSame('Activo', $contrato->fresh()->status);
    }

    public function test_sin_permiso_no_se_entra(): void
    {
        $rol = Role::where('name', 'tecnico')->firstOrFail();
        $tecnico = User::factory()->create();
        $tecnico->assignRole($rol);
        $tecnico->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($tecnico)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('technicals_orders.cutoffs'))->assertForbidden();
    }

    public function test_la_pantalla_se_ve(): void
    {
        $this->get(route('technicals_orders.cutoffs'))
            ->assertOk()
            ->assertSee('Cortes masivos por mora')
            ->assertSee('2 o más facturas vencidas');
    }

    // ==================== Los reportes ====================

    public function test_una_tanda_se_descarga_en_excel_con_toda_la_lista(): void
    {
        $this->sinRed();
        \Maatwebsite\Excel\Facades\Excel::fake();

        $this->deber($this->contrato('C-001'), 2);
        $this->cortar(['C-001', 'NO-EXISTE']);
        $tanda = ContractCutoff::firstOrFail();

        $this->get(route('technicals_orders.cutoffs.excel', $tanda))->assertOk();

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded(
            "corte-masivo-{$tanda->id}.xlsx",
            fn (\App\Exports\ContractCutoffExport $e) => $e->query()->count() === 2
                && $e->map($e->query()->first())[9] === 'Cortado',
        );
    }

    public function test_el_historial_se_filtra_y_se_exporta_por_fechas(): void
    {
        $this->sinRed();
        \Maatwebsite\Excel\Facades\Excel::fake();

        $this->deber($this->contrato('C-001'), 2);
        $this->cortar(['C-001']);

        // Una tanda de agosto: no entra en septiembre.
        $vieja = ContractCutoff::create([
            'branch_id' => $this->branch->id, 'user_id' => $this->admin->id,
            'reason' => 'Cartera de agosto', 'source' => 'Lista manual', 'threshold' => 2,
        ]);
        $vieja->forceFill(['created_at' => '2026-08-10 09:00:00'])->save();
        $vieja->items()->create(['contract_number' => 'C-777', 'status' => ContractCutoffItem::OMITIDO]);

        $this->get(route('technicals_orders.cutoffs', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))
            ->assertOk()
            ->assertSee('Cartera de septiembre')
            ->assertDontSee('Cartera de agosto');

        $this->get(route('technicals_orders.cutoffs.export', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))->assertOk();

        \Maatwebsite\Excel\Facades\Excel::assertDownloaded(
            'cortes-masivos-2026-09-01-a-2026-09-30.xlsx',
            fn (\App\Exports\ContractCutoffExport $e) => $e->query()->pluck('contract_number')->all() === ['C-001'],
        );
    }

    public function test_una_tanda_se_descarga_en_pdf(): void
    {
        $this->sinRed();

        $this->deber($this->contrato('C-001'), 2);
        $this->cortar(['C-001']);

        $respuesta = $this->get(route('technicals_orders.cutoffs.pdf', ContractCutoff::firstOrFail()));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
    }

    // ==================== Apoyo ====================

    private function cortar(array $numeros)
    {
        return $this->post(route('technicals_orders.cutoffs.store'), [
            'numeros' => $numeros,
            'reason' => 'Cartera de septiembre',
            'origen' => 'Lista manual',
            'confirmar' => 1,
        ]);
    }

    /** Ni el router ni la OLT pueden recibir nada. */
    private function sinRed(): void
    {
        $this->mock(MikrotikApiService::class, fn ($m) => $m->shouldNotReceive('setPppSecretState'));
        $this->mock(OltSshService::class, fn ($m) => $m->shouldNotReceive('setOntAdminState'));
    }

    private function contrato(string $numero, string $estado = 'Activo', ?int $branchId = null): Contract
    {
        $datos = ['branch_id' => $branchId ?? $this->branch->id, 'user_id' => $this->admin->id];

        return Contract::factory()->create($datos + [
            'client_id' => Client::factory()->create($datos)->id,
            'plan_id' => $this->plan->id,
            'status' => $estado,
            'contract_number' => $numero,
        ]);
    }

    /** $meses facturas vencidas y sin pagar. */
    private function deber(Contract $contrato, int $meses): Contract
    {
        foreach (range(1, $meses) as $i) {
            $mes = Carbon::parse('2026-09-01')->subMonths($i);

            Invoice::create([
                'contract_id' => $contrato->id,
                'branch_id' => $contrato->branch_id,
                'issue_date' => $mes->toDateString(),
                'due_date' => $mes->copy()->addDays(20)->toDateString(),
                'billed_year_month' => $mes->format('Ym'),
                'subtotal' => 80000, 'discount' => 0, 'tax' => 0, 'total' => 80000,
                'pending_invoice_amount' => 80000,
                'status' => InvoiceStatus::Vencida->value,
            ]);
        }

        return $contrato;
    }

    private function conEquipos(Contract $contrato): Contract
    {
        $this->cuenta($contrato);

        $olt = Olt::create([
            'branch_id' => $this->branch->id,
            'name' => 'OLT ' . $contrato->contract_number,
            'ip_address' => '10.0.1.' . (++$this->secuencia),
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'root', 'password' => 'admin',
            'brand' => 'huawei', 'uptime' => '0',
        ]);

        Ont::create([
            'branch_id' => $this->branch->id,
            'olt_id' => $olt->id,
            'contract_id' => $contrato->id,
            'slot' => '1', 'port' => '2', 'onu_id' => $this->secuencia,
            'sn' => 'HWTC' . str_pad((string) $this->secuencia, 8, '0', STR_PAD_LEFT),
            'admin_enabled' => true,
        ]);

        return $contrato;
    }

    private function cuenta(Contract $contrato): PppoeAccount
    {
        $router = Router::create([
            'branch_id' => $this->branch->id,
            'name' => 'Router ' . (++$this->secuencia),
            'ip_address' => '10.0.0.' . $this->secuencia,
            'username' => 'admin',
            'password' => 'secret',
            'api_port' => 8728,
        ]);

        return PppoeAccount::create([
            'branch_id' => $this->branch->id,
            'router_id' => $router->id,
            'contract_id' => $contrato->id,
            'username' => 'cliente' . $this->secuencia,
            'password' => 'clave',
            'profile' => 'PLAN-100M',
            'mikrotik_id' => '*' . $this->secuencia,
            'disabled' => false,
        ]);
    }
}
