<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\InvoiceStatus;
use App\Models\Invoice;

/**
 * Buscador de facturas para cobro (Cobranza → Cobrar): criterios
 * amplios — identificación, nombre, contrato, número de factura,
 * teléfono, usuario PPPoE — y el modo "todos los campos".
 */
class PaymentSearchTest extends BillingTestCase
{
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        // Contrato con datos conocidos para buscar por cada criterio
        $contract = $this->createBillableContract(price: 100000);
        $contract->client->update([
            'identity_number' => '1042770586',
            'name' => 'Duban',
            'last_name' => 'Restrepo',
            'number_phone' => '3126143902',
        ]);
        $contract->update(['user_pppoe' => 'duban.restrepo']);

        $this->post(route('invoices.generate'));
        $this->invoice = Invoice::where('contract_id', $contract->id)->firstOrFail();
    }

    /**
     * Ejecuta la búsqueda GET y verifica que la factura aparezca.
     */
    private function assertFinds(string $term, string $field = 'all'): void
    {
        $response = $this->get(route('payments.searchView', [
            'search_term' => $term,
            'search_field' => $field,
        ]))->assertOk();

        $found = collect($response->viewData('invoices')->items())
            ->contains(fn ($i) => $i->id === $this->invoice->id);

        $this->assertTrue($found, "La búsqueda de '{$term}' (campo {$field}) no encontró la factura");
    }

    public function test_busca_por_todos_los_criterios(): void
    {
        // Identificación (parcial y completa)
        $this->assertFinds('1042770586', 'identity');
        $this->assertFinds('104277');

        // Nombre y nombre completo
        $this->assertFinds('Duban', 'name');
        $this->assertFinds('Duban Restrepo');

        // Número de contrato
        $this->assertFinds((string) $this->invoice->contract_id, 'contract');

        // Número de factura formal (completo y parcial)
        $this->assertFinds($this->invoice->full_number, 'invoice');
        $this->assertFinds('FAC' . $this->branch->id);

        // Teléfono
        $this->assertFinds('3126143902', 'phone');

        // Usuario PPPoE
        $this->assertFinds('duban.restrepo', 'pppoe');
        $this->assertFinds('duban.res');
    }

    public function test_no_incluye_facturas_que_no_admiten_pago(): void
    {
        // Anular la factura → deja de aparecer en el buscador
        $this->post(route('invoices.void', $this->invoice), [
            'void_reason' => 'Prueba de exclusión',
        ]);
        $this->assertSame(InvoiceStatus::Anulada->value, $this->invoice->fresh()->status);

        $response = $this->get(route('payments.searchView', [
            'search_term' => '1042770586',
        ]))->assertOk();

        $found = collect($response->viewData('invoices')->items())
            ->contains(fn ($i) => $i->id === $this->invoice->id);

        $this->assertFalse($found, 'Una factura anulada apareció en el buscador de cobros');
    }

    public function test_muestra_el_estado_de_la_caja_del_usuario(): void
    {
        // Sin caja abierta: advertencia clara
        $this->get(route('payments.searchView'))
            ->assertOk()
            ->assertSee('No tienes una caja abierta', false);

        // Con caja abierta: información de la caja lista para cobrar
        $register = $this->openCashRegister(initialAmount: 50000);

        $this->get(route('payments.searchView'))
            ->assertOk()
            ->assertSee("Caja #{$register->id} abierta", false)
            ->assertSee('Listo para cobrar', false);

        // El indicador también aparece con resultados de búsqueda
        $this->get(route('payments.searchView', ['search_term' => '1042770586']))
            ->assertOk()
            ->assertSee("Caja #{$register->id} abierta", false);
    }

    public function test_calcula_la_deuda_total_de_los_resultados(): void
    {
        // Segunda factura vencida del mismo contrato: la deuda
        // total debe sumar ambas
        Invoice::create([
            'contract_id' => $this->invoice->contract_id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'billed_year_month' => now()->subMonths(2)->format('Ym'),
            'issue_date' => now()->subMonths(2),
            'due_date' => now()->subMonths(2)->addDays(20),
            'total' => 50000,
            'pending_invoice_amount' => 50000,
            'status' => InvoiceStatus::Vencida->value,
        ]);

        $response = $this->get(route('payments.searchView', [
            'search_term' => '1042770586',
        ]))->assertOk();

        $this->assertSame(2, $response->viewData('resultCount'));
        $this->assertEqualsWithDelta(150000, (float) $response->viewData('totalBalance'), 0.01);

        // Las vencidas aparecen primero (orden por vencimiento)
        $first = $response->viewData('invoices')->items()[0];
        $this->assertSame(InvoiceStatus::Vencida->value, $first->status);
    }
}
