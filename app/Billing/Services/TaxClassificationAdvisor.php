<?php

namespace App\Billing\Services;

use App\Billing\Enums\TaxClassification;
use App\Models\Contract;

/**
 * Avisa cuando el IVA de un contrato no cuadra con su estrato.
 *
 * LA REGLA
 * --------
 * El internet residencial de los **estratos 1, 2 y 3 está excluido de
 * IVA**; para los demás se grava a la tarifa general.
 *
 * AVISA, NO DECIDE
 * ----------------
 * Y eso es deliberado. La clasificación fiscal es del SERVICIO y la
 * pone quien lleva la contabilidad; meter la regla del estrato dentro
 * del código que factura significaría enterrar derecho tributario donde
 * nadie lo ve, y que un cambio de ley obligue a un despliegue.
 *
 * Lo que hace esto es señalar la combinación sospechosa —un estrato 5
 * facturando internet excluido, o un estrato 2 pagando IVA— para que
 * alguien la mire. Puede haber razones legítimas: un contrato
 * empresarial en una dirección de estrato bajo, por ejemplo. Por eso no
 * bloquea nada.
 *
 * DÓNDE SE MIRA
 * -------------
 * Al dar de alta o editar un contrato —que es donde se decide— y en el
 * informe de completitud fiscal, para poder repasar lo que ya existe.
 */
class TaxClassificationAdvisor
{
    /** Los estratos con internet excluido de IVA. */
    public const ESTRATOS_EXCLUIDOS = ['1', '2', '3'];

    /**
     * Qué no cuadra en este contrato.
     *
     * @return array<int, string>  vacío si todo encaja
     */
    public function avisos(Contract $contrato): array
    {
        $estrato = trim((string) $contrato->social_stratum);

        if ($estrato === '') {
            return [];
        }

        $servicios = $contrato->plan?->services;

        if (!$servicios || $servicios->isEmpty()) {
            return [];
        }

        $deberiaSerExcluido = in_array($estrato, self::ESTRATOS_EXCLUIDOS, true);
        $avisos = [];

        foreach ($servicios as $servicio) {
            $clasificacion = $servicio->clasificacion();

            if ($deberiaSerExcluido && $clasificacion === TaxClassification::Gravado) {
                $avisos[] = sprintf(
                    'El estrato %s tiene el internet excluido de IVA, pero «%s» está clasificado como gravado.',
                    $estrato,
                    $servicio->name,
                );

                continue;
            }

            if (!$deberiaSerExcluido && $clasificacion === TaxClassification::Excluido) {
                $avisos[] = sprintf(
                    'El estrato %s se grava a la tarifa general, pero «%s» está clasificado como excluido de IVA.',
                    $estrato,
                    $servicio->name,
                );
            }
        }

        return $avisos;
    }

    /** ¿Hay algo que mirar? */
    public function tieneAvisos(Contract $contrato): bool
    {
        return $this->avisos($contrato) !== [];
    }
}
