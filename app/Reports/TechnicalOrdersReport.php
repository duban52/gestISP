<?php

namespace App\Reports;

use App\Models\TechnicalOrder;
use App\Reports\Support\OrderDetailMap;
use App\Reports\Support\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operación de campo: volumen de órdenes técnicas y rendimiento de
 * los técnicos.
 *
 * Sobre el TIEMPO DE RESOLUCIÓN, que es el indicador más delicado
 * de este informe: la tabla no guarda cuándo se cerró una orden,
 * solo created_at y updated_at. El tiempo se calcula como la
 * diferencia entre ambos en las órdenes ya cerradas, lo que es
 * correcto mientras el cierre sea la última modificación. Si
 * alguien edita una orden cerrada semanas después, esa orden
 * aparecerá como si hubiera tardado más.
 *
 * Es una aproximación útil para comparar técnicos entre sí, pero no
 * un dato contractual. Guardar la fecha de cierre en su propia
 * columna lo volvería exacto.
 */
class TechnicalOrdersReport
{
    private const ESTADO_CERRADA = 'Cerrada';
    private const ESTADO_RECHAZADA = 'Rechazada';

    public function __construct(
        private readonly ReportPeriod $period,
        private readonly ?int $branchId = null,
    ) {
    }

    /**
     * Órdenes creadas y cerradas por período.
     *
     * @return array{labels: Collection, creadas: Collection, cerradas: Collection}
     */
    public function series(): array
    {
        $creadas = $this->period->completarSerie(
            $this->contarPor('technical_orders.created_at')
        );

        $cerradas = $this->period->completarSerie(
            $this->contarPor(
                'technical_orders.updated_at',
                fn ($q) => $q->where('technical_orders.status', self::ESTADO_CERRADA)
            )
        );

        return [
            'labels' => $this->period->etiquetas(),
            'creadas' => $creadas->values(),
            'cerradas' => $cerradas->values(),
        ];
    }

    /**
     * @return Collection<string, int>
     */
    private function contarPor(string $columna, ?callable $filtro = null): Collection
    {
        $bucket = $this->period->sqlBucket($columna);

        return $this->baseQuery()
            ->when($filtro, $filtro)
            ->whereBetween($columna, [$this->period->from, $this->period->to])
            ->selectRaw("{$bucket} as periodo, COUNT(*) as total")
            ->groupBy('periodo')
            ->pluck('total', 'periodo');
    }

    /**
     * Distribución por tipo de orden (Servicio / Incidencia).
     *
     * @return Collection<int, array{etiqueta: string, total: int}>
     */
    public function porTipo(): Collection
    {
        return $this->agrupar('technical_orders.type');
    }

    /**
     * Distribución por el detalle de la orden.
     *
     * Es el desglose que de verdad dice qué se hizo: instalaciones,
     * cortes, reconexiones, traslados, fallas de TV o de internet...
     * El tipo solo separa Servicio de Incidencia.
     *
     * Se agrupa por la columna cruda y las variantes se unifican
     * después en PHP (ver OrderDetailMap).
     *
     * @return Collection<int, array>
     */
    public function porDetalle(): Collection
    {
        $conteos = $this->baseQuery()
            ->whereBetween('technical_orders.created_at', [$this->period->from, $this->period->to])
            ->selectRaw('technical_orders.detail as detalle, COUNT(*) as total')
            ->groupBy('technical_orders.detail')
            ->pluck('total', 'detalle');

        return collect(OrderDetailMap::agrupar($conteos));
    }

    /**
     * Detalle de las órdenes cruzado con su resultado.
     *
     * Responde a la pregunta operativa: de las reconexiones, ¿cuántas
     * se cerraron y cuántas siguen abiertas? Un tipo de trabajo que
     * se acumula sin cerrar es justo lo que hay que detectar.
     *
     * @return Collection<int, array>
     */
    public function detallePorEstado(): Collection
    {
        $filas = $this->baseQuery()
            ->whereBetween('technical_orders.created_at', [$this->period->from, $this->period->to])
            ->selectRaw('technical_orders.detail as detalle')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN technical_orders.status = ? THEN 1 ELSE 0 END) as cerradas', [self::ESTADO_CERRADA])
            ->selectRaw('SUM(CASE WHEN technical_orders.status = ? THEN 1 ELSE 0 END) as rechazadas', [self::ESTADO_RECHAZADA])
            ->selectRaw(
                'AVG(CASE WHEN technical_orders.status = ? THEN TIMESTAMPDIFF(HOUR, technical_orders.created_at, technical_orders.updated_at) END) as horas',
                [self::ESTADO_CERRADA]
            )
            ->groupBy('technical_orders.detail')
            ->get();

        // Las variantes del mismo detalle se funden en una sola fila
        $acumulado = [];

        foreach ($filas as $f) {
            $clave = OrderDetailMap::clave($f->detalle);
            $indice = $clave ?? '';

            if (!isset($acumulado[$indice])) {
                $acumulado[$indice] = [
                    'clave' => $clave,
                    'etiqueta' => OrderDetailMap::etiqueta($clave),
                    'tipo' => OrderDetailMap::tipo($clave),
                    'color' => OrderDetailMap::color($clave),
                    'total' => 0, 'cerradas' => 0, 'rechazadas' => 0,
                    'horas_suma' => 0.0, 'horas_peso' => 0,
                ];
            }

            $acumulado[$indice]['total'] += (int) $f->total;
            $acumulado[$indice]['cerradas'] += (int) $f->cerradas;
            $acumulado[$indice]['rechazadas'] += (int) $f->rechazadas;

            // El promedio de horas se pondera por las órdenes
            // cerradas de cada variante: promediar promedios daría
            // el mismo peso a una variante con 1 orden que a otra
            // con 50
            if ($f->horas !== null && (int) $f->cerradas > 0) {
                $acumulado[$indice]['horas_suma'] += (float) $f->horas * (int) $f->cerradas;
                $acumulado[$indice]['horas_peso'] += (int) $f->cerradas;
            }
        }

        return collect($acumulado)
            ->map(function ($fila) {
                $fila['abiertas'] = $fila['total'] - $fila['cerradas'] - $fila['rechazadas'];
                $fila['horas_promedio'] = $fila['horas_peso'] > 0
                    ? round($fila['horas_suma'] / $fila['horas_peso'], 1)
                    : null;

                unset($fila['horas_suma'], $fila['horas_peso']);

                return $fila;
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @return Collection<int, array{etiqueta: string, total: int}>
     */
    public function porEstado(): Collection
    {
        return $this->agrupar('technical_orders.status');
    }

    /**
     * @return Collection<int, array{etiqueta: string, total: int}>
     */
    private function agrupar(string $columna): Collection
    {
        return $this->baseQuery()
            ->whereBetween('technical_orders.created_at', [$this->period->from, $this->period->to])
            ->selectRaw("{$columna} as etiqueta, COUNT(*) as total")
            ->groupBy('etiqueta')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [
                'etiqueta' => (string) ($f->etiqueta ?: 'Sin definir'),
                'total' => (int) $f->total,
            ]);
    }

    /**
     * Rendimiento por técnico.
     *
     * Se cuentan las órdenes ASIGNADAS al técnico dentro del rango.
     * El promedio de resolución solo considera las cerradas: incluir
     * las abiertas mezclaría trabajo terminado con trabajo en curso
     * y premiaría a quien deja órdenes sin cerrar.
     *
     * @return Collection<int, array>
     */
    public function porTecnico(): Collection
    {
        $filas = $this->baseQuery()
            ->join('users', 'users.id', '=', 'technical_orders.user_assigned')
            ->whereBetween('technical_orders.created_at', [$this->period->from, $this->period->to])
            ->selectRaw("CONCAT(users.name, ' ', COALESCE(users.last_name, '')) as tecnico")
            ->selectRaw('COUNT(*) as asignadas')
            ->selectRaw('SUM(CASE WHEN technical_orders.status = ? THEN 1 ELSE 0 END) as cerradas', [self::ESTADO_CERRADA])
            ->selectRaw('SUM(CASE WHEN technical_orders.status = ? THEN 1 ELSE 0 END) as rechazadas', [self::ESTADO_RECHAZADA])
            ->selectRaw(
                'AVG(CASE WHEN technical_orders.status = ? THEN TIMESTAMPDIFF(HOUR, technical_orders.created_at, technical_orders.updated_at) END) as horas',
                [self::ESTADO_CERRADA]
            )
            ->groupBy('users.id', 'users.name', 'users.last_name')
            ->orderByDesc('cerradas')
            ->get();

        return $filas->map(function ($f) {
            $asignadas = (int) $f->asignadas;

            return [
                'tecnico' => trim($f->tecnico),
                'asignadas' => $asignadas,
                'cerradas' => (int) $f->cerradas,
                'rechazadas' => (int) $f->rechazadas,
                'efectividad' => $asignadas > 0 ? round((int) $f->cerradas / $asignadas * 100, 1) : 0.0,
                'horas_promedio' => $f->horas !== null ? round((float) $f->horas, 1) : null,
            ];
        });
    }

    /**
     * Órdenes sin técnico asignado dentro del rango.
     *
     * No caben en el ranking por técnico y son justamente las que
     * conviene vigilar: trabajo que entró y nadie tomó.
     */
    public function sinAsignar(): int
    {
        return (int) $this->baseQuery()
            ->whereNull('technical_orders.user_assigned')
            ->whereBetween('technical_orders.created_at', [$this->period->from, $this->period->to])
            ->count();
    }

    /**
     * Resultado de las verificaciones de supervisión.
     *
     * @return Collection<int, array{etiqueta: string, total: int}>
     */
    public function verificaciones(): Collection
    {
        return DB::table('technical_order_verifications as v')
            ->join('technical_orders as o', 'o.id', '=', 'v.technical_order_id')
            ->when($this->branchId, fn ($q) => $q->where('o.branch_id', $this->branchId))
            ->whereBetween('v.created_at', [$this->period->from, $this->period->to])
            ->selectRaw('v.status as etiqueta, COUNT(*) as total')
            ->groupBy('v.status')
            ->get()
            ->map(fn ($f) => [
                'etiqueta' => (string) ($f->etiqueta ?: 'Sin definir'),
                'total' => (int) $f->total,
            ]);
    }

    /**
     * Indicadores del período con variación contra el anterior.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $creadas = $this->totalCreadas($this->period);
        $cerradas = $this->totalCerradas($this->period);

        $anterior = $this->period->anterior();

        // Pendientes es una foto de HOY, no del período: son las
        // órdenes que siguen abiertas en este momento
        $abiertas = (int) $this->baseQuery()
            ->whereNotIn('technical_orders.status', [self::ESTADO_CERRADA, self::ESTADO_RECHAZADA])
            ->count();

        $horas = $this->baseQuery()
            ->where('technical_orders.status', self::ESTADO_CERRADA)
            ->whereBetween('technical_orders.updated_at', [$this->period->from, $this->period->to])
            ->avg(DB::raw('TIMESTAMPDIFF(HOUR, technical_orders.created_at, technical_orders.updated_at)'));

        return [
            'creadas' => $creadas,
            'cerradas' => $cerradas,
            'abiertas' => $abiertas,
            'sin_asignar' => $this->sinAsignar(),
            'horas_promedio' => $horas !== null ? round((float) $horas, 1) : null,
            'tasa_cierre' => $creadas > 0 ? round($cerradas / $creadas * 100, 1) : 0.0,
            'creadas_previas' => $this->totalCreadas($anterior),
            'cerradas_previas' => $this->totalCerradas($anterior),
        ];
    }

    private function totalCreadas(ReportPeriod $periodo): int
    {
        return (int) $this->baseQuery()
            ->whereBetween('technical_orders.created_at', [$periodo->from, $periodo->to])
            ->count();
    }

    private function totalCerradas(ReportPeriod $periodo): int
    {
        return (int) $this->baseQuery()
            ->where('technical_orders.status', self::ESTADO_CERRADA)
            ->whereBetween('technical_orders.updated_at', [$periodo->from, $periodo->to])
            ->count();
    }

    private function baseQuery()
    {
        return TechnicalOrder::query()
            ->when($this->branchId, fn ($q) => $q->where('technical_orders.branch_id', $this->branchId));
    }
}
