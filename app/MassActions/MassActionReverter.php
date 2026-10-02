<?php

namespace App\MassActions;

use App\MassActions\Enums\MassActionItemStatus;
use App\MassActions\Enums\MassActionStatus;
use App\MassActions\Enums\MassActionType;
use App\Models\MassAction;
use App\Models\MassActionItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Deshace una acción masiva, registro por registro.
 *
 * LAS CUATRO COSAS QUE ESTE SERVICIO NO HACE NUNCA
 * ------------------------------------------------
 * 1. **No sobrescribe cambios posteriores.** Antes de tocar un
 *    registro comprueba que siga como lo dejó la acción. Si alguien lo
 *    cambió después, queda en CONFLICTO y se deja en paz. Un corte que
 *    se revierte no puede resucitar un contrato que mientras tanto se
 *    retiró.
 * 2. **No revierte dos veces.** La acción se bloquea antes de empezar
 *    y cada ítem lleva la fecha en que se revirtió. Un job que se
 *    reintenta no vuelve a tocar lo ya hecho.
 * 3. **No lo carga todo en memoria.** Los ítems se recorren con
 *    `chunkById`: esto tiene que funcionar igual con ochocientos que
 *    con cincuenta mil.
 * 4. **No deja que un registro tumbe a los demás.** Cada ítem va en su
 *    propia transacción. El que falla queda marcado y los otros
 *    ochocientos siguen.
 *
 * LA REVERSIÓN ES OTRA ACCIÓN MASIVA
 * ----------------------------------
 * Con su usuario, su hora, sus ítems y sus conflictos; y las dos se
 * apuntan entre sí. Así, al mirar una acción vieja, se ve quién la
 * deshizo y con qué resultado — que es la pregunta que se hace alguien
 * tres meses después.
 */
class MassActionReverter
{
    /** De a cuántos se traen los ítems. */
    private const LOTE = 200;

    public function __construct(
        private readonly MassActionRegistry $registro,
        private readonly MassActionRecorder $recorder,
        private readonly AuditLogger $auditoria,
    ) {
    }

    /**
     * Qué pasaría si se revirtiera, SIN revertir nada.
     *
     * Es lo que alimenta la pantalla de confirmación: cuántos se
     * pueden deshacer y cuántos están en conflicto, con su motivo.
     * Nadie debería confirmar una reversión de ochocientos registros
     * sin saber que ciento veinte no se van a poder.
     *
     * @return array{reversibles: int, conflictos: int, motivos: array<int, array{label: ?string, motivo: string}>}
     */
    public function revisar(MassAction $accion, int $muestraDeConflictos = 20): array
    {
        $estrategia = $this->registro->para($accion->type);

        if (!$estrategia) {
            return ['reversibles' => 0, 'conflictos' => 0, 'motivos' => []];
        }

        $reversibles = 0;
        $conflictos = 0;
        $motivos = [];

        $accion->items()
            ->where('status', MassActionItemStatus::Ok->value)
            ->whereNull('reverted_at')
            ->chunkById(self::LOTE, function ($items) use ($estrategia, &$reversibles, &$conflictos, &$motivos, $muestraDeConflictos) {
                foreach ($items as $item) {
                    $motivo = $estrategia->revisar($item);

                    if ($motivo === null) {
                        $reversibles++;

                        continue;
                    }

                    $conflictos++;

                    if (count($motivos) < $muestraDeConflictos) {
                        $motivos[] = ['label' => $item->label, 'motivo' => $motivo];
                    }
                }
            });

        return compact('reversibles', 'conflictos', 'motivos');
    }

    /**
     * Deshace la acción y devuelve la acción de reversión.
     *
     * @throws RuntimeException si la acción no admite reversión
     */
    public function revertir(MassAction $accion): MassAction
    {
        $estrategia = $this->registro->para($accion->type);

        if (!$estrategia) {
            throw new RuntimeException('Esta operación no se puede revertir automáticamente.');
        }

        // EL CANDADO CONTRA LA DOBLE REVERSIÓN.
        //
        // Se relee con la fila bloqueada y se comprueba dentro de la
        // transacción: dos pestañas abiertas, o un doble clic, llegan
        // aquí a la vez y sin esto las dos pasarían la comprobación.
        $reversion = DB::transaction(function () use ($accion) {
            $fresca = MassAction::whereKey($accion->id)->lockForUpdate()->firstOrFail();

            if (!$fresca->status->admiteReversion() || $fresca->reverted_by_mass_action_id !== null) {
                throw new RuntimeException('Esta acción ya fue revertida o no admite reversión.');
            }

            $reversion = MassAction::create([
                'type' => MassActionType::Reversion,
                'status' => MassActionStatus::Revirtiendo,
                'description' => 'Reversión de: ' . $fresca->description,
                'user_id' => Auth::id(),
                'branch_id' => $fresca->branch_id,
                'company_id' => $fresca->company_id,
                'summary' => [
                    'accion_original' => $fresca->id,
                    'tipo_original' => $fresca->type->value,
                ],
                'reverses_mass_action_id' => $fresca->id,
                'request_id' => AuditLogger::requestId(),
                'started_at' => now(),
            ]);

            $fresca->update([
                'status' => MassActionStatus::Revirtiendo,
                'reverted_by_mass_action_id' => $reversion->id,
            ]);

            return $reversion;
        });

        $this->auditoria->action(
            'mass_actions.revert_started',
            'Inició la reversión de la acción masiva #' . $accion->id . ' (' . $accion->type->label() . ')',
            ['accion' => $accion->id, 'reversion' => $reversion->id],
            $accion,
            'sistema',
        );

        $this->recorrer($accion, $reversion, $estrategia);

        return $reversion->refresh();
    }

    /**
     * Recorre los ítems y los va deshaciendo.
     */
    private function recorrer(MassAction $accion, MassAction $reversion, RevierteUnaAccionMasiva $estrategia): void
    {
        $accion->items()
            ->where('status', MassActionItemStatus::Ok->value)
            ->whereNull('reverted_at')
            ->chunkById(self::LOTE, function ($items) use ($reversion, $estrategia) {
                foreach ($items as $item) {
                    $this->revertirItem($item, $reversion, $estrategia);
                }
            });

        $this->cerrar($accion, $reversion);
    }

    /**
     * Un solo registro, en su propia transacción.
     */
    private function revertirItem(MassActionItem $item, MassAction $reversion, RevierteUnaAccionMasiva $estrategia): void
    {
        $motivo = $estrategia->revisar($item);

        if ($motivo !== null) {
            // EL CONFLICTO NO ES DEL ÍTEM, ES DEL INTENTO.
            //
            // El ítem sigue siendo lo que fue: un registro que la
            // acción cambió correctamente. Lo que falló es deshacerlo
            // hoy, porque alguien lo tocó. Si aquí se le cambiara el
            // estado, se perdería el dato de que había que revertirlo
            // —y con él la posibilidad de reintentarlo cuando se
            // resuelva lo que estorbaba—; además la acción se daría
            // por revertida del todo sin haberlo estado.
            //
            // El conflicto, como hecho, queda en los ítems de la
            // REVERSIÓN, que es la operación que lo encontró.
            $item->update(['conflict_reason' => mb_substr($motivo, 0, 1000)]);

            $this->recorder->registrar(
                $reversion,
                $item->subject,
                $item->label,
                estado: MassActionItemStatus::Conflicto,
                mensaje: $motivo,
            );

            return;
        }

        try {
            DB::transaction(fn () => $estrategia->revertir($item));

            $item->update([
                'status' => MassActionItemStatus::Revertido,
                'reverted_at' => now(),
                // Si venía de un conflicto anterior ya resuelto, el
                // motivo viejo deja de ser cierto.
                'conflict_reason' => null,
            ]);

            $this->recorder->registrar(
                $reversion,
                $item->subject,
                $item->label,
                antes: $item->after ?? [],
                despues: $item->before ?? [],
                estado: MassActionItemStatus::Revertido,
            );
        } catch (\Throwable $e) {
            Log::error('No se pudo revertir el ítem ' . $item->id . ': ' . $e->getMessage());

            $item->update([
                'conflict_reason' => mb_substr('No se pudo revertir: ' . $e->getMessage(), 0, 1000),
            ]);

            $this->recorder->registrar(
                $reversion,
                $item->subject,
                $item->label,
                estado: MassActionItemStatus::Error,
                mensaje: $e->getMessage(),
            );
        }
    }

    /**
     * Cierra las dos acciones con el resultado real.
     *
     * La original queda REVERTIDA solo si no quedó nada pendiente. Si
     * hubo conflictos, queda como reversión PARCIAL: es información, no
     * un fracaso, y además deja la puerta abierta a reintentar cuando
     * se resuelva lo que estorbaba.
     */
    private function cerrar(MassAction $accion, MassAction $reversion): void
    {
        $reversion->refresh();

        $quedanSinRevertir = $accion->items()
            ->where('status', MassActionItemStatus::Ok->value)
            ->whereNull('reverted_at')
            ->exists();

        $estadoFinal = $quedanSinRevertir
            ? MassActionStatus::ReversionParcial
            : MassActionStatus::Revertida;

        $reversion->update([
            'status' => $reversion->failed_items > 0 || $reversion->conflict_items > 0
                ? MassActionStatus::CompletadaConErrores
                : MassActionStatus::Completada,
            'finished_at' => now(),
        ]);

        $accion->update([
            'status' => $estadoFinal,
            // Si quedó a medias, se permite reintentar: el enlace a la
            // reversión se conserva en el resumen, pero el candado se
            // suelta para que los ítems en conflicto se puedan
            // reintentar cuando se resuelva lo que estorbaba.
            'reverted_by_mass_action_id' => $estadoFinal === MassActionStatus::Revertida
                ? $reversion->id
                : null,
            'reverted_items' => $accion->items()->whereNotNull('reverted_at')->count(),
            // Los que quedaron sin revertir por un conflicto: siguen
            // siendo «ok» —la acción sí los cambió— pero llevan el
            // motivo por el que hoy no se pudieron deshacer.
            'conflict_items' => $accion->items()
                ->where('status', MassActionItemStatus::Ok->value)
                ->whereNull('reverted_at')
                ->whereNotNull('conflict_reason')
                ->count(),
        ]);

        $this->auditoria->action(
            'mass_actions.reverted',
            sprintf(
                'Revirtió la acción masiva #%d: %d de %d registro(s)',
                $accion->id,
                $reversion->reverted_items,
                $accion->ok_items,
            ),
            [
                'accion' => $accion->id,
                'reversion' => $reversion->id,
                'revertidos' => $reversion->reverted_items,
                'conflictos' => $reversion->conflict_items,
                'estado' => $estadoFinal->value,
            ],
            $accion,
            'sistema',
        );
    }
}
