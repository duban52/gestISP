<?php

namespace App\Billing\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara con cada pago registrado (total o parcial).
 */
class PaymentRegistered
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment)
    {
    }
}
