<?php

namespace App\Billing\Enums;

/**
 * Estados de un pago.
 *
 * Única fuente de verdad de los valores de payments.status. Los
 * saldos de factura se calculan sumando SOLO pagos Completed.
 *
 * Voided existe desde ya para la anulación formal de pagos
 * (fase 4); ningún flujo lo escribe todavía — hoy los pagos se
 * anulan con SoftDeletes.
 */
enum PaymentStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided';
}
