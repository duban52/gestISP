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
 *
 * Y dos FINALES, a los que no se llega por cobranza:
 *
 *   · Retirado — tuvo servicio y lo dejó. Se llega al cerrar una orden
 *     de «Retiro de servicio», o con una orden administrativa.
 *   · Anulado  — firmó el contrato y nunca tomó el servicio. Solo por
 *     orden administrativa: no hay trabajo de campo que lo produzca.
 *
 * No son lo mismo y por eso son dos estados. Contarlos juntos mentiría
 * en los informes de bajas: una anulación no es un cliente perdido,
 * es un contrato que nunca empezó.
 */
enum ContractStatus: string
{
    case PorInstalar = 'Por Instalar';
    case Activo = 'Activo';
    case PreSuspension = 'Pre-suspensión';
    case Suspendido = 'Suspendido';
    case PorReconexion = 'Por Reconexión';
    case Retirado = 'Retirado';
    case Anulado = 'Anulado';

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

    /**
     * Estados a los que NO se le emite factura, pase lo que pase.
     *
     * POR QUÉ ESTA LISTA ADEMÁS DE `billable()`
     * -----------------------------------------
     * `billable()` es el filtro de la corrida mensual: dice a quién SÍ
     * se factura. Esta es la otra mitad, y hace falta porque hay una
     * segunda puerta —generar la factura de un contrato suelto desde su
     * ficha— que no pasa por ese filtro.
     *
     * Se declara en negativo a propósito. Invertir `billable()` habría
     * bloqueado también «Por Instalar», y ese caso hoy se factura a
     * mano a veces (un cobro de instalación antes de instalar). Cambiar
     * eso de paso sería colar una decisión de negocio dentro de otra.
     *
     * Van los finales y el corte. «Cortado» es el nombre viejo de
     * Suspendido y sigue habiendo filas con él: dejarlo fuera
     * permitiría facturarle a un contrato cortado solo por cómo está
     * escrito su estado.
     *
     * @return array<int, string>
     */
    public static function noFacturables(): array
    {
        return [
            self::Suspendido->value,
            'Cortado',
            self::Retirado->value,
            self::Anulado->value,
        ];
    }

    /**
     * Bajas: el contrato terminó y no vuelve.
     *
     * Para volver a prestarle servicio a ese cliente se hace un
     * contrato nuevo. Reactivar uno dado de baja dejaría un historial
     * de facturación con un agujero en medio y ningún documento que lo
     * explique.
     *
     * @return array<int, string>
     */
    public static function finales(): array
    {
        return [
            self::Retirado->value,
            self::Anulado->value,
        ];
    }

    /** ¿Este estado admite que se le emita una factura? */
    public static function facturable(?string $estado): bool
    {
        return $estado !== null && !in_array($estado, self::noFacturables(), true);
    }

    /** ¿Es una baja definitiva? */
    public static function esFinal(?string $estado): bool
    {
        return $estado !== null && in_array($estado, self::finales(), true);
    }

    /** Cómo se explica cada estado en pantalla. */
    public function descripcion(): string
    {
        return match ($this) {
            self::PorInstalar => 'Contrato creado; el servicio todavía no se ha instalado.',
            self::Activo => 'Prestando servicio y al día.',
            self::PreSuspension => 'Tiene facturas vencidas pero conserva el servicio.',
            self::Suspendido => 'Servicio cortado por no pago.',
            self::PorReconexion => 'Pagó estando cortado; espera la visita de reconexión.',
            self::Retirado => 'Tuvo servicio y lo dejó. No se le factura más.',
            self::Anulado => 'Firmó el contrato y nunca tomó el servicio. No se le factura.',
        };
    }
}
