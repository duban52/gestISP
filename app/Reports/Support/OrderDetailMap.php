<?php

namespace App\Reports\Support;

/**
 * Clasificación de las órdenes técnicas por su detalle.
 *
 * technical_orders.type solo distingue "Servicio" e "Incidencia",
 * pero el detalle sí dice qué se hizo realmente. Sale de una lista
 * cerrada del formulario de creación (12 opciones), así que se
 * puede tratar como una categoría y no como texto libre.
 *
 * El valor guardado tiene variantes que hay que unificar:
 *
 *  - Tildes inconsistentes: el formulario guarda "Instalacion de
 *    servicio" y "Adicion de servicio" sin tilde, pero
 *    "Suspensión temporal" y "Reconexión" con ella.
 *  - Sufijos: las órdenes que crea el sistema al firmar un contrato
 *    guardan "Instalación de servicio (creación automática)".
 *
 * Por eso se normaliza antes de comparar: se quitan tildes, se pasa
 * a minúsculas y se descarta lo que vaya entre paréntesis. Sin esto
 * la misma operación aparecería repartida en tres filas del informe.
 */
class OrderDetailMap
{
    /**
     * clave => [etiqueta, tipo al que pertenece, color]
     *
     * El orden es el del formulario: primero el ciclo de vida del
     * servicio y después las incidencias.
     */
    private const CATEGORIAS = [
        'instalacion de servicio' => ['Instalación de servicio', 'Servicio', '#28a745'],
        'retiro de servicio' => ['Retiro de servicio', 'Servicio', '#6c757d'],
        'corte de servicio' => ['Corte de servicio', 'Servicio', '#dc3545'],
        'traslado de servicio' => ['Traslado de servicio', 'Servicio', '#6610f2'],
        'adicion de servicio' => ['Adición de servicio', 'Servicio', '#20c997'],
        'suspension temporal' => ['Suspensión temporal', 'Servicio', '#fd7e14'],
        'reconexion' => ['Reconexión', 'Servicio', '#17a2b8'],
        'sin servicio de tv' => ['Sin servicio de TV', 'Incidencia', '#e83e8c'],
        'sin servicio de internet' => ['Sin servicio de internet', 'Incidencia', '#dc3545'],
        'sin servicio' => ['Sin servicio', 'Incidencia', '#b02a37'],
        'configuraciones' => ['Configuraciones', 'Incidencia', '#0d6efd'],
        'otros' => ['Otros', 'Incidencia', '#adb5bd'],
    ];

    private const SIN_CLASIFICAR = ['Sin clasificar', '—', '#ced4da'];

    /**
     * Normaliza un detalle a su clave de categoría.
     *
     * Devuelve null cuando no corresponde a ninguna opción conocida
     * (por ejemplo una orden antigua con el detalle escrito a mano).
     */
    public static function clave(?string $detalle): ?string
    {
        $normalizado = self::normalizar($detalle);

        if ($normalizado === '') {
            return null;
        }

        if (isset(self::CATEGORIAS[$normalizado])) {
            return $normalizado;
        }

        // "Sin servicio de TV" contiene "sin servicio": se busca
        // la coincidencia MÁS LARGA para no clasificar de menos
        $mejor = null;

        foreach (array_keys(self::CATEGORIAS) as $clave) {
            if (str_contains($normalizado, $clave)
                && (($mejor === null) || strlen($clave) > strlen($mejor))) {
                $mejor = $clave;
            }
        }

        return $mejor;
    }

    /**
     * Quita tildes, paréntesis y espacios sobrantes.
     */
    private static function normalizar(?string $valor): string
    {
        $valor = mb_strtolower(trim((string) $valor));

        // Lo que va entre paréntesis es un matiz, no una categoría:
        // "(creación automática)" no cambia lo que hay que hacer
        $valor = preg_replace('/\s*\([^)]*\)/u', '', $valor);

        $valor = strtr($valor, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $valor));
    }

    public static function etiqueta(?string $clave): string
    {
        return self::CATEGORIAS[$clave][0] ?? self::SIN_CLASIFICAR[0];
    }

    public static function tipo(?string $clave): string
    {
        return self::CATEGORIAS[$clave][1] ?? self::SIN_CLASIFICAR[1];
    }

    public static function color(?string $clave): string
    {
        return self::CATEGORIAS[$clave][2] ?? self::SIN_CLASIFICAR[2];
    }

    /**
     * Agrupa un conjunto de "detalle => cantidad" en categorías.
     *
     * Recibe lo que devolvió el GROUP BY sobre la columna cruda y
     * suma las variantes de un mismo concepto. Se agrupa así, y no
     * en SQL, para no depender de que la colación de MySQL ignore
     * las tildes.
     *
     * @param  iterable<string, int>  $conteos
     * @return array<int, array{clave: ?string, etiqueta: string, tipo: string, color: string, total: int}>
     */
    public static function agrupar(iterable $conteos): array
    {
        $totales = [];

        foreach ($conteos as $detalle => $cantidad) {
            $clave = self::clave((string) $detalle);
            $totales[$clave ?? ''] = ($totales[$clave ?? ''] ?? 0) + (int) $cantidad;
        }

        $filas = [];

        foreach ($totales as $clave => $total) {
            $clave = $clave === '' ? null : $clave;

            $filas[] = [
                'clave' => $clave,
                'etiqueta' => self::etiqueta($clave),
                'tipo' => self::tipo($clave),
                'color' => self::color($clave),
                'total' => $total,
            ];
        }

        usort($filas, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $filas;
    }
}
