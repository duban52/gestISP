<?php

namespace App\Billing\Enums;

use Carbon\CarbonInterface;

/**
 * QUÉ MES COBRA LA CORRIDA.
 *
 * Es una decisión comercial, no técnica, y hasta ahora estaba
 * implícita: la corrida facturaba SIEMPRE el mes en el que se
 * ejecutaba. Un ISP que cobra por adelantado —lo más común— no tenía
 * forma de decirlo, y uno que cobra vencido tampoco.
 *
 * LAS TRES FORMAS
 * ---------------
 * Corrida del 25 de septiembre:
 *
 *   · Anticipado → factura OCTUBRE (1 al 31 de octubre).
 *     El cliente paga antes de consumir. Es lo habitual en un ISP.
 *
 *   · En curso   → factura SEPTIEMBRE (1 al 30 de septiembre).
 *     Es lo que hacía el sistema, y por eso es el valor por defecto:
 *     nadie se encuentra un cambio de criterio sin pedirlo. Cobra un
 *     mes que ya va por la mitad.
 *
 *   · Vencido    → factura AGOSTO (1 al 31 de agosto).
 *     El cliente paga lo ya consumido.
 *
 * LO QUE NO CAMBIA
 * ----------------
 * La fecha de emisión y la de vencimiento siguen saliendo del día en
 * que se corre: la factura se emite hoy y se paga en los días de plazo
 * de la sucursal. Lo único que mueve el ciclo es el PERÍODO que se
 * cobra.
 */
enum BillingCycle: string
{
    case Anticipado = 'advance';
    case EnCurso = 'current';
    case Vencido = 'arrears';

    public function label(): string
    {
        return match ($this) {
            self::Anticipado => 'Anticipado (el mes siguiente)',
            self::EnCurso => 'Mes en curso (el mes de la corrida)',
            self::Vencido => 'Vencido (el mes anterior)',
        };
    }

    /** Una frase de ayuda para el formulario. */
    public function ayuda(): string
    {
        return match ($this) {
            self::Anticipado => 'La corrida de septiembre cobra octubre. El cliente paga antes de consumir.',
            self::EnCurso => 'La corrida de septiembre cobra septiembre.',
            self::Vencido => 'La corrida de septiembre cobra agosto. El cliente paga lo ya consumido.',
        };
    }

    /**
     * El PRIMER DÍA del mes que se factura, a partir del día en que se
     * corre.
     *
     * `subMonthNoOverflow`/`addMonthNoOverflow` y no `subMonth`: desde
     * el 31 de marzo, «el mes anterior» tiene que ser febrero, no el 3
     * de marzo. Aquí el día ya es 1, así que da igual, pero dejarlo
     * escrito evita que alguien lo copie mal en otro sitio.
     */
    public function mesFacturado(CarbonInterface $hoy): CarbonInterface
    {
        $mes = $hoy->copy()->startOfMonth();

        return match ($this) {
            self::Anticipado => $mes->addMonthNoOverflow(),
            self::EnCurso => $mes,
            self::Vencido => $mes->subMonthNoOverflow(),
        };
    }
}
