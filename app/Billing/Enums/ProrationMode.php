<?php

namespace App\Billing\Enums;

/**
 * Modo de facturación del primer mes de un contrato.
 *
 * Configurable por sucursal en branch_billing_settings:
 *
 * - Prorated (opción B, default — comportamiento histórico):
 *   contrato activado a mitad de mes paga solo los días restantes
 *   (precio * días_restantes / días_del_mes).
 *
 * - FullMonth (opción A): se factura el mes completo sin importar
 *   el día de activación.
 */
enum ProrationMode: string
{
    case FullMonth = 'full_month';
    case Prorated = 'prorated';

    /**
     * Etiqueta legible para formularios.
     */
    public function label(): string
    {
        return match ($this) {
            self::FullMonth => 'Mes completo (sin prorrateo)',
            self::Prorated => 'Prorratear días restantes',
        };
    }
}
