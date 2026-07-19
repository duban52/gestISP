<?php

namespace App\Billing\Enums;

/**
 * Estados del ciclo de vida de un contrato.
 *
 * Única fuente de verdad de los valores de contracts.status.
 * El flujo de cobranza es:
 *
 *   Activo → Pre-suspensión (facturas vencidas, aún con servicio)
 *          → Suspendido     (servicio cortado)
 *          → Por Reconexión (pagó estando cortado; espera visita)
 *          → Activo
 */
enum ContractStatus: string
{
    case PorInstalar = 'Por Instalar';
    case Activo = 'Activo';
    case PreSuspension = 'Pre-suspensión';
    case Suspendido = 'Suspendido';
    case PorReconexion = 'Por Reconexión';

    /**
     * Estados que se incluyen en la generación de facturas
     * mensuales (con servicio activo o en riesgo, aún no cortado).
     *
     * @return array<int, string>
     */
    public static function billable(): array
    {
        return [
            self::Activo->value,
            self::PreSuspension->value,
        ];
    }
}
