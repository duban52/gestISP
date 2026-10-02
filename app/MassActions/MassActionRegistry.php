<?php

namespace App\MassActions;

use App\MassActions\Enums\MassActionType;
use App\MassActions\Reversiones\AnularCorridaDeFacturacion;
use App\MassActions\Reversiones\RevertirCorteDeContratos;
use App\MassActions\Reversiones\RevertirCortePppoe;
use App\MassActions\Reversiones\RevertirCobroMultiple;
use App\MassActions\Reversiones\RevertirImportacionDeClientes;
use App\MassActions\Reversiones\RevertirImportacionDeOnts;
use App\MassActions\Reversiones\RevertirMovimientoDeAlmacen;

/**
 * Qué estrategia sabe deshacer cada tipo de operación.
 *
 * UN SOLO SITIO DONDE MIRARLO
 * ---------------------------
 * La pantalla pregunta aquí si enseñar el botón, el motor pregunta
 * aquí a quién llamar, y la política pregunta aquí si la acción es
 * reversible siquiera. Si esto estuviera repartido en tres `match`,
 * bastaría olvidarse de uno para que un botón ofreciera deshacer algo
 * que nadie sabe deshacer.
 *
 * LO QUE NO ESTÁ AQUÍ, NO SE REVIERTE
 * -----------------------------------
 * Y es la respuesta correcta. Mejor que el historial diga «esto no
 * se deshace» a que un botón prometa algo que no puede cumplir.
 *
 * DESHACER NO ES SIEMPRE LO MISMO QUE DESCONFIGURAR
 * -------------------------------------------------
 * La importación de ONTs LEYÓ la OLT y copió lo que encontró: no
 * configuró nada. Por eso sí se revierte, pero solo en la base.
 * Mandarle un borrado a la OLT sería hacer algo que la importación
 * nunca hizo, y dejar sin servicio a clientes que llevan meses
 * navegando por deshacer una lectura.
 */
class MassActionRegistry
{
    /** @return array<string, class-string<RevierteUnaAccionMasiva>> */
    private function estrategias(): array
    {
        return [
            MassActionType::CorteDeContratos->value => RevertirCorteDeContratos::class,
            MassActionType::CortePppoe->value => RevertirCortePppoe::class,
            MassActionType::ImportacionDeClientes->value => RevertirImportacionDeClientes::class,
            MassActionType::CorridaDeFacturacion->value => AnularCorridaDeFacturacion::class,
            MassActionType::CobroMultiple->value => RevertirCobroMultiple::class,
            MassActionType::MovimientoDeAlmacen->value => RevertirMovimientoDeAlmacen::class,
            MassActionType::ImportacionDeOnts->value => RevertirImportacionDeOnts::class,
        ];
    }

    public function esReversible(?MassActionType $tipo): bool
    {
        return $tipo !== null && isset($this->estrategias()[$tipo->value]);
    }

    /**
     * La estrategia del tipo, o null si ese tipo no se revierte.
     */
    public function para(?MassActionType $tipo): ?RevierteUnaAccionMasiva
    {
        $clase = $tipo ? ($this->estrategias()[$tipo->value] ?? null) : null;

        return $clase ? app($clase) : null;
    }
}
