<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\NoteType;
use App\Billing\Services\NoteIssuer;
use App\Models\Audit;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\CreditDebitNote;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Notas crédito y débito sobre facturas.
 *
 * Lo esencial: una factura emitida no se modifica; se corrige con una
 * nota que ajusta el saldo y deja constancia del motivo. Aquí se
 * comprueba ese efecto sobre el dinero, que es lo que no puede fallar.
 */
class CreditDebitNoteTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $usuario;
    private Contract $contrato;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        foreach (['notes.index', 'notes.create', 'notes.void', 'notes.pdf'] as $permiso) {
            Permission::firstOrCreate(
                ['name' => $permiso, 'guard_name' => 'web'],
                ['description' => $permiso],
            );
            $this->rol->givePermissionTo($permiso);
        }

        $this->usuario = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $this->usuario->assignRole($this->rol);
        $this->usuario->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        $plan = Plan::create([
            'name' => 'Internet 100 Megas',
            'user_id' => $this->usuario->id,
            'branch_id' => $this->branch->id,
        ]);

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->usuario->id,
        ]);

        $this->contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'status' => 'Activo',
            'user_id' => $this->usuario->id,
        ]);

        $this->actingAs($this->usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    private function factura(float $total = 100000, array $extra = []): Invoice
    {
        return Invoice::create(array_merge([
            'contract_id' => $this->contrato->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->usuario->id,
            'type' => 'Mensualidad',
            'billed_period' => 'Julio 2026',
            'billed_month_name' => 'Julio',
            'billed_year_month' => '202607',
            'issue_date' => now(),
            'due_date' => now()->addDays(15),
            'subtotal' => $total,
            'total' => $total,
            'pending_invoice_amount' => $total,
            'status' => InvoiceStatus::Pendiente->value,
        ], $extra));
    }

    private function emitir(Invoice $factura, array $datos = []): CreditDebitNote
    {
        return app(NoteIssuer::class)->emitir($factura, array_merge([
            'type' => NoteType::Credito->value,
            'concept_code' => '3',
            'reason' => 'Descuento acordado con el cliente por falla del servicio.',
            'subtotal' => 20000,
            'tax' => 0,
        ], $datos));
    }

    // ==================== Efecto sobre el saldo ====================

    public function test_la_nota_credito_disminuye_el_saldo_de_la_factura(): void
    {
        $factura = $this->factura(100000);

        $this->emitir($factura, ['subtotal' => 30000]);

        $factura->refresh();

        $this->assertEquals(70000, (float) $factura->pending_invoice_amount);
        // El total facturado NO se toca: la factura no se modifica
        $this->assertEquals(100000, (float) $factura->total);
    }

    public function test_la_nota_debito_aumenta_el_saldo_de_la_factura(): void
    {
        $factura = $this->factura(100000);

        $this->emitir($factura, [
            'type' => NoteType::Debito->value,
            'concept_code' => '1',
            'subtotal' => 15000,
            'reason' => 'Intereses de mora por pago fuera de plazo.',
        ]);

        $factura->refresh();

        $this->assertEquals(115000, (float) $factura->pending_invoice_amount);
        $this->assertEquals(100000, (float) $factura->total);
    }

    public function test_una_nota_credito_por_el_saldo_total_deja_la_factura_saldada(): void
    {
        $factura = $this->factura(80000);

        $this->emitir($factura, ['subtotal' => 80000, 'concept_code' => '2']);

        $factura->refresh();

        $this->assertEquals(0, (float) $factura->pending_invoice_amount);
        $this->assertSame(InvoiceStatus::Pagada->value, $factura->status);
    }

    public function test_una_nota_debito_reabre_una_factura_ya_pagada(): void
    {
        $factura = $this->factura(50000, [
            'pending_invoice_amount' => 0,
            'status' => InvoiceStatus::Pagada->value,
        ]);

        $this->emitir($factura, [
            'type' => NoteType::Debito->value,
            'concept_code' => '2',
            'subtotal' => 12000,
            'reason' => 'Gastos de reconexión por cobrar al cliente.',
        ]);

        $factura->refresh();

        $this->assertEquals(12000, (float) $factura->pending_invoice_amount);
        $this->assertSame(InvoiceStatus::Pendiente->value, $factura->status);
    }

    public function test_los_impuestos_se_suman_al_total_de_la_nota(): void
    {
        $factura = $this->factura(100000);

        $nota = $this->emitir($factura, ['subtotal' => 10000, 'tax' => 1900]);

        $this->assertEquals(11900, (float) $nota->total);
        $this->assertEquals(88100, (float) $factura->fresh()->pending_invoice_amount);
    }

    // ==================== Validaciones ====================

    public function test_la_nota_credito_no_puede_superar_el_saldo(): void
    {
        $factura = $this->factura(50000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('supera el saldo pendiente');

        $this->emitir($factura, ['subtotal' => 60000]);
    }

    public function test_no_se_emiten_notas_sobre_una_factura_anulada(): void
    {
        $factura = $this->factura(50000, ['status' => InvoiceStatus::Anulada->value]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('anulada');

        $this->emitir($factura);
    }

    public function test_el_concepto_debe_corresponder_al_tipo_de_nota(): void
    {
        $factura = $this->factura();

        // El código 6 solo existe en las notas crédito
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('concepto');

        $this->emitir($factura, ['type' => NoteType::Debito->value, 'concept_code' => '6']);
    }

    public function test_el_valor_debe_ser_mayor_que_cero(): void
    {
        $factura = $this->factura();

        $this->expectException(\RuntimeException::class);

        $this->emitir($factura, ['subtotal' => 0]);
    }

    // ==================== Numeración ====================

    public function test_cada_tipo_de_nota_lleva_su_propia_numeracion(): void
    {
        $factura = $this->factura(200000);

        $credito1 = $this->emitir($factura, ['subtotal' => 1000]);
        $debito1 = $this->emitir($factura, [
            'type' => NoteType::Debito->value,
            'concept_code' => '4',
            'subtotal' => 2000,
            'reason' => 'Ajuste al alza acordado con el cliente.',
        ]);
        $credito2 = $this->emitir($factura, ['subtotal' => 3000]);

        $this->assertSame('NC-1', $credito1->full_number);
        $this->assertSame('ND-1', $debito1->full_number);
        $this->assertSame('NC-2', $credito2->full_number);
    }

    // ==================== Anulación ====================

    public function test_anular_una_nota_revierte_su_efecto(): void
    {
        $factura = $this->factura(100000);

        $nota = $this->emitir($factura, ['subtotal' => 25000]);
        $this->assertEquals(75000, (float) $factura->fresh()->pending_invoice_amount);

        app(NoteIssuer::class)->anular($nota, 'Se emitió por error sobre la factura equivocada.');

        $factura->refresh();
        $nota->refresh();

        $this->assertEquals(100000, (float) $factura->pending_invoice_amount);
        $this->assertSame(CreditDebitNote::ANULADA, $nota->status);
        $this->assertNotNull($nota->voided_at);
        // La nota NO se borra: queda como constancia
        $this->assertDatabaseHas('credit_debit_notes', ['id' => $nota->id]);
    }

    public function test_una_nota_anulada_no_se_puede_anular_dos_veces(): void
    {
        $nota = $this->emitir($this->factura());

        app(NoteIssuer::class)->anular($nota, 'Primera anulación por error de digitación.');

        $this->expectException(\RuntimeException::class);

        app(NoteIssuer::class)->anular($nota->fresh(), 'Segundo intento de anulación.');
    }

    // ==================== Trazabilidad ====================

    public function test_la_emision_queda_en_la_trazabilidad(): void
    {
        $factura = $this->factura();

        $nota = $this->emitir($factura, ['subtotal' => 5000]);

        $registro = Audit::where('action', 'invoices.note_issued')->latest('id')->first();

        $this->assertNotNull($registro, 'La emisión debe quedar registrada.');
        $this->assertStringContainsString($nota->full_number, $registro->description);
        $this->assertSame('facturacion', $registro->category);
        $this->assertSame($this->usuario->id, $registro->user_id);
    }

    public function test_la_anulacion_queda_en_la_trazabilidad(): void
    {
        $nota = $this->emitir($this->factura());

        app(NoteIssuer::class)->anular($nota, 'Anulada por solicitud del área contable.');

        $this->assertDatabaseHas('audits', ['action' => 'invoices.note_voided']);
    }

    // ==================== Pantallas ====================

    public function test_el_formulario_muestra_la_factura_y_sus_conceptos(): void
    {
        $factura = $this->factura();

        $respuesta = $this->get(route('notes.create', ['invoice' => $factura->id]));

        $respuesta->assertOk();
        $respuesta->assertSee($factura->displayNumber());

        // Los dos tipos de nota se ofrecen en el formulario
        $respuesta->assertSee('Nota crédito');
        $respuesta->assertSee('Nota débito');
        $respuesta->assertSee('Concepto (DIAN)');

        // Los conceptos viajan al navegador dentro del JSON que
        // alimenta el desplegable (por eso se busca sin tildes: ahí
        // van escapadas como ó).
        $respuesta->assertSee('Intereses', false);
        $respuesta->assertSee('Gastos por cobrar', false);
    }

    public function test_se_emite_desde_el_formulario(): void
    {
        $factura = $this->factura(60000);

        $respuesta = $this->post(route('notes.store'), [
            'invoice_id' => $factura->id,
            'type' => NoteType::Credito->value,
            'concept_code' => '1',
            'reason' => 'El cliente devolvió el equipo y se le reintegra el valor cobrado.',
            'subtotal' => 20000,
            'tax' => 0,
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');
        $this->assertEquals(40000, (float) $factura->fresh()->pending_invoice_amount);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $factura = $this->factura();

        $respuesta = $this->post(route('notes.store'), [
            'invoice_id' => $factura->id,
            'type' => NoteType::Credito->value,
            'concept_code' => '1',
            'reason' => 'corto',
            'subtotal' => 1000,
        ]);

        $respuesta->assertSessionHasErrors('reason');
        $this->assertSame(0, CreditDebitNote::count());
    }

    public function test_sin_permiso_no_se_pueden_emitir(): void
    {
        $this->rol->revokePermissionTo('notes.create');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $factura = $this->factura();

        $this->get(route('notes.create', ['invoice' => $factura->id]))->assertForbidden();
    }

    public function test_no_se_ven_notas_de_otra_sucursal(): void
    {
        $otraSucursal = Branch::factory()->create();

        $nota = $this->emitir($this->factura());
        $nota->update(['branch_id' => $otraSucursal->id]);

        $this->get(route('notes.show', $nota))->assertForbidden();
    }
}
