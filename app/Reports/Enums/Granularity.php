<?php

namespace App\Reports\Enums;

/**
 * Granularidad temporal de un informe gerencial.
 *
 * Cada caso conoce tres cosas que deben coincidir entre sí:
 *
 *  - la expresión con la que MySQL agrupa las filas,
 *  - el formato con el que PHP genera esa misma clave,
 *  - y el paso con el que se avanza de un período al siguiente.
 *
 * Si el formato de MySQL y el de PHP no produjeran exactamente la
 * misma cadena, el relleno de períodos vacíos no encontraría los
 * datos y las gráficas saldrían en cero.
 */
enum Granularity: string
{
    case Dia = 'day';
    case Semana = 'week';
    case Mes = 'month';
    case Anio = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Dia => 'Diario',
            self::Semana => 'Semanal',
            self::Mes => 'Mensual',
            self::Anio => 'Anual',
        };
    }

    /**
     * Formato de DATE_FORMAT con el que MySQL agrupa.
     *
     * En semanas se usan %x/%v (año y semana ISO, que empiezan en
     * lunes) y no %Y/%u: %Y podría devolver el año anterior en los
     * primeros días de enero y partiría la semana en dos grupos.
     */
    public function sqlFormat(): string
    {
        return match ($this) {
            self::Dia => '%Y-%m-%d',
            self::Semana => '%x-W%v',
            self::Mes => '%Y-%m',
            self::Anio => '%Y',
        };
    }

    /**
     * Formato de PHP equivalente al de sqlFormat().
     *
     * 'o' y 'W' son el año y la semana ISO: la pareja exacta de
     * %x y %v en MySQL.
     */
    public function phpFormat(): string
    {
        return match ($this) {
            self::Dia => 'Y-m-d',
            self::Semana => 'o-\WW',
            self::Mes => 'Y-m',
            self::Anio => 'Y',
        };
    }

    /**
     * Etiqueta legible de una clave de período.
     *
     * "2026-03" se muestra como "mar 2026"; "2026-W12" como
     * "Sem 12, 2026".
     */
    public function humanize(string $bucket): string
    {
        return match ($this) {
            self::Dia => \Carbon\Carbon::createFromFormat('Y-m-d', $bucket)->format('d/m/Y'),
            self::Semana => 'Sem ' . substr($bucket, -2) . ', ' . substr($bucket, 0, 4),
            self::Mes => ucfirst(\Carbon\Carbon::createFromFormat('Y-m', $bucket)->translatedFormat('M Y')),
            self::Anio => $bucket,
        };
    }

    /**
     * Rango máximo razonable, en días, para esta granularidad.
     *
     * Un informe diario de cinco años serían más de 1.800 barras:
     * ilegible en pantalla e innecesariamente pesado. El
     * controlador lo usa para avisar y sugerir otra granularidad.
     */
    public function maxDays(): int
    {
        return match ($this) {
            self::Dia => 186,
            self::Semana => 730,
            self::Mes => 3650,
            self::Anio => 36500,
        };
    }

    public static function fromRequest(?string $valor): self
    {
        return self::tryFrom((string) $valor) ?? self::Mes;
    }
}
