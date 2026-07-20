<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Models\Ont;
use App\Models\OntImportRun;
use App\Services\OltOntDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importa a GestISP las ONTs que ya existen en una OLT.
 *
 * Corre en segundo plano porque una OLT grande puede tener miles
 * de ONTs: hacerlo dentro de la petición web dejaría al usuario
 * esperando y agotaría el tiempo límite del servidor.
 *
 * Cuidados para no saturar nada:
 *  - El inventario se lee con recorridos SNMP masivos (cuatro
 *    consultas para toda la OLT, no una por ONT).
 *  - La escritura va por lotes dentro de transacciones cortas, en
 *    vez de una transacción gigante que bloquearía la tabla.
 *  - El avance se publica en ont_import_runs para que la pantalla
 *    informe al usuario sin consultar a la OLT.
 *
 * Nunca modifica ONTs ya registradas: las existentes se cuentan
 * como omitidas y se dejan intactas.
 */
class ImportOltOnts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ONTs que se escriben por lote */
    private const TAMANO_LOTE = 100;

    /** Una importación larga no debe reintentarse sola */
    public int $tries = 1;

    public int $timeout = 900; // 15 minutos

    public function __construct(
        public readonly int $runId,
    ) {
    }

    public function handle(OltOntDiscovery $discovery): void
    {
        $run = OntImportRun::find($this->runId);

        if (!$run) {
            return;
        }

        $olt = Olt::find($run->olt_id);

        if (!$olt) {
            $this->fallar($run, 'La OLT ya no existe.');

            return;
        }

        $run->update([
            'status' => OntImportRun::ESTADO_EJECUTANDO,
            'started_at' => now(),
            'message' => 'Leyendo el inventario de la OLT...',
        ]);

        try {
            $encontradas = $discovery->discover($olt);
        } catch (Throwable $e) {
            $this->fallar($run, $e->getMessage());

            return;
        }

        $run->update([
            'total_found' => $encontradas->count(),
            'message' => "Se encontraron {$encontradas->count()} ONTs en la OLT. Importando...",
        ]);

        // Seriales ya registrados: se comparan en memoria para no
        // consultar la base de datos una vez por ONT
        $existentes = Ont::where('olt_id', $olt->id)
            ->pluck('sn')
            ->map(fn ($sn) => strtoupper(trim($sn)))
            ->flip();

        $contadores = [
            'processed' => 0,
            'imported' => 0,
            'skipped_existing' => 0,
            'skipped_invalid' => 0,
            'matched_contracts' => 0,
        ];

        foreach ($encontradas->chunk(self::TAMANO_LOTE) as $lote) {
            try {
                DB::transaction(function () use ($lote, $olt, $existentes, $discovery, &$contadores) {
                    foreach ($lote as $datos) {
                        $contadores['processed']++;

                        $sn = strtoupper(trim($datos['sn']));

                        // Ya registrada: no se toca
                        if ($existentes->has($sn)) {
                            $contadores['skipped_existing']++;
                            continue;
                        }

                        // Sin ubicación no se puede operar la ONT
                        // (no se sabría en qué puerto está)
                        if ($datos['slot'] === null || $datos['port'] === null) {
                            $contadores['skipped_invalid']++;
                            continue;
                        }

                        $contractId = $discovery->matchContract($datos['description'], $olt->branch_id);

                        if ($contractId) {
                            $contadores['matched_contracts']++;
                        }

                        Ont::create([
                            'branch_id' => $olt->branch_id,
                            'olt_id' => $olt->id,
                            'contract_id' => $contractId,
                            'slot' => $datos['slot'],
                            'port' => $datos['port'],
                            'onu_id' => $datos['onu_id'],
                            'if_index' => $datos['if_index'],
                            'sn' => $datos['sn'],
                            'description' => $datos['description'] ?: null,
                            'status' => $datos['online'] ? 1 : 0,
                            'admin_enabled' => true,
                        ]);

                        // Evita duplicados si la OLT reportara el
                        // mismo serial dos veces
                        $existentes->put($sn, true);
                        $contadores['imported']++;
                    }
                });
            } catch (Throwable $e) {
                Log::error('Error importando un lote de ONTs', [
                    'olt' => $olt->name,
                    'error' => $e->getMessage(),
                ]);

                // Un lote con problemas no aborta la importación
                $contadores['skipped_invalid'] += $lote->count();
            }

            // Publicar el avance para la barra de progreso
            $run->update($contadores);
        }

        $run->update(array_merge($contadores, [
            'status' => OntImportRun::ESTADO_COMPLETADO,
            'finished_at' => now(),
            'message' => $this->resumen($contadores),
        ]));

        Log::info('Importación de ONTs completada', [
            'olt' => $olt->name,
            'resultado' => $contadores,
        ]);
    }

    /**
     * Mensaje final que verá el usuario.
     */
    private function resumen(array $c): string
    {
        $partes = ["Importadas: {$c['imported']}"];

        if ($c['matched_contracts'] > 0) {
            $partes[] = "asignadas a un contrato: {$c['matched_contracts']}";
        }

        if ($c['skipped_existing'] > 0) {
            $partes[] = "ya registradas: {$c['skipped_existing']}";
        }

        if ($c['skipped_invalid'] > 0) {
            $partes[] = "omitidas por datos incompletos: {$c['skipped_invalid']}";
        }

        return ucfirst(implode(' · ', $partes)) . '.';
    }

    private function fallar(OntImportRun $run, string $mensaje): void
    {
        $run->update([
            'status' => OntImportRun::ESTADO_FALLIDO,
            'finished_at' => now(),
            'message' => $mensaje,
        ]);

        Log::error('Importación de ONTs fallida', ['run' => $run->id, 'error' => $mensaje]);
    }

    /**
     * Si el job muere por una excepción no controlada, la corrida
     * no debe quedar "en ejecución" para siempre.
     */
    public function failed(Throwable $e): void
    {
        $run = OntImportRun::find($this->runId);

        if ($run && $run->enCurso()) {
            $this->fallar($run, 'El proceso se interrumpió: ' . $e->getMessage());
        }
    }
}
