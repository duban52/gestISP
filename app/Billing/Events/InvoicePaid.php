<?php

namespace App\Billing\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando una factura queda totalmente pagada.
 */
class InvoicePaid
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice)
    {
    }
}
