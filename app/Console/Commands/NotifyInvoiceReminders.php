<?php

namespace App\Console\Commands;

use App\Billing\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Notifications\InvoiceDueSoon;
use App\Notifications\InvoiceOverdue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Envía los recordatorios de facturación al cliente:
 *
 *  - "Por vencer": tantos días antes del vencimiento como diga la
 *    configuración (notifications.invoice.due_soon_days).
 *  - "Vencida": cuando la factura ya pasó su fecha de vencimiento.
 *
 * Es IDEMPOTENTE: cada factura marca cuándo se le envió cada aviso
 * (due_soon_notified_at / overdue_notified_at), así que aunque el
 * comando corra a diario, el cliente recibe cada recordatorio una
 * sola vez. Se agenda una vez al día.
 */
class NotifyInvoiceReminders extends Command
{
    protected $signature = 'invoices:notify-reminders';

    protected $description = 'Avisa al cliente cuando su factura está por vencer o ya se venció';

    public function handle(): int
    {
        $porVencer = $this->recordatoriosPorVencer();
        $vencidas = $this->recordatoriosVencidas();

        $this->info("Avisos enviados — por vencer: {$porVencer}, vencidas: {$vencidas}.");

        return self::SUCCESS;
    }

    /**
     * Avisa de las facturas que vencen dentro de N días.
     */
    private function recordatoriosPorVencer(): int
    {
        $dias = config('notifications.invoice.due_soon_days', [3]);
        $enviados = 0;

        foreach ($dias as $n) {
            $objetivo = Carbon::today()->addDays((int) $n);

            $facturas = Invoice::query()
                ->whereIn('status', InvoiceStatus::payable())
                ->whereNull('due_soon_notified_at')
                ->whereDate('due_date', $objetivo)
                ->where('pending_invoice_amount', '>', 0)
                ->with('contract.client', 'branch')
                ->get();

            foreach ($facturas as $factura) {
                $cliente = $factura->contract?->client;

                if ($cliente) {
                    $cliente->notify(new InvoiceDueSoon($factura, (int) $n));
                }

                // Se marca aunque el cliente no tenga datos de
                // contacto, para no reintentarlo cada día.
                $factura->update(['due_soon_notified_at' => now()]);
                $enviados++;
            }
        }

        return $enviados;
    }

    /**
     * Avisa de las facturas ya vencidas que aún no se han avisado.
     */
    private function recordatoriosVencidas(): int
    {
        $facturas = Invoice::query()
            ->where('status', InvoiceStatus::Vencida->value)
            ->whereNull('overdue_notified_at')
            ->where('pending_invoice_amount', '>', 0)
            ->with('contract.client', 'branch')
            ->get();

        foreach ($facturas as $factura) {
            $cliente = $factura->contract?->client;

            if ($cliente) {
                $cliente->notify(new InvoiceOverdue($factura));
            }

            $factura->update(['overdue_notified_at' => now()]);
        }

        return $facturas->count();
    }
}
