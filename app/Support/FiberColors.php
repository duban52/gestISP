<?php

namespace App\Support;

/**
 * Código de colores de la fibra óptica (TIA-598-C).
 *
 * POR QUÉ ESTÁ EN UN SITIO SOLO
 * -----------------------------
 * El orden de los doce colores no es una convención de esta empresa:
 * es la norma con la que vienen fabricados todos los cables, y con la
 * que hablan los técnicos por radio ("el naranja del buffer verde").
 * Escribirlo dos veces es garantizar que algún día una pantalla diga
 * un color y otra diga otro para el mismo hilo.
 *
 * CÓMO SE NUMERA
 * --------------
 * Los buffers siguen la misma secuencia que los hilos. Un cable de 48
 * hilos son 4 buffers de 12: el buffer 2 es Naranja y dentro de él los
 * hilos vuelven a empezar en Azul. Así, el hilo global 14 es
 * "Naranja / Naranja".
 *
 * Cuando un cable tiene más de doce buffers —o más de doce hilos por
 * buffer, que es raro pero existe— la secuencia se repite marcada con
 * un trazador. Se refleja en el nombre ("Azul con trazador") en lugar
 * de inventar colores nuevos.
 */
class FiberColors
{
    /**
     * La secuencia normalizada, en su orden. El índice 0 es el hilo 1.
     */
    public const SECUENCIA = [
        'Azul',
        'Naranja',
        'Verde',
        'Café',
        'Gris',
        'Blanco',
        'Rojo',
        'Negro',
        'Amarillo',
        'Violeta',
        'Rosado',
        'Aguamarina',
    ];

    /**
     * Color aproximado para pintarlo en pantalla.
     *
     * Son referencias, no el tono exacto del fabricante: sirven para
     * reconocer el hilo de un vistazo en una rejilla, no para casar
     * pantone con el cable.
     */
    public const HEX = [
        'Azul' => '#0d6efd',
        'Naranja' => '#fd7e14',
        'Verde' => '#198754',
        'Café' => '#7b4b28',
        'Gris' => '#8c959d',
        'Blanco' => '#f8f9fa',
        'Rojo' => '#dc3545',
        'Negro' => '#212529',
        'Amarillo' => '#ffc107',
        'Violeta' => '#6f42c1',
        'Rosado' => '#e685b5',
        'Aguamarina' => '#20c997',
    ];

    /**
     * Nombre del color de la posición N (base 1).
     *
     * Pasada la docena se repite la secuencia con trazador, que es lo
     * que hace el fabricante.
     */
    public static function nombre(int $posicion): string
    {
        $indice = ($posicion - 1) % count(self::SECUENCIA);
        $vuelta = intdiv($posicion - 1, count(self::SECUENCIA));

        $color = self::SECUENCIA[$indice];

        return $vuelta === 0
            ? $color
            : $color . ' con trazador' . ($vuelta > 1 ? " ({$vuelta})" : '');
    }

    /** Color de pantalla de la posición N (base 1). */
    public static function hex(int $posicion): string
    {
        $indice = ($posicion - 1) % count(self::SECUENCIA);

        return self::HEX[self::SECUENCIA[$indice]] ?? '#6c757d';
    }

    /**
     * Texto de contraste para escribir encima del color.
     *
     * El blanco y el amarillo no admiten letra blanca encima; el negro
     * y el café no admiten letra oscura. Sin esto, la mitad de la
     * rejilla queda ilegible.
     */
    public static function textoSobre(string $color): string
    {
        return in_array($color, ['Blanco', 'Amarillo', 'Aguamarina', 'Gris', 'Rosado'], true)
            ? '#212529'
            : '#ffffff';
    }

    /**
     * Descompone un número de hilo global en su posición física.
     *
     * Es la conversión que hace el técnico de cabeza y que aquí no
     * puede fallar: hilo 14 de un cable con 12 hilos por buffer es el
     * hilo 2 del buffer 2.
     *
     * @return array{buffer: int, buffer_color: string, hilo: int, hilo_color: string}
     */
    public static function posicionDe(int $numeroGlobal, int $hilosPorBuffer): array
    {
        $hilosPorBuffer = max($hilosPorBuffer, 1);

        $buffer = intdiv($numeroGlobal - 1, $hilosPorBuffer) + 1;
        $hilo = (($numeroGlobal - 1) % $hilosPorBuffer) + 1;

        return [
            'buffer' => $buffer,
            'buffer_color' => self::nombre($buffer),
            'hilo' => $hilo,
            'hilo_color' => self::nombre($hilo),
        ];
    }

    /**
     * Capacidades habituales de cable y cómo se reparten.
     *
     * Se ofrecen como sugerencia en el formulario: son las que se
     * compran de verdad. Nada impide capturar otra combinación.
     *
     * @return array<int, array{hilos: int, buffers: int, por_buffer: int}>
     */
    public static function capacidadesHabituales(): array
    {
        return [
            ['hilos' => 2, 'buffers' => 1, 'por_buffer' => 2],
            ['hilos' => 4, 'buffers' => 1, 'por_buffer' => 4],
            ['hilos' => 6, 'buffers' => 1, 'por_buffer' => 6],
            ['hilos' => 12, 'buffers' => 1, 'por_buffer' => 12],
            ['hilos' => 24, 'buffers' => 2, 'por_buffer' => 12],
            ['hilos' => 36, 'buffers' => 3, 'por_buffer' => 12],
            ['hilos' => 48, 'buffers' => 4, 'por_buffer' => 12],
            ['hilos' => 72, 'buffers' => 6, 'por_buffer' => 12],
            ['hilos' => 96, 'buffers' => 8, 'por_buffer' => 12],
            ['hilos' => 144, 'buffers' => 12, 'por_buffer' => 12],
            ['hilos' => 288, 'buffers' => 24, 'por_buffer' => 12],
        ];
    }
}
