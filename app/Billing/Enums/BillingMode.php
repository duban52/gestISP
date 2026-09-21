<?php

namespace App\Billing\Enums;

/**
 * Cómo se lanza la corrida mensual de una sucursal.
 *
 * - Manual: solo con el botón «Generar facturas». Es el defecto y el
 *   comportamiento de siempre.
 *
 * - Automatico: la tarea diaria la lanza sola el día del mes que
 *   configure la sucursal (`billing_day`).
 *
 * Es por sucursal a propósito: una sede con quien revise antes de
 * emitir puede querer seguir a mano aunque la de al lado no.
 */
enum BillingMode: string
{
    case Manual = 'manual';
    case Automatico = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual (con el botón Generar facturas)',
            self::Automatico => 'Automática (el día del mes que se indique)',
        };
    }

    public function esAutomatico(): bool
    {
        return $this === self::Automatico;
    }
}
