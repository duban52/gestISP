<?php

namespace App\Billing\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando una factura se anula. Punto de extensión para
 * notificar la anulación al proveedor de facturación electrónica
 * (fase 6).
 */
class InvoiceVoided
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice)
    {
    }
}
