<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\OltPortMetric;
use App\Models\Ont;
use App\Models\PonPort;
use Illuminate\Support\Collection;

/**
 * Radiografía de una OLT a partir de lo que ya está en la base.
 *
 * POR QUÉ NO SE CONSULTA EL EQUIPO
 * --------------------------------
 * Todo lo que hay aquí sale de la tabla `onts`, que mantienen al día
 * los comandos `onts:poll` y `onts:sync-power`. Es a propósito: pedirle
 * estos datos a la OLT en cada visita significaría decenas de consultas
 * SNMP y una pantalla que tarda medio minuto en abrir. El estado en
 * vivo del chasis (temperatura, uptime) sí se consulta, pero aparte y
 * en segundo plano.
 *
 * LA POTENCIA ÓPTICA
 * ------------------
 * Es el dato que de verdad importa en una red GPON: predice las fallas
 * antes de que el cliente llame. Se mide en dBm en el receptor de la
 * ONT y siempre es negativa; cuanto más cerca de cero, más señal.
 *
 * Los rangos usados corresponden a ópticas clase B+/C+, que son las
 * habituales en las OLT Huawei:
 *
 *   > -8      SATURACIÓN. Demasiada luz; el receptor se ciega y
 *             puede dañarse. Típico de un cliente muy cerca de la OLT
 *             sin atenuador.
 *   -8 a -22  ÓPTIMA. Es donde debe estar todo el mundo.
 *   -22 a -25 ACEPTABLE. Funciona, pero ya conviene mirarlo.
 *   -25 a -27 DÉBIL. Falla con lluvia o con calor; es la que produce
 *             las quejas de "se me va a ratos".
 *   < -27     CRÍTICA. Al borde de la sensibilidad del receptor
 *             (-28 dBm): el enlace se cae solo.
 */
class OltStatistics
{
    /** Umbrales de potencia en dBm. Ver la explicación de arriba. */
    public const SATURACION = -8.0;
    public const OPTIMA_MIN = -22.0;
    public const ACEPTABLE_MIN = -25.0;
    public const DEBIL_MIN = -27.0;

    /**
     * Resumen completo de la OLT.
     *
     * @return array<string, mixed>
     */
    public function resumen(Olt $olt): array
    {
        $onts = Ont::where('olt_id', $olt->id)
            ->get(['id', 'status', 'admin_enabled', 'rx_power', 'contract_id', 'slot', 'port', 'updated_at']);

        return [
            'conteos' => $this->conteos($onts),
            'potencia' => $this->potencia($onts),
            'calidad' => $this->calidad($onts),
            'puertos' => $this->puertos($onts),
            'peores' => $this->peores($olt),
        ];
    }

    /**
     * Cuántas ONTs hay y en qué estado.
     *
     * `status` es lo que reporta la OLT (1 = en línea). `admin_enabled`
     * es lo que decidimos nosotros: una ONT deshabilitada a propósito
     * no es una falla, y mezclarlas haría creer que hay un problema de
     * red donde solo hay un corte por facturación.
     *
     * @param  Collection<int, Ont>  $onts
     * @return array<string, int|float>
     */
    private function conteos(Collection $onts): array
    {
        $total = $onts->count();
        $deshabilitadas = $onts->where('admin_enabled', false)->count();

        // Entre las que SÍ deberían estar dando servicio
        $operativas = $onts->where('admin_enabled', '!==', false);
        $enLinea = $onts->filter(fn ($o) => $o->admin_enabled !== false && (int) $o->status === 1)->count();
        $caidas = $operativas->count() - $enLinea;

        return [
            'total' => $total,
            'en_linea' => $enLinea,
            'caidas' => max($caidas, 0),
            'deshabilitadas' => $deshabilitadas,
            'sin_contrato' => $onts->whereNull('contract_id')->count(),
            // Sobre las operativas, no sobre el total: incluir las
            // deshabilitadas hundiría el porcentaje sin que haya falla.
            'disponibilidad' => $operativas->count() > 0
                ? round($enLinea / $operativas->count() * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Promedio, mejor y peor potencia de las ONTs en línea.
     *
     * Solo cuentan las que están en línea: la potencia de una ONT
     * apagada es el último valor que se leyó, y meterla en el promedio
     * lo ensucia con datos viejos.
     *
     * @param  Collection<int, Ont>  $onts
     * @return array<string, float|int|null>
     */
    private function potencia(Collection $onts): array
    {
        $valores = $this->conPotencia($onts)->pluck('rx_power')->map(fn ($v) => (float) $v);

        if ($valores->isEmpty()) {
            return ['promedio' => null, 'mejor' => null, 'peor' => null, 'medidas' => 0];
        }

        return [
            'promedio' => round($valores->avg(), 2),
            // "Mejor" es la más cercana a cero; en negativos, la mayor
            'mejor' => round($valores->max(), 2),
            'peor' => round($valores->min(), 2),
            'medidas' => $valores->count(),
        ];
    }

    /**
     * Reparto de las ONTs por calidad de señal.
     *
     * @param  Collection<int, Ont>  $onts
     * @return array<string, array{etiqueta: string, color: string, rango: string, cantidad: int}>
     */
    private function calidad(Collection $onts): array
    {
        $conPotencia = $this->conPotencia($onts);

        $bandas = [];

        // Se cuenta con bandaDe(), la MISMA función que clasifica una
        // potencia suelta en la ficha de la ONT y en el listado. Antes
        // los rangos estaban escritos otra vez aquí con comparaciones a
        // mano: dos sitios que tenían que decir lo mismo y que se
        // separarían el día que alguien afinara un umbral.
        foreach (self::bandas() as $clave => $definicion) {
            $bandas[$clave] = $definicion + [
                'cantidad' => $conPotencia
                    ->filter(fn (Ont $o) => self::bandaDe((float) $o->rx_power) === $clave)
                    ->count(),
            ];
        }

        return $bandas;
    }

    /**
     * Catálogo de bandas de calidad óptica.
     *
     * Los umbrales son los de una ONT clase B+/C+: el receptor deja de
     * funcionar cerca de −28 dBm, y por encima de −8 se satura. Lo usan
     * la ficha de la OLT, el listado de ONTs y la ficha de cada ONT.
     *
     * @return array<string, array{etiqueta: string, color: string, rango: string}>
     */
    public static function bandas(): array
    {
        return [
            'saturacion' => ['etiqueta' => 'Saturación', 'color' => 'danger', 'rango' => 'mayor a −8 dBm'],
            'optima' => ['etiqueta' => 'Óptima', 'color' => 'success', 'rango' => '−8 a −22 dBm'],
            'aceptable' => ['etiqueta' => 'Aceptable', 'color' => 'info', 'rango' => '−22 a −25 dBm'],
            'debil' => ['etiqueta' => 'Débil', 'color' => 'warning', 'rango' => '−25 a −27 dBm'],
            'critica' => ['etiqueta' => 'Crítica', 'color' => 'danger', 'rango' => 'menor a −27 dBm'],
        ];
    }

    /**
     * Ocupación de cada puerto PON.
     *
     * Un puerto GPON admite hasta 128 ONTs por especificación, pero en
     * la práctica se reparte entre 32 y 64 para no quedarse sin ancho
     * de banda. Ver cuántas cuelgan de cada puerto es lo que dice si
     * hay que balancear antes de seguir instalando.
     *
     * @param  Collection<int, Ont>  $onts
     * @return array<int, array{puerto: string, total: int, en_linea: int}>
     */
    private function puertos(Collection $onts): array
    {
        return $onts
            ->groupBy(fn (Ont $o) => $o->slot . '/' . $o->port)
            ->map(fn (Collection $grupo, string $puerto) => [
                'puerto' => $puerto,
                'total' => $grupo->count(),
                'en_linea' => $grupo->filter(fn ($o) => (int) $o->status === 1)->count(),
            ])
            ->sortBy('puerto')
            ->values()
            ->all();
    }

    /**
     * Bits por segundo en algo que se lea de un vistazo.
     *
     * Se usan múltiplos de 1000 y no de 1024: el ancho de banda de red
     * se mide en unidades decimales (un enlace de 1 Gbps son
     * 1.000.000.000 bits), al contrario que el almacenamiento.
     */
    public static function formatoBps(?int $bps): string
    {
        if ($bps === null) {
            return '—';
        }

        return match (true) {
            $bps >= 1_000_000_000 => round($bps / 1_000_000_000, 2) . ' Gbps',
            $bps >= 1_000_000 => round($bps / 1_000_000, 1) . ' Mbps',
            $bps >= 1_000 => round($bps / 1_000) . ' kbps',
            default => $bps . ' bps',
        };
    }

    /**
     * Cuántas ONTs cuelgan de cada puerto y cuántas están en línea.
     *
     * Se resuelve con UNA agregación en SQL, no trayéndose las ONTs:
     * en una OLT con dos mil ONTs, recorrerlas en PHP para contarlas
     * por puerto es lo que hace que la ficha tarde.
     *
     * @return array<string, array{total: int, online: int}>  ["1/2" => …]
     */
    public function ontsPorPuerto(Olt $olt): array
    {
        $filas = Ont::where('olt_id', $olt->id)
            ->selectRaw('slot, port, COUNT(*) AS total, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS online')
            ->groupBy('slot', 'port')
            ->get();

        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[$fila->slot . '/' . $fila->port] = [
                'total' => (int) $fila->total,
                'online' => (int) $fila->online,
            ];
        }

        return $resultado;
    }

    /**
     * Todo lo que muestra el modal de un puerto PON.
     *
     * Junta en una sola respuesta lo que hay que mirar cuando se
     * sospecha de un puerto: cómo está el puerto en el equipo, cuánto
     * tráfico mueve, cuántos clientes dependen de él, qué cajas cuelgan
     * y cómo va la señal de sus ONTs.
     *
     * @return array<string, mixed>
     */
    public function detalleDePuerto(PonPort $puerto, int $horas = 6): array
    {
        $onts = Ont::where('olt_id', $puerto->olt_id)
            ->where('slot', $puerto->slot)
            ->where('port', $puerto->port)
            ->with('contract.client')
            ->get();

        $enLinea = $onts->filter(fn (Ont $o) => (int) $o->status === 1);
        // conPotencia() devuelve ONTs, no números: rx_power es varchar
        // y hay que convertirlo antes de promediar o comparar.
        $potencias = $this->conPotencia($enLinea)->map(fn (Ont $o) => (float) $o->rx_power);

        // La serie de tráfico. Va acotada en horas y no completa
        // porque es una gráfica de "qué está pasando", no un informe
        // histórico: seis horas cubren el pico de la noche anterior.
        $serie = OltPortMetric::where('port_type', $puerto->getMorphClass())
            ->where('port_id', $puerto->id)
            ->ultimasHoras($horas)
            ->get(['measured_at', 'in_bps', 'out_bps']);

        return [
            'puerto' => [
                'id' => $puerto->id,
                'etiqueta' => $puerto->etiqueta,
                'descripcion' => $puerto->description,
                'zona' => $puerto->zone?->name,
                'splitter' => $puerto->splitter_ratio,
                'max_onts' => $puerto->max_onts,
                'if_index' => $puerto->if_index,
                'estado' => $puerto->estado_legible,
                'color_estado' => $puerto->color_estado,
                'admin_status' => $puerto->admin_status,
                'descubierto' => $puerto->estaDescubierto(),
                'tx_power' => $puerto->tx_power !== null ? (float) $puerto->tx_power : null,
                'in_bps' => $puerto->in_bps,
                'out_bps' => $puerto->out_bps,
                'medido_en' => $puerto->measured_at?->diffForHumans(),
            ],
            'onts' => [
                'total' => $onts->count(),
                'en_linea' => $enLinea->count(),
                'fuera' => $onts->count() - $enLinea->count(),
                // Ocupación contra el tope configurado del puerto: es
                // lo que dice si todavía se puede seguir instalando.
                'ocupacion' => $puerto->max_onts > 0
                    ? round($onts->count() / $puerto->max_onts * 100, 1)
                    : null,
                'potencia_media' => $potencias->isNotEmpty()
                    ? round($potencias->avg(), 2)
                    : null,
                'peor' => $potencias->isNotEmpty() ? round($potencias->min(), 2) : null,
            ],
            'cajas' => $puerto->napBoxes->map(fn ($caja) => [
                'id' => $caja->id,
                'codigo' => $caja->code,
                'nombre' => $caja->name,
                'ocupacion' => $caja->ocupacion(),
                'url' => route('naps.show', $caja->id),
            ])->values(),
            'trafico' => [
                'horas' => $horas,
                'muestras' => $serie->map(fn ($m) => [
                    'momento' => $m->measured_at->format('H:i'),
                    'in' => (int) $m->in_bps,
                    'out' => (int) $m->out_bps,
                ])->values(),
            ],
            // Las peores del puerto, para saltar directo a la ONT
            // problemática sin salir del modal.
            'peores_onts' => $this->peoresDelPuerto($puerto),
        ];
    }

    /**
     * Las cinco ONTs con peor señal de un puerto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function peoresDelPuerto(PonPort $puerto, int $limite = 5): array
    {
        return Ont::where('olt_id', $puerto->olt_id)
            ->where('slot', $puerto->slot)
            ->where('port', $puerto->port)
            ->whereNotNull('rx_power')
            ->where('rx_power', '!=', '')
            ->where('status', 1)
            // rx_power es varchar: sin el CAST se ordena como texto y
            // "-15.0" quedaría antes que "-28.5", al revés de lo real.
            ->orderByRaw('CAST(rx_power AS DECIMAL(10,3)) ASC')
            ->limit($limite)
            ->with('contract.client')
            ->get()
            ->map(fn (Ont $ont) => [
                'id' => $ont->id,
                'sn' => $ont->sn,
                'onu_id' => $ont->onu_id,
                'rx_power' => (float) $ont->rx_power,
                'banda' => self::bandaDe((float) $ont->rx_power),
                'contrato' => $ont->contract?->numero_visible,
                'cliente' => trim(($ont->contract?->client?->name ?? '') . ' ' . ($ont->contract?->client?->last_name ?? '')) ?: null,
                'url' => route('onts.show', $ont->id),
            ])
            ->all();
    }

    /**
     * Las ONTs con peor señal, que son las que hay que ir a revisar.
     *
     * Se limita a diez: es una lista para actuar, no un informe.
     *
     * @return Collection<int, Ont>
     */
    private function peores(Olt $olt, int $limite = 10): Collection
    {
        return Ont::where('olt_id', $olt->id)
            ->whereNotNull('rx_power')
            ->where('rx_power', '!=', '')
            ->where('status', 1)
            // OJO: rx_power es una columna de TEXTO (varchar), así que
            // un orderBy normal compara cadenas y "-15.0" quedaría
            // antes que "-28.5" — justo al revés de lo que se busca.
            // Hay que convertir a número para ordenar de verdad; menor
            // potencia = peor señal, porque los valores son negativos.
            ->orderByRaw('CAST(rx_power AS DECIMAL(10,3)) ASC')
            ->limit($limite)
            ->with('contract.client')
            ->get();
    }

    /**
     * ONTs en línea que además tienen una lectura de potencia.
     *
     * @param  Collection<int, Ont>  $onts
     * @return Collection<int, Ont>
     */
    private function conPotencia(Collection $onts): Collection
    {
        return $onts->filter(
            fn (Ont $o) => $o->rx_power !== null && (int) $o->status === 1
        );
    }

    /**
     * Nombre de la banda de calidad de una potencia concreta.
     * Lo usa la vista de la ONT individual para pintar su badge.
     */
    public static function bandaDe(?float $dbm): ?string
    {
        if ($dbm === null) {
            return null;
        }

        return match (true) {
            $dbm > self::SATURACION => 'saturacion',
            $dbm > self::OPTIMA_MIN => 'optima',
            $dbm > self::ACEPTABLE_MIN => 'aceptable',
            $dbm > self::DEBIL_MIN => 'debil',
            default => 'critica',
        };
    }
}
