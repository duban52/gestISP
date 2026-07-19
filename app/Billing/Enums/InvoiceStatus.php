<?php

namespace App\Billing\Enums;

/**
 * Estados del ciclo de vida de una factura.
 *
 * Única fuente de verdad de los valores de invoices.status. Antes
 * convivían variantes de mayúsculas ('pendiente'/'Pendiente',
 * 'vencida'/'Vencida') que solo funcionaban por la collation
 * case-insensitive de MySQL; la migración de normalización unificó
 * los datos a estos valores canónicos.
 *
 * Borrador y Anulada existen desde ya para el ciclo de vida
 * completo (fase 4: emisión formal y anulación); ningún flujo los
 * escribe todavía.
 */
enum InvoiceStatus: string
{
    case Borrador = 'Borrador';
    case Pendiente = 'Pendiente';
    case PendienteParcial = 'Pendiente Parcial';
    case PendienteRiesgoCorte = 'Pendiente con riesgo de corte';
    case Vencida = 'Vencida';
    case Pagada = 'Pagada';
    case CargadaANuevaFactura = 'Cargada a nueva factura';
    case Anulada = 'Anulada';

    /**
     * Estados que admiten recibir pagos (tienen saldo exigible).
     *
     * @return array<int, string>
     */
    public static function payable(): array
    {
        return [
            self::Pendiente->value,
            self::PendienteParcial->value,
            self::PendienteRiesgoCorte->value,
            self::Vencida->value,
        ];
    }

    /**
     * Estados que pasan a Vencida cuando se supera la fecha de
     * vencimiento. PendienteRiesgoCorte se excluye deliberadamente:
     * ya está en la ruta de suspensión y tiene su propia fecha de
     * corte (comportamiento heredado que se preserva).
     *
     * @return array<int, string>
     */
    public static function overdueCandidates(): array
    {
        return [
            self::Pendiente->value,
            self::PendienteParcial->value,
        ];
    }

    /**
     * Estados que ya no admiten pagos.
     *
     * @return array<int, string>
     */
    public static function notPayable(): array
    {
        return [
            self::Pagada->value,
            self::CargadaANuevaFactura->value,
            self::Anulada->value,
            self::Borrador->value,
        ];
    }
}
