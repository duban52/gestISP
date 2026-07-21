<?php

namespace App\Listeners;

use App\Billing\Events\InvoiceIssued;
use App\Notifications\InvoiceGenerated;

/**
 * Avisa al cliente por correo y WhatsApp cuando se emite su factura.
 *
 * Escucha el evento InvoiceIssued (que dispara InvoiceGenerator al
 * numerar la factura), así el aviso cubre tanto la facturación
 * mensual masiva como la generación individual, sin acoplar la
 * notificación al servicio de facturación.
 */
class NotifyClientInvoiceIssued
{
    public function handle(InvoiceIssued $event): void
    {
        $invoice = $event->invoice->loadMissing('contract.client', 'branch');
        $cliente = $invoice->contract?->client;

        // La notificación va en cola: no demora el cierre de la
        // corrida de facturación aunque sean cientos de facturas.
        optional($cliente)->notify(new InvoiceGenerated($invoice));
    }
}
