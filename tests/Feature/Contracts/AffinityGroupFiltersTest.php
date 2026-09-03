<?php

namespace Tests\Feature\Contracts;

use App\Billing\Enums\InvoiceStatus;
use App\Models\AffinityGroup;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El grupo de afinidad en los documentos que salen del contrato.
 *
 * QUÉ SE AÑADIÓ Y POR QUÉ SOLO ESTO
 * ---------------------------------
 * Facturas, pagos y notas heredan la clasificación del contrato, y en
 * ninguno de los tres el grupo era columna. Sin él no hay forma de
 * contestar «cuáles son del grupo corporativo» salvo abriendo los
 * contratos uno a uno. Eso es información nueva.
 *
 * NO se añadió un filtro de sucursal a cada listado. La sucursal ya es
 * columna, y estos listados son DataTables del lado del cliente: su
 * buscador propio ya filtra por ella. Un desplegable aparte sería la
 * misma pregunta por otro camino, y dos filtros que dicen lo mismo se
 * acaban contradiciendo.
 *
 * La excepción es facturas, que crece sin límite: ahí el filtro de
 * sucursal sí aporta, porque reduce lo que se CARGA y no solo lo que
 * se muestra.
 *
 * LA REGLA QUE NO SE CRUZA
 * ------------------------
 * Los filtros se aplican **además** del alcance, nunca en su lugar.
 * Pedir por URL un grupo o una sucursal que no corresponden cruza las
 * dos condiciones y da **cero resultados** — no abre nada, y tampoco
 * da 403, que confirmaría que existen.
 */
class AffinityGroupFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;
    private Company $empresa;
    private Branch $sucursal;
    private Plan $plan;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        foreach (['notes.index', 'notes.create', 'notes.void', 'notes.pdf'] as $permiso) {
            Permission::firstOrCreate(
                ['name' => $permiso, 'guard_name' => 'web'],
                ['description' => $permiso],
            );
            $this->rol->givePermissionTo($permiso);
        }

        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $this->usuario = User::factory()->create();
        $this->usuario->assignRole($this->rol);
        $this->usuario->branches()->attach($this->sucursal->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer(
            $this->empresa->id,
            [$this->sucursal->id],
            $this->sucursal->id,
        );

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
        ]);
    }

    private function contrato(?AffinityGroup $grupo): Contract
    {
        $cliente = Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'affinity_group_id' => $grupo?->id,
            'status' => 'Activo',
            'user_id' => $this->usuario->id,
        ]);
    }

    /**
     * Una factura pendiente del contrato.
     *
     * A mano y no con factory porque `Invoice` no tiene: en este
     * proyecto las facturas se crean por la corrida de facturacion, y
     * las pruebas de facturacion las arman asi.
     */
    private function factura(Contract $contrato, ?Branch $sucursal = null): Invoice
    {
        return Invoice::create([
            'contract_id' => $contrato->id,
            'branch_id' => ($sucursal ?? $this->sucursal)->id,
            'user_id' => $this->usuario->id,
            'type' => 'Mensualidad',
            'billed_period' => 'Julio 2026',
            'billed_month_name' => 'Julio',
            'billed_year_month' => '202607',
            'issue_date' => now(),
            'due_date' => now()->addDays(15),
            'subtotal' => 50000,
            'total' => 50000,
            'pending_invoice_amount' => 50000,
            'status' => InvoiceStatus::Pendiente->value,
        ]);
    }

    /** Un pago, con factura o sin ella (los anticipos no la tienen). */
    private function pago(?Invoice $factura, ?Contract $contrato = null): Payment
    {
        return Payment::create([
            'invoice_id' => $factura?->id,
            'contract_id' => $contrato?->id,
            'type' => $factura ? 'Factura' : 'Anticipo',
            'user_id' => $this->usuario->id,
            'payment_date' => now(),
            'amount' => 10000,
            'payment_method' => 'Efectivo',
            'status' => 'Completado',
        ]);
    }

    // ==================== Facturas ====================

    public function test_el_listado_de_facturas_dice_el_grupo(): void
    {
        $grupo = AffinityGroup::factory()->create([
            'company_id' => $this->empresa->id,
            'code' => 'CORP',
            'name' => 'Corporativo',
        ]);

        $this->factura($this->contrato($grupo));

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('CORP — Corporativo', escape: false);
    }

    public function test_las_facturas_se_filtran_por_grupo(): void
    {
        $corporativo = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);
        $general = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);

        $deCorporativo = $this->factura($this->contrato($corporativo));
        $deGeneral = $this->factura($this->contrato($general));

        $respuesta = $this->get(route('invoices.index', [
            'affinity_group_id' => [$corporativo->id],
        ]))->assertOk();

        $ids = $respuesta->viewData('invoices')->pluck('id');

        $this->assertTrue($ids->contains($deCorporativo->id));
        $this->assertFalse($ids->contains($deGeneral->id));
    }

    public function test_pedir_un_grupo_de_otra_empresa_no_cuela_nada(): void
    {
        // El filtro se cruza con el alcance, no lo sustituye.
        $propio = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);
        $ajeno = AffinityGroup::factory()->create();

        $this->factura($this->contrato($propio));

        $respuesta = $this->get(route('invoices.index', [
            'affinity_group_id' => [$ajeno->id],
        ]))->assertOk();

        $this->assertCount(0, $respuesta->viewData('invoices'));
    }

    public function test_las_facturas_tienen_buscador_de_grupo(): void
    {
        AffinityGroup::factory()->create([
            'company_id' => $this->empresa->id,
            'name' => 'Corporativo',
        ]);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('name="affinity_group_id[]"', escape: false)
            ->assertSee('Corporativo', escape: false);
    }

    public function test_sin_grupos_el_buscador_de_facturas_no_estorba(): void
    {
        // Una empresa que todavía no ha creado ninguno no gana nada con
        // un desplegable vacío.
        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertDontSee('name="affinity_group_id[]"', escape: false);
    }

    // ==================== Pagos ====================

    public function test_el_listado_de_pagos_dice_el_grupo(): void
    {
        $grupo = AffinityGroup::factory()->create([
            'company_id' => $this->empresa->id,
            'code' => 'CORP',
            'name' => 'Corporativo',
        ]);

        $contrato = $this->contrato($grupo);

        $this->pago($this->factura($contrato));

        $this->get(route('payments.index'))
            ->assertOk()
            ->assertSee('CORP — Corporativo', escape: false);
    }

    public function test_los_pagos_se_filtran_por_grupo(): void
    {
        $corporativo = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);
        $general = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);

        $deCorporativo = $this->pago($this->factura($this->contrato($corporativo)));
        $deGeneral = $this->pago($this->factura($this->contrato($general)));

        $respuesta = $this->get(route('payments.index', [
            'affinity_group_id' => [$corporativo->id],
        ]))->assertOk();

        $ids = $respuesta->viewData('payments')->pluck('id');

        $this->assertTrue($ids->contains($deCorporativo->id));
        $this->assertFalse($ids->contains($deGeneral->id));
    }

    public function test_el_anticipo_tambien_se_filtra_por_su_grupo(): void
    {
        // Los anticipos no tienen factura: cuelgan del contrato
        // directamente. Es el mismo motivo por el que el filtro mira
        // los dos caminos, y si solo mirara la factura estos pagos
        // desaparecerían al filtrar.
        $corporativo = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);

        $anticipo = $this->pago(null, $this->contrato($corporativo));

        $respuesta = $this->get(route('payments.index', [
            'affinity_group_id' => [$corporativo->id],
        ]))->assertOk();

        $this->assertTrue($respuesta->viewData('payments')->pluck('id')->contains($anticipo->id));
    }

    // ==================== La sucursal, solo donde aporta ====================

    public function test_las_facturas_se_pueden_filtrar_por_sucursal_en_consolidado(): void
    {
        // Las facturas crecen sin limite: aqui el filtro reduce lo que
        // se carga, no solo lo que se muestra.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Norte']);
        $sur = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Sur']);

        $this->usuario->branches()->detach();
        $this->usuario->branches()->attach([$norte->id, $sur->id], ['role_id' => $this->rol->id]);

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => null,
            'branch_ids' => [$norte->id, $sur->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer($empresa->id, [$norte->id, $sur->id], null);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('name="branch_id[]"', escape: false)
            ->assertSee('Sede Norte', escape: false);
    }

    public function test_en_una_sola_sede_las_facturas_no_ofrecen_ese_filtro(): void
    {
        AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertDontSee('name="branch_id[]"', escape: false);
    }

    public function test_los_pagos_no_duplican_el_filtro_de_sucursal(): void
    {
        // La sucursal ya es columna y esta tabla busca del lado del
        // cliente: su propio buscador ya filtra por ella.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->usuario->branches()->detach();
        $this->usuario->branches()->attach([$norte->id, $sur->id], ['role_id' => $this->rol->id]);

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => null,
            'branch_ids' => [$norte->id, $sur->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer($empresa->id, [$norte->id, $sur->id], null);

        $this->get(route('payments.index'))
            ->assertOk()
            ->assertDontSee('name="branch_id[]"', escape: false);
    }
}
