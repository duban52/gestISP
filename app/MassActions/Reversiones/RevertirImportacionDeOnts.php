<?php

namespace App\MassActions\Reversiones;

use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\MassActionItem;
use App\Models\Ont;
use App\Services\Audit\AuditLogger;

/**
 * Deshace una importación de ONTs borrándolas del sistema.
 *
 * NO TOCA LA OLT. ES LA REGLA DE ESTA REVERSIÓN
 * ---------------------------------------------
 * La importación no configuró nada: LEYÓ la OLT y copió a la base lo
 * que encontró. Deshacerla es borrar esa copia, y nada más. Mandar un
 * `ont delete` al equipo sería hacer algo que la importación nunca
 * hizo — y dejaría sin servicio a clientes que llevan meses navegando,
 * por deshacer una lectura.
 *
 * Las ONT siguen autorizadas en la OLT. Volver a importar las trae de
 * nuevo, que es justamente la prueba de que no se perdió nada.
 *
 * SE NIEGA CUANDO LA ONT YA TIENE VIDA PROPIA
 * -------------------------------------------
 * Si después de importarla alguien la vinculó a un contrato, le anotó
 * un puerto de caja NAP o le cambió algo, borrarla se llevaría ese
 * trabajo por delante. Y una ONT activada DESPUÉS por el flujo normal
 * —no por esta importación— no es cosa de esta acción.
 */
class RevertirImportacionDeOnts implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly AuditLogger $auditoria,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $ont = $item->subject;

        if (!$ont instanceof Ont) {
            return 'La ONT ya no existe en el sistema.';
        }

        // La importación las trae SIN contrato, salvo las que casó por
        // descripción —y eso también lo hizo ella—. Si hoy tiene un
        // contrato que no traía, alguien la vinculó después.
        $contratoImportado = $item->valorDespues('contract_id');

        if ($ont->contract_id && (int) $ont->contract_id !== (int) $contratoImportado) {
            return sprintf(
                'La ONT se vinculó después al contrato %s: borrarla se llevaría ese trabajo.',
                $ont->contract?->numero_visible ?? $ont->contract_id,
            );
        }

        return null;
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var Ont $ont */
        $ont = $item->subject;

        $sn = $ont->sn;

        // Solo la fila. En la OLT la ONT sigue autorizada y el cliente
        // sigue navegando: volver a importar la trae otra vez.
        $ont->delete();

        $this->auditoria->action(
            'onts.import_reverted',
            'Borró del sistema la ONT ' . $sn . ' al revertir una importación. La OLT no se tocó.',
            ['sn' => $sn, 'accion' => $item->mass_action_id],
            null,
            'red',
        );
    }

    public function advertencia(): string
    {
        return 'Se borrarán del SISTEMA las ONT que trajo la importación. La OLT NO se toca: '
            . 'siguen autorizadas y los clientes siguen navegando; volver a importar las trae '
            . 'de nuevo. Las que se vincularon a un contrato después no se tocan.';
    }
}
