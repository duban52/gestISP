<?php

namespace App\MassActions\Enums;

/**
 * Qué pasó con UN registro dentro de una acción masiva.
 *
 * LA DIFERENCIA ENTRE «OMITIDO» Y «ERROR» IMPORTA
 * -----------------------------------------------
 * Omitido es una decisión del sistema —ese contrato no debía dos
 * meses, esa fila ya existía—: la operación hizo lo correcto. Error es
 * que algo se rompió. Contarlos juntos haría que una corrida sana
 * pareciera un desastre, y al revés: un desastre pasaría por sano.
 *
 * Y CONFLICTO NO ES ERROR
 * -----------------------
 * Conflicto es que el registro cambió DESPUÉS de la acción y por eso
 * no se puede revertir sin pisar a quien lo cambió. No falló nada: el
 * sistema se negó a sobrescribir en silencio, que es exactamente lo
 * que se le pide.
 */
enum MassActionItemStatus: string
{
    case Pendiente = 'pendiente';
    case Ok = 'ok';
    case Omitido = 'omitido';
    case Error = 'error';
    case Revertido = 'revertido';
    case Conflicto = 'conflicto';
    case NoReversible = 'no_reversible';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Ok => 'Correcto',
            self::Omitido => 'Omitido',
            self::Error => 'Error',
            self::Revertido => 'Revertido',
            self::Conflicto => 'Conflicto',
            self::NoReversible => 'No reversible',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok, self::Revertido => 'success',
            self::Omitido => 'secondary',
            self::Error => 'danger',
            self::Conflicto, self::NoReversible => 'warning',
            self::Pendiente => 'light',
        };
    }

    /**
     * ¿Este ítem es candidato a revertirse?
     *
     * Solo lo que de verdad se hizo. Un omitido no cambió nada que
     * deshacer, y un error tampoco llegó a cambiarlo.
     */
    public function seRevierte(): bool
    {
        return $this === self::Ok;
    }
}
