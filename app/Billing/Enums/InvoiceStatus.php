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
    /**
     * La factura quedó en cero por una NOTA CRÉDITO, no porque el
     * cliente pagara. Contablemente no es lo mismo: el ingreso no se
     * recaudó, se anuló o se ajustó. Tenerlo aparte evita que un
     * informe de recaudo cuente como cobrado lo que nunca entró.
     */
    case SaldadaConNota = 'Saldada con nota crédito';
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
            self::SaldadaConNota->value,
            self::CargadaANuevaFactura->value,
            self::Anulada->value,
            self::Borrador->value,
        ];
    }

    /**
     * Estados en los que la factura ya no debe nada, sin importar
     * cómo se saldó (cobrada o ajustada con nota crédito).
     *
     * @return array<int, string>
     */
    public static function settled(): array
    {
        return [
            self::Pagada->value,
            self::SaldadaConNota->value,
        ];
    }
}
