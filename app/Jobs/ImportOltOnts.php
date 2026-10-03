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

    /**
     * Cuántas ONT omitidas se explican con nombre y apellido.
     *
     * Con mil ilegibles, guardar las mil no ayuda más que guardar las
     * primeras doscientas y sí hincha la fila. El contador sigue
     * contándolas todas.
     */
    private const LIMITE_DETALLES = 200;

    /** El porqué de cada ONT que no entró. @var array<int, array{sn: string, ubicacion: string, motivo: string}> */
    private array $omitidas = [];

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

        $this->omitidas = [];

        // LA ACCIÓN MASIVA. Se abre fuera del bucle: los lotes son
        // una conveniencia de la transacción, no operaciones distintas.
        $accion = app(\App\MassActions\MassActionRecorder::class)->abrir(
            \App\MassActions\Enums\MassActionType::ImportacionDeOnts,
            sprintf('Importación de ONTs desde la OLT %s: %d encontrada(s)', $olt->name, $encontradas->count()),
            summary: ['olt' => $olt->name, 'encontradas' => $encontradas->count()],
            source: $run,
            branchId: $olt->branch_id,
            userId: $run->user_id,
        );

        foreach ($encontradas->chunk(self::TAMANO_LOTE) as $lote) {
            // LA FOTO DE ANTES DEL LOTE.
            //
            // Si la transaccion revienta, la base vuelve atras pero
            // estos dos no: son memoria de PHP. Sin restaurarlos, el
            // reintento daba por importadas filas que el ROLLBACK se
            // habia llevado —el contador decia 2 y en la base habia 1—.
            $contadoresAntes = $contadores;
            $vistosAntes = clone $existentes;

            try {
                DB::transaction(function () use ($lote, $olt, $existentes, $discovery, &$contadores, $accion) {
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
                            $this->anotarOmitida(
                                $datos,
                                $datos['slot'] === null && $datos['port'] === null
                                    ? 'La OLT no reportó ni la tarjeta ni el puerto PON de esta ONT.'
                                    : ($datos['slot'] === null
                                        ? 'La OLT no reportó la tarjeta (slot) de esta ONT.'
                                        : 'La OLT no reportó el puerto PON de esta ONT.'),
                            );
                            continue;
                        }

                        $contractId = $discovery->matchContract($datos['description'], $olt->branch_id);

                        if ($contractId) {
                            $contadores['matched_contracts']++;
                        }

                        $ont = Ont::create([
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

                        // Lo que hace falta para deshacerlo: cuál ONT
                        // trajo esta importación y con qué contrato
                        // —si lo casó ella—, para saber después si
                        // alguien la vinculó a mano.
                        app(\App\MassActions\MassActionRecorder::class)->registrar(
                            $accion,
                            $ont,
                            $ont->sn,
                            antes: ['existia' => false],
                            despues: [
                                'contract_id' => $contractId,
                                'ubicacion' => $datos['slot'] . '/' . $datos['port'] . '/' . $datos['onu_id'],
                            ],
                        );

                        // Evita duplicados si la OLT reportara el
                        // mismo serial dos veces
                        $existentes->put($sn, true);
                        $contadores['imported']++;
                    }
                });
            } catch (Throwable $e) {
                Log::error('Error importando un lote de ONTs; se reintenta una a una', [
                    'olt' => $olt->name,
                    'error' => $e->getMessage(),
                ]);

                // EL LOTE ENTERO NO PUEDE CAER POR UNA ONT.
                //
                // Antes esto sumaba las cien al contador de «datos
                // incompletos» y seguía: por una sola fila con un
                // problema se perdían noventa y nueve buenas, y el
                // usuario leía «200 omitidas por datos incompletos»
                // —dos lotes— sin que a ninguna le faltara un dato.
                // El motivo real solo quedaba en el log del servidor.
                //
                // Ahora se reintenta registro a registro: entran las
                // que pueden, y la que falla dice por qué.
                $contadores = $contadoresAntes;
                $existentes = $vistosAntes;

                $this->reintentarUnaAUna($lote, $olt, $existentes, $discovery, $contadores, $accion);
            }

            // Publicar el avance para la barra de progreso
            $run->update($contadores + ['skipped_details' => $this->omitidas]);
        }

        app(\App\MassActions\MassActionRecorder::class)->cerrar($accion);

        $run->update(array_merge($contadores, [
            'status' => OntImportRun::ESTADO_COMPLETADO,
            'finished_at' => now(),
            'message' => $this->resumen($contadores),
            'skipped_details' => $this->omitidas,
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
            // «Datos incompletos» era mentira la mitad de las veces:
            // ahí caía también el lote que reventaba por otra causa.
            // Ahora se dice el total y el detalle está en la pantalla.
            $partes[] = "no importadas: {$c['skipped_invalid']} (abajo se explica cada una)";
        }

        return ucfirst(implode(' · ', $partes)) . '.';
    }

    /**
     * Reintenta un lote caído registro a registro.
     *
     * Cada ONT en su propia transacción: la que falle se queda sola
     * con su motivo y las demás entran.
     */
    private function reintentarUnaAUna(
        $lote,
        Olt $olt,
        $existentes,
        OltOntDiscovery $discovery,
        array &$contadores,
        $accion,
    ): void {
        foreach ($lote as $datos) {
            $sn = strtoupper(trim($datos['sn'] ?? ''));

            if ($sn === '' || $existentes->has($sn)) {
                continue;
            }

            try {
                DB::transaction(function () use ($datos, $olt, $existentes, $discovery, &$contadores, $accion, $sn) {
                    $contractId = $discovery->matchContract($datos['description'], $olt->branch_id);

                    $ont = Ont::create([
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

                    app(\App\MassActions\MassActionRecorder::class)->registrar(
                        $accion,
                        $ont,
                        $ont->sn,
                        antes: ['existia' => false],
                        despues: [
                            'contract_id' => $contractId,
                            'ubicacion' => $datos['slot'] . '/' . $datos['port'] . '/' . $datos['onu_id'],
                        ],
                    );

                    if ($contractId) {
                        $contadores['matched_contracts']++;
                    }

                    $existentes->put($sn, true);
                    $contadores['imported']++;
                });
            } catch (Throwable $e) {
                $contadores['skipped_invalid']++;
                $this->anotarOmitida($datos, $this->enClaro($e));
            }
        }
    }

    /**
     * Deja constancia de una ONT que no entró, con su motivo.
     */
    private function anotarOmitida(array $datos, string $motivo): void
    {
        if (count($this->omitidas) >= self::LIMITE_DETALLES) {
            return;
        }

        $this->omitidas[] = [
            'sn' => $datos['sn'] ?? '(sin serial)',
            'ubicacion' => implode('/', array_map(
                fn ($v) => $v === null ? '?' : $v,
                [$datos['slot'] ?? null, $datos['port'] ?? null, $datos['onu_id'] ?? null],
            )),
            'descripcion' => $datos['description'] ?? null,
            'motivo' => $motivo,
        ];
    }

    /**
     * El error del motor, en algo que se pueda leer y corregir.
     *
     * «SQLSTATE[23000]: Integrity constraint violation: 1062
     * Duplicate entry...» no le dice nada a quien está importando.
     */
    private function enClaro(Throwable $e): string
    {
        $mensaje = $e->getMessage();

        if (str_contains($mensaje, '1062') || str_contains($mensaje, 'Duplicate entry')) {
            return 'Ya existe otra ONT con ese serial o en esa misma posición de la OLT.';
        }

        if (str_contains($mensaje, '1452') || str_contains($mensaje, 'foreign key')) {
            return 'Apunta a un contrato, una OLT o una sucursal que ya no existe.';
        }

        if (str_contains($mensaje, 'Data too long')) {
            return 'Algún dato que reportó la OLT es más largo de lo que cabe en el sistema.';
        }

        if (str_contains($mensaje, 'cannot be null') || str_contains($mensaje, '1048')) {
            return 'Le falta un dato obligatorio que la OLT no reportó.';
        }

        // Lo que no se reconozca va tal cual: peor que un mensaje feo
        // es un «error desconocido» que no se puede investigar.
        return \Illuminate\Support\Str::limit($mensaje, 200);
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
