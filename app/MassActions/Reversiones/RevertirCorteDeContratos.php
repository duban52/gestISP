<?php

namespace App\MassActions\Reversiones;

use App\Billing\Enums\ContractStatus;
use App\Billing\Services\ContractStatusFromOrder;
use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\Contract;
use App\Models\MassActionItem;

/**
 * Deshace un corte masivo por cartera.
 *
 * QUÉ DESHACE DE VERDAD
 * ---------------------
 * Le devuelve al contrato el estado que tenía y, con él, el servicio:
 * la ONT y la cuenta PPPoE se vuelven a habilitar. No es un cambio de
 * columna — es exactamente el camino inverso del corte, y pasa por el
 * mismo sitio que lo cortó (`ContractStatusFromOrder`), para que el
 * equipo se entere igual que se enteró al cortarlo.
 *
 * LA ORDEN ADMINISTRATIVA NO SE BORRA
 * -----------------------------------
 * El corte creó una orden cerrada que dice por qué se cortó, y eso
 * ocurrió. Se crea OTRA que dice por qué se restableció. Borrar la
 * primera dejaría al cliente preguntando por qué estuvo tres días sin
 * servicio y al sistema sin nada que responder.
 *
 * CUÁNDO SE NIEGA
 * ---------------
 * Cuando el contrato ya no está como lo dejó el corte. Si alguien lo
 * retiró, lo cedió o lo reactivó a mano, revertir sería pisar esa
 * decisión con una de hace tres días.
 */
class RevertirCorteDeContratos implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly ContractStatusFromOrder $estados,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $contrato = $item->subject;

        if (!$contrato instanceof Contract) {
            return 'El contrato ya no existe en el sistema.';
        }

        $esperado = $item->valorDespues('status');
        $actual = (string) $contrato->status;

        if ($esperado === null) {
            return 'No se guardó el estado que dejó el corte, así que no se puede comprobar.';
        }

        if ($actual !== $esperado) {
            return sprintf(
                'El corte lo dejó en «%s» y ahora está en «%s»: alguien lo cambió después.',
                $esperado,
                $actual,
            );
        }

        // Un contrato terminado no se resucita con una reversión: se
        // retiró o se anuló, y eso es una decisión posterior.
        if (in_array($actual, ContractStatus::finales(), true)) {
            return sprintf('El contrato está en «%s», que es un estado final.', $actual);
        }

        return null;
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var Contract $contrato */
        $contrato = $item->subject;

        $anterior = (string) $item->valorAntes('status');

        $this->estados->ordenAdministrativa(
            $contrato,
            $anterior,
            sprintf(
                'Reversión del corte masivo #%d: el contrato vuelve a «%s» y se le restablece el servicio.',
                $item->mass_action_id,
                $anterior,
            ),
            auth()->id(),
            'Reversión de corte masivo',
        );
    }

    public function advertencia(): string
    {
        return 'Los contratos volverán al estado que tenían antes del corte y se les '
            . 'restablecerá el servicio (ONT y PPPoE). Se creará una orden administrativa '
            . 'por cada uno dejando constancia; las órdenes del corte original no se borran.';
    }
}
