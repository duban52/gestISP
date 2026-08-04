<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\Ont;
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

        $bandas = [
            'saturacion' => [
                'etiqueta' => 'Saturación', 'color' => 'danger', 'rango' => 'mayor a −8 dBm',
                'cantidad' => $conPotencia->filter(fn ($o) => (float) $o->rx_power > self::SATURACION)->count(),
            ],
            'optima' => [
                'etiqueta' => 'Óptima', 'color' => 'success', 'rango' => '−8 a −22 dBm',
                'cantidad' => $conPotencia->filter(fn ($o) => (float) $o->rx_power <= self::SATURACION
                    && (float) $o->rx_power > self::OPTIMA_MIN)->count(),
            ],
            'aceptable' => [
                'etiqueta' => 'Aceptable', 'color' => 'info', 'rango' => '−22 a −25 dBm',
                'cantidad' => $conPotencia->filter(fn ($o) => (float) $o->rx_power <= self::OPTIMA_MIN
                    && (float) $o->rx_power > self::ACEPTABLE_MIN)->count(),
            ],
            'debil' => [
                'etiqueta' => 'Débil', 'color' => 'warning', 'rango' => '−25 a −27 dBm',
                'cantidad' => $conPotencia->filter(fn ($o) => (float) $o->rx_power <= self::ACEPTABLE_MIN
                    && (float) $o->rx_power > self::DEBIL_MIN)->count(),
            ],
            'critica' => [
                'etiqueta' => 'Crítica', 'color' => 'danger', 'rango' => 'menor a −27 dBm',
                'cantidad' => $conPotencia->filter(fn ($o) => (float) $o->rx_power <= self::DEBIL_MIN)->count(),
            ],
        ];

        return $bandas;
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
