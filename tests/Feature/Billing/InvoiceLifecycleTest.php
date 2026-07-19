<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\InvoiceType;
use App\Billing\Events\InvoiceIssued;
use App\Models\Audit;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\InvoiceNumberingSequence;
use Illuminate\Support\Facades\Event;

/**
 * Ciclo de vida de la factura (fase 4): numeración formal por
 * secuencia de sucursal, anulación con reglas, auditoría
 * automática y eventos de dominio.
 */
class InvoiceLifecycleTest extends BillingTestCase
{
    public function test_las_facturas_reciben_numeracion_consecutiva_por_sucursal(): void
    {
        $contractA = $this->createBillableContract();
        $contractB = $this->createBillableContract();

        $this->post(route('invoices.generate'));

        $invoiceA = Invoice::where('contract_id', $contractA->id)->firstOrFail();
        $invoiceB = Invoice::where('contract_id', $contractB->id)->firstOrFail();

        $prefix = 'FAC' . $this->branch->id;

        $this->assertSame($prefix, $invoiceA->prefix);
        $this->assertSame(InvoiceType::Mensualidad->value, $invoiceA->type);

        // Consecutivos 1 y 2, en algún orden de generación
        $numbers = collect([$invoiceA->number, $invoiceB->number])->sort()->values()->all();
        $this->assertSame([1, 2], $numbers);
        $this->assertSame($prefix . '-' . $invoiceA->number, $invoiceA->full_number);

        // La secuencia quedó en el último consecutivo emitido
        $sequence = InvoiceNumberingSequence::where('branch_id', $this->branch->id)->firstOrFail();
        $this->assertSame(2, $sequence->current_number);
    }

    public function test_la_secuencia_respeta_el_rango_autorizado(): void
    {
        // Secuencia con rango casi agotado (solo queda el número 5)
        InvoiceNumberingSequence::create([
            'branch_id' => $this->branch->id,
            'prefix' => 'FV',
            'resolution_number' => 'RES-001',
            'range_start' => 1,
            'range_end' => 5,
            'current_number' => 4,
            'active' => true,
        ]);

        $contractA = $this->createBillableContract();
        $contractB = $this->createBillableContract();

        $this->post(route('invoices.generate'));

        // Solo una factura alcanzó número dentro del rango; la otra
        // corrida falló y quedó como omitida (log), sin factura
        $issued = Invoice::whereIn('contract_id', [$contractA->id, $contractB->id])->get();

        $this->assertCount(1, $issued);
        $this->assertSame('FV-5', $issued->first()->full_number);
    }

    public function test_anula_una_factura_con_motivo_y_auditoria(): void
    {
        $contract = $this->createBillableContract();
        $this->post(route('invoices.generate'));
        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->post(route('invoices.void', $invoice), [
            'void_reason' => 'Error en el valor facturado',
        ])->assertRedirect(route('invoices.index'));

        $invoice->refresh();

        $this->assertSame(InvoiceStatus::Anulada->value, $invoice->status);
        $this->assertSame('Error en el valor facturado', $invoice->void_reason);
        $this->assertSame($this->admin->id, $invoice->voided_by);
        $this->assertNotNull($invoice->voided_at);
        $this->assertEqualsWithDelta(0, (float) $invoice->pending_invoice_amount, 0.01);

        // El trait Auditable registró el cambio con antes/después
        $audit = Audit::where('auditable_type', Invoice::class)
            ->where('auditable_id', $invoice->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'No se registró la auditoría de la anulación');
        $this->assertSame(InvoiceStatus::Anulada->value, $audit->new_values['status']);
        $this->assertSame($this->admin->id, $audit->user_id);
    }

    public function test_no_anula_facturas_con_pagos_registrados(): void
    {
        $contract = $this->createBillableContract(price: 100000);
        $this->post(route('invoices.generate'));
        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        // Abono parcial (con caja abierta, requisito de todo cobro)
        $this->openCashRegister();
        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 30000,
            'payment_method' => 'transferencia',
        ])->assertOk();

        $this->post(route('invoices.void', $invoice), [
            'void_reason' => 'Intento inválido',
        ]);

        $invoice->refresh();
        $this->assertNotSame(InvoiceStatus::Anulada->value, $invoice->status);
    }

    public function test_una_factura_anulada_no_admite_pagos(): void
    {
        $contract = $this->createBillableContract(price: 100000);
        $this->post(route('invoices.generate'));
        $invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();

        $this->post(route('invoices.void', $invoice), ['void_reason' => 'Anulada para prueba']);
        $this->assertSame(InvoiceStatus::Anulada->value, $invoice->fresh()->status);

        $this->postJson(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
            'payment_method' => 'transferencia',
        ])->assertStatus(422);

        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_emitir_una_factura_dispara_el_evento(): void
    {
        Event::fake([InvoiceIssued::class]);

        $contract = $this->createBillableContract();
        $this->post(route('invoices.generate'));

        Event::assertDispatched(InvoiceIssued::class, function (InvoiceIssued $event) use ($contract) {
            return $event->invoice->contract_id === $contract->id;
        });
    }
}
