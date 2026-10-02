<?php

namespace App\MassActions;

use App\MassActions\Enums\MassActionType;
use App\MassActions\Reversiones\AnularCorridaDeFacturacion;
use App\MassActions\Reversiones\RevertirCorteDeContratos;
use App\MassActions\Reversiones\RevertirCortePppoe;
use App\MassActions\Reversiones\RevertirImportacionDeClientes;

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
 * Y es la respuesta correcta: una importación de ONTs se registra
 * —para saber qué entró y cuándo— pero deshacerla significaría
 * desautorizar equipos en una OLT, que no es una fila de una tabla.
 * Mejor que el historial lo diga a que un botón prometa algo que no
 * puede cumplir.
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
