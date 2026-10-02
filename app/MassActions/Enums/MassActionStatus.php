<?php

namespace App\MassActions\Enums;

/**
 * En qué punto está una acción masiva.
 *
 * ENUM Y NO CONSTANTES, como el resto del proyecto (`ContractStatus`,
 * `InvoiceStatus`, `BillingCycle`): el valor que viaja a la base es una
 * cadena legible, y quien lo recibe no puede inventarse uno que no
 * exista.
 *
 * LOS ESTADOS NO SON ADORNO: DECIDEN
 * ----------------------------------
 * De ellos depende si aparece el botón de revertir. Una acción que
 * todavía está procesando no se revierte —no se sabe qué va a dejar—,
 * y una ya revertida tampoco, por mucho que alguien recargue la
 * página con el botón en la mano.
 */
enum MassActionStatus: string
{
    case Iniciada = 'iniciada';
    case Procesando = 'procesando';
    case Completada = 'completada';
    case CompletadaConErrores = 'completada_con_errores';
    case Fallida = 'fallida';
    case Revirtiendo = 'revirtiendo';
    case Revertida = 'revertida';
    case ReversionParcial = 'reversion_parcial';

    public function label(): string
    {
        return match ($this) {
            self::Iniciada => 'Iniciada',
            self::Procesando => 'Procesando',
            self::Completada => 'Completada',
            self::CompletadaConErrores => 'Completada con errores',
            self::Fallida => 'Fallida',
            self::Revirtiendo => 'Revirtiendo',
            self::Revertida => 'Revertida',
            self::ReversionParcial => 'Revertida parcialmente',
        };
    }

    /** El color del distintivo en la pantalla. */
    public function color(): string
    {
        return match ($this) {
            self::Completada, self::Revertida => 'success',
            self::CompletadaConErrores, self::ReversionParcial => 'warning',
            self::Fallida => 'danger',
            self::Procesando, self::Revirtiendo => 'info',
            self::Iniciada => 'secondary',
        };
    }

    /**
     * ¿Una acción en este estado se puede intentar revertir?
     *
     * Solo lo que terminó y todavía no se ha deshecho. «Revertida
     * parcialmente» SÍ entra: quedaron ítems sin revertir —en
     * conflicto, o fallidos— y reintentar es legítimo; los que ya se
     * revirtieron llevan su propia marca y no se tocan dos veces.
     */
    public function admiteReversion(): bool
    {
        return in_array($this, [
            self::Completada,
            self::CompletadaConErrores,
            self::ReversionParcial,
        ], true);
    }

    /** ¿Terminó de ejecutarse? */
    public function terminada(): bool
    {
        return !in_array($this, [self::Iniciada, self::Procesando, self::Revirtiendo], true);
    }
}
