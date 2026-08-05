<?php

namespace App\Services;

use App\Models\CableStrand;
use App\Models\FiberCable;
use App\Models\OpticalNetwork;
use App\Models\Splice;
use App\Models\SpliceClosure;
use App\Models\Splitter;
use App\Models\SplitterOutput;
use App\Services\Audit\AuditLogger;
use App\Support\FiberColors;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operaciones de la planta de fibra que tocan más de una tabla.
 *
 * Aquí vive todo lo que no puede quedar a medias: crear un cable con
 * sus hilos, fusionar dos hilos, montar un splitter con sus salidas.
 * Todo va en transacción y todo queda en la trazabilidad, porque una
 * fusión mal anotada manda a un técnico a abrir la mufla equivocada.
 *
 * Las reglas que se defienden aquí son las que evitan documentar cosas
 * físicamente imposibles: un hilo fusionado dos veces en la misma
 * mufla, una fusión de un hilo consigo mismo, o un splitter cuya
 * entrada ya está ocupada por otra cosa.
 */
class FiberPlantManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    // ==================== Cables ====================

    /**
     * Crea el cable y genera sus hilos.
     *
     * Los hilos se generan aquí y no a mano porque su numeración y sus
     * colores salen de la norma, no del criterio de quien captura: un
     * cable de 48 con 4 buffers de 12 SIEMPRE tiene el hilo 14 en el
     * buffer naranja, posición naranja.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearCable(OpticalNetwork $red, array $datos): FiberCable
    {
        $this->validarReparto(
            (int) $datos['fiber_count'],
            (int) $datos['buffer_count'],
            (int) $datos['fibers_per_buffer'],
        );

        return DB::transaction(function () use ($red, $datos) {
            $cable = FiberCable::create(array_merge($datos, [
                'optical_network_id' => $red->id,
                'user_id' => auth()->id(),
            ]));

            $this->generarHilos($cable);

            $this->auditLogger->action(
                'fiber_cables.created',
                sprintf(
                    'Registró el cable %s de %s, de %s a %s',
                    $cable->code,
                    $cable->capacidad_legible,
                    $cable->desde_legible,
                    $cable->hasta_legible,
                ),
                [
                    'cable' => $cable->code,
                    'red' => $red->name,
                    'hilos' => $cable->fiber_count,
                    'buffers' => $cable->buffer_count,
                    'desde' => $cable->desde_legible,
                    'hasta' => $cable->hasta_legible,
                ],
                $cable,
                'red',
            );

            return $cable;
        });
    }

    /**
     * Crea las filas de hilo que falten.
     *
     * Es idempotente: se puede volver a llamar sin duplicar nada.
     */
    public function generarHilos(FiberCable $cable): void
    {
        $existentes = $cable->strands()->pluck('number')->all();
        $nuevos = [];
        $ahora = now();

        for ($numero = 1; $numero <= $cable->fiber_count; $numero++) {
            if (in_array($numero, $existentes, true)) {
                continue;
            }

            $posicion = FiberColors::posicionDe($numero, $cable->fibers_per_buffer);

            $nuevos[] = [
                'fiber_cable_id' => $cable->id,
                'number' => $numero,
                'buffer_number' => $posicion['buffer'],
                'buffer_color' => $posicion['buffer_color'],
                'strand_number' => $posicion['hilo'],
                'strand_color' => $posicion['hilo_color'],
                'status' => CableStrand::LIBRE,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        if ($nuevos !== []) {
            // De golpe: un cable de 288 hilos son 288 inserciones que
            // no tiene sentido hacer una por una.
            CableStrand::insert($nuevos);
        }
    }

    /**
     * El reparto tiene que cuadrar.
     *
     * Un cable de 48 hilos con 4 buffers de 10 no existe. Dejarlo pasar
     * generaría 48 hilos con posiciones que no se corresponden con el
     * cable real, y el técnico buscaría un color que no está ahí.
     */
    private function validarReparto(int $hilos, int $buffers, int $porBuffer): void
    {
        if ($buffers * $porBuffer !== $hilos) {
            throw new RuntimeException(sprintf(
                'El reparto no cuadra: %d buffer(s) de %d hilos son %d, no %d.',
                $buffers,
                $porBuffer,
                $buffers * $porBuffer,
                $hilos,
            ));
        }
    }

    // ==================== Fusiones ====================

    /**
     * Fusiona dos hilos dentro de una mufla.
     *
     * @param  array<string, mixed>  $datos  bandeja, posición, tipo, atenuación
     */
    public function fusionar(SpliceClosure $mufla, CableStrand $a, CableStrand $b, array $datos = []): Splice
    {
        if ($a->id === $b->id) {
            throw new RuntimeException('No se puede fusionar un hilo consigo mismo.');
        }

        foreach ([$a, $b] as $hilo) {
            if ($hilo->status === CableStrand::DANADO) {
                throw new RuntimeException(
                    "El hilo {$hilo->etiqueta_completa} está marcado como dañado."
                );
            }

            // Un hilo llega a la mufla por UN extremo: si ya está
            // fusionado aquí, la fusión nueva sería físicamente
            // imposible. Fuera de esta mufla sí puede estar fusionado,
            // que es justo lo que hace un tramo de paso.
            $yaFusionado = Splice::where('splice_closure_id', $mufla->id)
                ->where(fn ($q) => $q->where('strand_a_id', $hilo->id)->orWhere('strand_b_id', $hilo->id))
                ->exists();

            if ($yaFusionado) {
                throw new RuntimeException(
                    "El hilo {$hilo->etiqueta_completa} ya está fusionado en la mufla {$mufla->code}."
                );
            }
        }

        $ocupacion = $mufla->ocupacion();

        if ($ocupacion['libres'] < 1) {
            throw new RuntimeException(sprintf(
                'La mufla %s no tiene espacio: %d de %d fusiones ocupadas.',
                $mufla->code,
                $ocupacion['usadas'],
                $ocupacion['capacidad'],
            ));
        }

        // El par SIEMPRE con el id menor primero: si no, «A con B» y
        // «B con A» serían dos filas distintas para la misma fusión y
        // el índice único no podría impedirlo.
        [$primero, $segundo] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        $fusion = DB::transaction(fn () => Splice::create(array_merge($datos, [
            'splice_closure_id' => $mufla->id,
            'strand_a_id' => $primero->id,
            'strand_b_id' => $segundo->id,
            'user_id' => auth()->id(),
        ])));

        $this->auditLogger->action(
            'splices.created',
            sprintf(
                'Fusionó %s con %s en la mufla %s',
                $a->etiqueta_completa,
                $b->etiqueta_completa,
                $mufla->code,
            ),
            [
                'mufla' => $mufla->code,
                'hilo_a' => $a->etiqueta_completa,
                'hilo_b' => $b->etiqueta_completa,
                'bandeja' => $datos['tray'] ?? null,
                'atenuacion_db' => $datos['loss_db'] ?? null,
            ],
            $fusion,
            'red',
        );

        return $fusion;
    }

    /** Deshace una fusión. */
    public function deshacerFusion(Splice $fusion): void
    {
        $fusion->loadMissing('closure', 'strandA.cable', 'strandB.cable');

        $descripcion = sprintf(
            'Deshizo la fusión de %s con %s en la mufla %s',
            $fusion->strandA?->etiqueta_completa ?? 'hilo eliminado',
            $fusion->strandB?->etiqueta_completa ?? 'hilo eliminado',
            $fusion->closure?->code ?? '—',
        );

        $datos = [
            'mufla' => $fusion->closure?->code,
            'hilo_a' => $fusion->strandA?->etiqueta_completa,
            'hilo_b' => $fusion->strandB?->etiqueta_completa,
        ];

        $fusion->delete();

        $this->auditLogger->action('splices.deleted', $descripcion, $datos, null, 'red');
    }

    // ==================== Splitters ====================

    /**
     * Monta un splitter en una mufla y crea sus salidas.
     *
     * @param  array<string, mixed>  $datos
     */
    public function montarSplitter(SpliceClosure $mufla, array $datos): Splitter
    {
        $salidas = Splitter::salidasDe($datos['ratio'] ?? '');

        if ($salidas < 2) {
            throw new RuntimeException('El reparto del splitter no es válido.');
        }

        if (!empty($datos['input_strand_id'])) {
            $entrada = CableStrand::findOrFail($datos['input_strand_id']);

            if (!$entrada->estaDisponible()) {
                throw new RuntimeException(
                    "El hilo de entrada {$entrada->etiqueta_completa} no está libre: {$entrada->estado_legible}."
                );
            }
        }

        return DB::transaction(function () use ($mufla, $datos, $salidas) {
            $splitter = Splitter::create(array_merge($datos, [
                'splice_closure_id' => $mufla->id,
                'output_count' => $salidas,
                'user_id' => auth()->id(),
            ]));

            // Las salidas se crean todas, conectadas o no: poder ver
            // las libres es lo que se pregunta al planear una
            // derivación.
            $filas = [];
            $ahora = now();

            for ($numero = 1; $numero <= $salidas; $numero++) {
                $filas[] = [
                    'splitter_id' => $splitter->id,
                    'number' => $numero,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            SplitterOutput::insert($filas);

            $this->auditLogger->action(
                'splitters.created',
                sprintf('Montó un splitter %s en la mufla %s', $splitter->ratio, $mufla->code),
                [
                    'mufla' => $mufla->code,
                    'ratio' => $splitter->ratio,
                    'entrada' => $splitter->inputStrand?->etiqueta_completa,
                ],
                $splitter,
                'red',
            );

            return $splitter;
        });
    }

    /**
     * Quita un splitter de la mufla.
     *
     * Se lleva sus salidas por delante (la clave foránea las borra en
     * cascada), y con ellas la conexión de los hilos que colgaban. Eso
     * es justo lo que pasa en la realidad al desmontarlo: los hilos
     * quedan sueltos dentro de la mufla, no desaparecen.
     *
     * Se avisa de a cuántos clientes afecta ANTES de borrar, en el
     * mensaje de la trazabilidad: desmontar un splitter 1:8 puede dejar
     * ocho cajas sin camino, y quien lo hizo tiene que quedar anotado.
     */
    public function desmontarSplitter(Splitter $splitter): void
    {
        $splitter->loadMissing('closure', 'inputStrand.cable', 'outputs.strand.cable');

        $conectadas = $splitter->outputs->filter(fn (SplitterOutput $s) => $s->strand_id !== null);

        $descripcion = sprintf(
            'Desmontó el splitter %s de la mufla %s%s',
            $splitter->ratio,
            $splitter->closure?->code ?? '—',
            $conectadas->isNotEmpty()
                ? ' (tenía ' . $conectadas->count() . ' salida(s) conectadas)'
                : '',
        );

        $datos = [
            'mufla' => $splitter->closure?->code,
            'ratio' => $splitter->ratio,
            'codigo' => $splitter->code,
            'entrada' => $splitter->inputStrand?->etiqueta_completa,
            'salidas_conectadas' => $conectadas
                ->map(fn (SplitterOutput $s) => $s->strand?->etiqueta_completa)
                ->filter()
                ->values()
                ->all(),
        ];

        $splitter->delete();

        $this->auditLogger->action('splitters.deleted', $descripcion, $datos, null, 'red');
    }

    /** Conecta (o desconecta) una salida de splitter a un hilo. */
    public function conectarSalida(SplitterOutput $salida, ?CableStrand $hilo): void
    {
        $salida->loadMissing('splitter.closure');

        if ($hilo && !$hilo->estaDisponible()) {
            throw new RuntimeException(
                "El hilo {$hilo->etiqueta_completa} no está libre: {$hilo->estado_legible}."
            );
        }

        $anterior = $salida->strand;

        $salida->update(['strand_id' => $hilo?->id]);

        $this->auditLogger->action(
            $hilo ? 'splitters.output_connected' : 'splitters.output_released',
            $hilo
                ? sprintf(
                    'Conectó la salida %d del splitter %s a %s',
                    $salida->number,
                    $salida->splitter->ratio,
                    $hilo->etiqueta_completa,
                )
                : sprintf(
                    'Liberó la salida %d del splitter %s%s',
                    $salida->number,
                    $salida->splitter->ratio,
                    $anterior ? ' (estaba en ' . $anterior->etiqueta_completa . ')' : '',
                ),
            [
                'mufla' => $salida->splitter->closure?->code,
                'salida' => $salida->number,
                'hilo' => $hilo?->etiqueta_completa,
                'hilo_anterior' => $anterior?->etiqueta_completa,
            ],
            $salida->splitter,
            'red',
        );
    }

    // ==================== Muflas ====================

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearMufla(OpticalNetwork $red, array $datos): SpliceClosure
    {
        $mufla = SpliceClosure::create(array_merge($datos, [
            'optical_network_id' => $red->id,
            'user_id' => auth()->id(),
        ]));

        $this->auditLogger->action(
            'splice_closures.created',
            sprintf(
                'Registró la mufla %s (%s) en %s',
                $mufla->code,
                $mufla->tipo_legible,
                $mufla->address,
            ),
            [
                'mufla' => $mufla->code,
                'red' => $red->name,
                'tipo' => $mufla->type,
                'capacidad' => $mufla->capacidadFusiones(),
                'coordenadas' => "{$mufla->latitude}, {$mufla->longitude}",
            ],
            $mufla,
            'red',
        );

        return $mufla;
    }

    /** Conecta el hilo que alimenta una caja NAP. */
    public function alimentarCaja(\App\Models\NapBox $caja, ?CableStrand $hilo): void
    {
        if ($hilo && !$hilo->estaDisponible() && (int) $caja->feed_strand_id !== (int) $hilo->id) {
            throw new RuntimeException(
                "El hilo {$hilo->etiqueta_completa} no está libre: {$hilo->estado_legible}."
            );
        }

        $anterior = $caja->feedStrand;

        $caja->update(['feed_strand_id' => $hilo?->id]);

        $this->auditLogger->action(
            'naps.feed_changed',
            $hilo
                ? sprintf('La caja %s pasa a alimentarse de %s', $caja->code, $hilo->etiqueta_completa)
                : sprintf('Se quitó el hilo que alimentaba la caja %s', $caja->code),
            [
                'caja' => $caja->code,
                'hilo' => $hilo?->etiqueta_completa,
                'hilo_anterior' => $anterior?->etiqueta_completa,
            ],
            $caja,
            'red',
        );
    }
}
