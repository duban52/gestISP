<?php

namespace App\MassActions;

use App\MassActions\Enums\MassActionItemStatus;
use App\MassActions\Enums\MassActionStatus;
use App\MassActions\Enums\MassActionType;
use App\Models\MassAction;
use App\Models\MassActionItem;
use App\Services\Audit\AuditLogger;
use App\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Por donde pasa TODO lo que se registra de una operación masiva.
 *
 * Es el equivalente de `AuditLogger` para los procesos que tocan
 * muchos registros, y sigue su mismo principio: **esto no puede tumbar
 * la operación**. Si el registro falla, se anota en el log y el corte,
 * la importación o la corrida siguen su camino. Una bitácora rota no
 * puede impedir que se corte a un moroso.
 *
 * CÓMO SE USA, DESDE CUALQUIER PROCESO
 * ------------------------------------
 *     $accion = $recorder->abrir(
 *         MassActionType::CorteDeContratos,
 *         'Corte masivo de 42 contratos por mora',
 *         summary: ['umbral' => 2],
 *         source: $tanda,
 *     );
 *
 *     foreach (...) {
 *         $recorder->registrar($accion, $contrato, 'EGP000123',
 *             antes: ['status' => 'Activo'],
 *             despues: ['status' => 'Suspendido'],
 *         );
 *     }
 *
 *     $recorder->cerrar($accion);
 *
 * El proceso no cambia su firma ni lo que devuelve: solo avisa.
 *
 * LOS CONTADORES SE LLEVAN AL VUELO
 * ---------------------------------
 * Y no contando los ítems al final. El historial lista decenas de
 * acciones, y contar ítems por fila sería una consulta por fila; con
 * cincuenta mil ítems, además, la cuenta final tarda más que la propia
 * operación.
 */
class MassActionRecorder
{
    public function __construct(
        private readonly AuditLogger $auditoria,
    ) {
    }

    /**
     * Abre una acción masiva y la deja lista para recibir ítems.
     *
     * @param  array<string, mixed>  $summary  Lo propio del proceso
     * @param  Model|null  $source  Su registro propio, si lo tiene
     */
    public function abrir(
        MassActionType $tipo,
        string $descripcion,
        array $summary = [],
        ?Model $source = null,
        ?int $branchId = null,
        ?int $userId = null,
    ): ?MassAction {
        try {
            $contexto = app(CurrentContext::class);

            $accion = MassAction::create([
                'type' => $tipo,
                'status' => MassActionStatus::Procesando,
                'description' => mb_substr($descripcion, 0, 500),
                'user_id' => $userId ?? Auth::id(),
                'branch_id' => $branchId ?? $contexto->branchId(),
                'company_id' => $contexto->companyId(),
                'summary' => $summary ?: null,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'request_id' => AuditLogger::requestId(),
                'started_at' => now(),
            ]);

            $this->auditoria->action(
                'mass_actions.started',
                'Inició ' . $tipo->label() . ': ' . $descripcion,
                ['accion' => $accion->id, 'tipo' => $tipo->value],
                $accion,
                'sistema',
            );

            return $accion;
        } catch (\Throwable $e) {
            // Igual que la auditoría: no se tumba la operación por no
            // poder registrarla.
            Log::error('No se pudo abrir la acción masiva: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Anota qué pasó con UN registro.
     *
     * `$antes` y `$despues` llevan SOLO los campos que cambiaron. De
     * `$despues` depende que la reversión sea segura: es con lo que se
     * compara el estado actual antes de tocar nada.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     */
    public function registrar(
        ?MassAction $accion,
        ?Model $sujeto,
        ?string $etiqueta = null,
        array $antes = [],
        array $despues = [],
        MassActionItemStatus $estado = MassActionItemStatus::Ok,
        ?string $mensaje = null,
    ): ?MassActionItem {
        if (!$accion) {
            return null;
        }

        try {
            $item = MassActionItem::create([
                'mass_action_id' => $accion->id,
                'subject_type' => $sujeto ? $sujeto::class : null,
                'subject_id' => $sujeto?->getKey(),
                'label' => $etiqueta ? mb_substr($etiqueta, 0, 150) : null,
                'status' => $estado,
                'message' => $mensaje ? mb_substr($mensaje, 0, 1000) : null,
                'before' => $antes ?: null,
                'after' => $despues ?: null,
            ]);

            $this->sumar($accion, $estado);

            return $item;
        } catch (\Throwable $e) {
            Log::error('No se pudo registrar el ítem de la acción masiva: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Cierra la acción con el estado que le corresponda.
     *
     * Lo decide por los contadores y no por quien la cierra: una
     * operación con quince errores está «completada con errores»
     * aunque el proceso que la lanzó crea que fue bien.
     */
    public function cerrar(?MassAction $accion, ?MassActionStatus $forzado = null): void
    {
        if (!$accion) {
            return;
        }

        try {
            $accion->refresh();

            $estado = $forzado ?? match (true) {
                $accion->failed_items > 0 => MassActionStatus::CompletadaConErrores,
                default => MassActionStatus::Completada,
            };

            $accion->update(['status' => $estado, 'finished_at' => now()]);

            $this->auditoria->action(
                'mass_actions.finished',
                sprintf(
                    'Terminó %s: %d correcto(s), %d omitido(s), %d con error',
                    $accion->type->label(),
                    $accion->ok_items,
                    $accion->skipped_items,
                    $accion->failed_items,
                ),
                [
                    'accion' => $accion->id,
                    'estado' => $estado->value,
                    'total' => $accion->total_items,
                ],
                $accion,
                'sistema',
            );
        } catch (\Throwable $e) {
            Log::error('No se pudo cerrar la acción masiva: ' . $e->getMessage());
        }
    }

    /**
     * Suma el ítem a los contadores de la acción.
     *
     * Con `increment`, que es un UPDATE atómico: una importación en
     * cola puede estar escribiendo desde varios procesos a la vez, y
     * leer-sumar-guardar perdería cuentas por el camino.
     */
    private function sumar(MassAction $accion, MassActionItemStatus $estado): void
    {
        $columna = match ($estado) {
            MassActionItemStatus::Ok => 'ok_items',
            MassActionItemStatus::Omitido => 'skipped_items',
            MassActionItemStatus::Error => 'failed_items',
            MassActionItemStatus::Revertido => 'reverted_items',
            MassActionItemStatus::Conflicto, MassActionItemStatus::NoReversible => 'conflict_items',
            MassActionItemStatus::Pendiente => null,
        };

        $accion->increment('total_items');

        if ($columna) {
            $accion->increment($columna);
        }
    }
}
