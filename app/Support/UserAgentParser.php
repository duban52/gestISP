<?php

namespace App\Support;

/**
 * Interpreta la cadena User-Agent del navegador.
 *
 * Extrae el navegador, el sistema operativo y el tipo de equipo con
 * unas pocas coincidencias sobre los patrones más comunes. Es
 * deliberadamente ligero: no pretende reconocer cada navegador del
 * mundo, sino dar una etiqueta legible ("Chrome en Windows, equipo
 * de escritorio") para la trazabilidad, sin sumar una dependencia
 * al proyecto.
 *
 * El orden de las comprobaciones importa: varios navegadores mienten
 * en su User-Agent por compatibilidad (Edge dice "Chrome", Chrome
 * dice "Safari"), así que los más específicos se prueban primero.
 */
class UserAgentParser
{
    /**
     * @return array{browser: string, platform: string, device_type: string}
     */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        return [
            'browser' => self::browser($ua),
            'platform' => self::platform($ua),
            'device_type' => self::deviceType($ua),
        ];
    }

    private static function browser(string $ua): string
    {
        // Edge y Opera incluyen "Chrome" en su UA: van primero
        $patrones = [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Opera' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'CriOS' => 'Chrome',            // Chrome en iOS
            'Safari' => 'Safari',           // después de Chrome: Chrome también dice Safari
            'MSIE' => 'Internet Explorer',
            'Trident' => 'Internet Explorer',
        ];

        foreach ($patrones as $aguja => $nombre) {
            if (stripos($ua, $aguja) !== false) {
                return $nombre;
            }
        }

        return $ua === '' ? 'Desconocido' : 'Otro';
    }

    private static function platform(string $ua): string
    {
        $patrones = [
            'Windows NT 10' => 'Windows 10/11',
            'Windows' => 'Windows',
            'iPhone' => 'iOS',
            'iPad' => 'iPadOS',
            'Android' => 'Android',
            'Mac OS X' => 'macOS',
            'Macintosh' => 'macOS',
            'Linux' => 'Linux',
            'CrOS' => 'ChromeOS',
        ];

        foreach ($patrones as $aguja => $nombre) {
            if (stripos($ua, $aguja) !== false) {
                return $nombre;
            }
        }

        return 'Desconocido';
    }

    private static function deviceType(string $ua): string
    {
        if (stripos($ua, 'iPad') !== false
            || (stripos($ua, 'Tablet') !== false)
            || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
            return 'Tablet';
        }

        if (stripos($ua, 'Mobile') !== false
            || stripos($ua, 'iPhone') !== false
            || stripos($ua, 'Android') !== false) {
            return 'Móvil';
        }

        return 'Escritorio';
    }

    /**
     * Etiqueta corta para mostrar: "Chrome · Windows 10/11".
     */
    public static function resumen(?string $userAgent): string
    {
        $d = self::parse($userAgent);

        return $d['browser'] . ' · ' . $d['platform'];
    }
}
