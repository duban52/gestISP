<?php

namespace App\Reports;

use App\Models\Contract;
use App\Reports\Support\ContractStatusMap;
use App\Reports\Support\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Crecimiento de la base de contratos.
 *
 * Responde a las preguntas de gerencia sobre la evolución del
 * negocio: cuántos clientes entran, cuántos se van, cómo queda el
 * neto y cuánto ingreso recurrente representa.
 *
 * Dos precisiones sobre las fechas, importantes para leer bien las
 * cifras:
 *
 * - El ALTA se toma de activation_date y, si está vacía, de
 *   created_at. Hoy más de la mitad de los contratos no tienen
 *   fecha de activación registrada, así que sin ese respaldo el
 *   crecimiento saldría casi plano.
 *
 * - La BAJA se aproxima con updated_at del contrato retirado: no
 *   existe una columna que registre cuándo se retiró. Es fiable
 *   mientras un contrato retirado no se vuelva a editar; si se
 *   edita, la baja se mueve a esa fecha. Registrar la fecha de
 *   retiro en su propia columna es la forma de volverlo exacto.
 */
class GrowthReport
{
    /** Fecha de alta: activación real o, en su defecto, creación */
    private const FECHA_ALTA = 'COALESCE(contracts.activation_date, contracts.created_at)';

    public function __construct(
        private readonly ReportPeriod $period,
        private readonly ?int $branchId = null,
    ) {
    }

    /**
     * Serie de altas, bajas y neto por período.
     *
     * @return array{labels: Collection, altas: Collection, bajas: Collection, neto: Collection, base: Collection}
     */
    public function series(): array
    {
        $altas = $this->period->completarSerie($this->contarPor(self::FECHA_ALTA));

        $bajas = $this->period->completarSerie(
            $this->contarPor('contracts.updated_at', fn ($q) => $q->whereIn('contracts.status', ContractStatusMap::bajas()))
        );

        $neto = $altas->map(fn ($valor, $clave) => $valor - $bajas->get($clave, 0));

        return [
            'labels' => $this->period->etiquetas(),
            'altas' => $altas->values(),
            'bajas' => $bajas->values(),
            'neto' => $neto->values(),
            'base' => $this->baseAcumulada($neto)->values(),
        ];
    }

    /**
     * Base de contratos vigentes al cierre de cada período.
     *
     * Se parte de los que ya existían antes del rango y se le va
     * sumando el neto. No hay historial de estados, así que es una
     * reconstrucción: sirve para ver la tendencia, no para
     * auditar un día concreto del pasado.
     */
    private function baseAcumulada(Collection $neto): Collection
    {
        $acumulado = $this->baseInicial();

        return $neto->map(function ($cambio) use (&$acumulado) {
            $acumulado += $cambio;

            return max(0, $acumulado);
        });
    }

    /**
     * Contratos vigentes justo antes de que empiece el período.
     */
    private function baseInicial(): int
    {
        return (int) $this->baseQuery()
            ->whereRaw(self::FECHA_ALTA . ' < ?', [$this->period->from])
            ->where(function ($q) {
                // Los que ya estaban retirados antes del rango no
                // forman parte de la base de partida
                $q->whereNotIn('contracts.status', ContractStatusMap::bajas())
                    ->orWhere('contracts.updated_at', '>=', $this->period->from);
            })
            ->count();
    }

    /**
     * Cuenta contratos agrupados por período sobre una columna de
     * fecha.
     *
     * @return Collection<string, int>
     */
    private function contarPor(string $expresionFecha, ?callable $filtro = null): Collection
    {
        $bucket = $this->period->sqlBucket($expresionFecha);

        return $this->baseQuery()
            ->when($filtro, $filtro)
            ->whereRaw("{$expresionFecha} BETWEEN ? AND ?", [$this->period->from, $this->period->to])
            ->selectRaw("{$bucket} as periodo, COUNT(*) as total")
            ->groupBy('periodo')
            ->pluck('total', 'periodo');
    }

    /**
     * Distribución actual de la base por estado canónico.
     *
     * @return Collection<int, array{grupo: string, etiqueta: string, color: string, total: int}>
     */
    public function distribucionEstados(): Collection
    {
        $grupo = ContractStatusMap::sqlGrupo('contracts.status');

        $conteos = $this->baseQuery()
            ->selectRaw("{$grupo} as grupo, COUNT(*) as total")
            ->groupBy('grupo')
            ->pluck('total', 'grupo');

        // Se recorren los grupos conocidos para que los que están
        // en cero también aparezcan en la gráfica
        return collect(ContractStatusMap::grupos())
            ->push('otro')
            ->map(fn ($g) => [
                'grupo' => $g,
                'etiqueta' => ContractStatusMap::etiqueta($g),
                'color' => ContractStatusMap::color($g),
                'total' => (int) $conteos->get($g, 0),
            ])
            ->filter(fn ($fila) => $fila['grupo'] !== 'otro' || $fila['total'] > 0)
            ->values();
    }

    /**
     * Contratos e ingreso recurrente por plan.
     *
     * El precio del plan es la suma de los servicios que lo
     * componen, la misma regla que aplica InvoiceGenerator al
     * facturar.
     *
     * @return Collection<int, array>
     */
    public function porPlan(): Collection
    {
        $precioPlan = DB::table('plan_service')
            ->join('services', 'services.id', '=', 'plan_service.service_id')
            ->selectRaw('plan_service.plan_id, SUM(services.base_price) as precio')
            ->groupBy('plan_service.plan_id');

        return $this->baseQuery()
            ->join('plans', 'plans.id', '=', 'contracts.plan_id')
            ->leftJoinSub($precioPlan, 'precios', 'precios.plan_id', '=', 'plans.id')
            ->whereIn('contracts.status', ContractStatusMap::vigentes())
            ->selectRaw('plans.name as plan, COUNT(*) as contratos, COALESCE(precios.precio, 0) as precio')
            ->groupBy('plans.id', 'plans.name', 'precios.precio')
            ->orderByDesc('contratos')
            ->get()
            ->map(fn ($fila) => [
                'plan' => $fila->plan,
                'contratos' => (int) $fila->contratos,
                'precio' => (float) $fila->precio,
                'mrr' => (float) $fila->precio * (int) $fila->contratos,
            ]);
    }

    /**
     * Indicadores del período, con variación contra el anterior.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $altas = $this->altasEnPeriodo($this->period);
        $bajas = $this->bajasEnPeriodo($this->period);

        $anterior = $this->period->anterior();
        $altasPrevias = $this->altasEnPeriodo($anterior);
        $bajasPrevias = $this->bajasEnPeriodo($anterior);

        $vigentes = (int) $this->baseQuery()
            ->whereIn('contracts.status', ContractStatusMap::vigentes())
            ->count();

        // Churn: bajas del período sobre la base con la que se
        // empezó. Sin base de partida no es una cifra que signifique
        // algo, así que se deja en cero en lugar de dividir por cero
        $baseInicial = $this->baseInicial();
        $churn = $baseInicial > 0 ? round($bajas / $baseInicial * 100, 2) : 0.0;

        return [
            'vigentes' => $vigentes,
            'altas' => $altas,
            'bajas' => $bajas,
            'neto' => $altas - $bajas,
            'churn' => $churn,
            'mrr' => round($this->porPlan()->sum('mrr'), 2),
            'variacion_altas' => $this->variacion($altas, $altasPrevias),
            'variacion_bajas' => $this->variacion($bajas, $bajasPrevias),
            'altas_previas' => $altasPrevias,
            'bajas_previas' => $bajasPrevias,
        ];
    }

    private function altasEnPeriodo(ReportPeriod $periodo): int
    {
        return (int) $this->baseQuery()
            ->whereRaw(self::FECHA_ALTA . ' BETWEEN ? AND ?', [$periodo->from, $periodo->to])
            ->count();
    }

    private function bajasEnPeriodo(ReportPeriod $periodo): int
    {
        return (int) $this->baseQuery()
            ->whereIn('contracts.status', ContractStatusMap::bajas())
            ->whereBetween('contracts.updated_at', [$periodo->from, $periodo->to])
            ->count();
    }

    /**
     * Variación porcentual entre dos períodos.
     *
     * Sin base previa no existe porcentaje: devolver 100% cuando se
     * parte de cero sería inventar una tendencia.
     */
    private function variacion(int $actual, int $previo): ?float
    {
        if ($previo === 0) {
            return null;
        }

        return round(($actual - $previo) / $previo * 100, 1);
    }


    /**
     * Consulta base, ya limitada a la sucursal en curso.
     *
     * Sin branchId es la vista consolidada de todas las sucursales,
     * reservada a quien tiene el permiso correspondiente.
     */
    private function baseQuery()
    {
        return Contract::query()
            ->when($this->branchId, fn ($q) => $q->where('contracts.branch_id', $this->branchId));
    }
}
