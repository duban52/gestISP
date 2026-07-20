<?php

namespace App\Reports\Support;

use App\Reports\Enums\Granularity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Período analizado por un informe gerencial.
 *
 * Reúne el rango de fechas, la granularidad y las operaciones que
 * todos los informes necesitan:
 *
 *  - la expresión SQL con la que se agrupa,
 *  - la lista completa de períodos del rango (incluidos los que no
 *    tienen datos),
 *  - y el período inmediatamente anterior, para las comparativas.
 *
 * El relleno de períodos vacíos es lo que hace que las gráficas
 * sean honestas: un GROUP BY solo devuelve los períodos CON datos,
 * así que un mes sin ventas simplemente no aparecería y la línea
 * uniría marzo con mayo como si abril no hubiera existido.
 */
class ReportPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly Granularity $granularity,
    ) {
    }

    /**
     * Construye el período a partir de los filtros de la pantalla.
     *
     * Sin fechas, toma los últimos doce meses completos: es el
     * rango que un gerente espera ver al entrar.
     */
    public static function fromRequest(?string $desde, ?string $hasta, ?string $granularidad): self
    {
        $granularity = Granularity::fromRequest($granularidad);

        $to = self::parse($hasta) ?? CarbonImmutable::today();
        $from = self::parse($desde) ?? $to->subMonths(11)->startOfMonth();

        // Un rango invertido no es un error del usuario que valga
        // la pena rechazar: se ordena y ya
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from->startOfDay(), $to->endOfDay(), $granularity);
    }

    private static function parse(?string $fecha): ?CarbonImmutable
    {
        if (blank($fecha)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($fecha);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Expresión SQL que agrupa una columna de fecha por período.
     *
     * La columna se interpola, así que NUNCA debe venir de la
     * petición: los informes la fijan en su propio código.
     */
    public function sqlBucket(string $columna): string
    {
        return "DATE_FORMAT({$columna}, '{$this->granularity->sqlFormat()}')";
    }

    /**
     * Todas las claves de período del rango, en orden y sin huecos.
     *
     * @return Collection<int, string>
     */
    public function buckets(): Collection
    {
        $claves = collect();
        $cursor = $this->alineeInicio($this->from);
        $formato = $this->granularity->phpFormat();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $claves->push($cursor->format($formato));
            $cursor = $this->siguiente($cursor);
        }

        // Un rango más corto que un período (p. ej. tres días con
        // granularidad anual) igual debe producir su período
        return $claves->isEmpty()
            ? collect([$this->from->format($formato)])
            : $claves->unique()->values();
    }

    /**
     * Rellena los períodos sin datos.
     *
     * Recibe lo que devolvió el GROUP BY (clave => valor) y
     * devuelve la serie completa del rango, con $relleno donde no
     * hubo movimiento.
     *
     * @param  array<string, mixed>|Collection<string, mixed>  $datos
     * @return Collection<string, mixed>
     */
    public function completarSerie($datos, mixed $relleno = 0): Collection
    {
        $datos = collect($datos);

        return $this->buckets()->mapWithKeys(fn ($clave) => [
            $clave => $datos->get($clave, $relleno),
        ]);
    }

    /**
     * Etiquetas legibles de la serie, para el eje de las gráficas.
     *
     * @return Collection<int, string>
     */
    public function etiquetas(): Collection
    {
        return $this->buckets()->map(fn ($b) => $this->granularity->humanize($b));
    }

    /**
     * Período inmediatamente anterior, de la misma duración.
     *
     * Es la base de las comparativas: "creció un 12% respecto al
     * período anterior" compara contra un lapso equivalente, no
     * contra el mes calendario anterior.
     */
    public function anterior(): self
    {
        // +1 día porque el rango incluye ambos extremos
        $dias = $this->from->diffInDays($this->to) + 1;

        return new self(
            $this->from->subDays($dias)->startOfDay(),
            $this->from->subDay()->endOfDay(),
            $this->granularity,
        );
    }

    /**
     * ¿El rango es demasiado largo para esta granularidad?
     */
    public function demasiadoLargo(): bool
    {
        return $this->from->diffInDays($this->to) > $this->granularity->maxDays();
    }

    public function granularidadSugerida(): Granularity
    {
        $dias = $this->from->diffInDays($this->to);

        return match (true) {
            $dias <= Granularity::Dia->maxDays() => Granularity::Dia,
            $dias <= Granularity::Semana->maxDays() => Granularity::Semana,
            $dias <= Granularity::Mes->maxDays() => Granularity::Mes,
            default => Granularity::Anio,
        };
    }

    public function etiquetaRango(): string
    {
        return $this->from->format('d/m/Y') . ' — ' . $this->to->format('d/m/Y');
    }

    /**
     * Lleva la fecha al comienzo de su período, para que el
     * recorrido no genere claves duplicadas ni se salte ninguna.
     */
    private function alineeInicio(CarbonImmutable $fecha): CarbonImmutable
    {
        return match ($this->granularity) {
            Granularity::Dia => $fecha->startOfDay(),
            Granularity::Semana => $fecha->startOfWeek(),
            Granularity::Mes => $fecha->startOfMonth(),
            Granularity::Anio => $fecha->startOfYear(),
        };
    }

    private function siguiente(CarbonImmutable $fecha): CarbonImmutable
    {
        return match ($this->granularity) {
            Granularity::Dia => $fecha->addDay(),
            Granularity::Semana => $fecha->addWeek(),
            Granularity::Mes => $fecha->addMonth(),
            Granularity::Anio => $fecha->addYear(),
        };
    }
}
