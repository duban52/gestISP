<?php

namespace App\Billing\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando una factura queda emitida (numerada y
 * cobrable). Punto de extensión para notificaciones y para el
 * envío a facturación electrónica (fase 6).
 */
class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice)
    {
    }
}
