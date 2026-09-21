<?php

namespace App\Billing\Enums;

/**
 * Cómo se expresa el descuento de un contrato.
 *
 * - Porcentaje: sobre el precio de cada servicio del plan.
 * - Valor fijo: se reparte entre los servicios del plan, en
 *   proporción a su precio, para que ningún renglón quede en
 *   negativo y la suma siga cuadrando.
 */
enum DiscountType: string
{
    case Porcentaje = 'percent';
    case Valor = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Porcentaje => 'Porcentaje (%)',
            self::Valor => 'Valor fijo ($)',
        };
    }
}
