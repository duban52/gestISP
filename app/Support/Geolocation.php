<?php

namespace App\Support;

/**
 * Cálculos sobre coordenadas geográficas.
 *
 * Vive en Support y no en un modelo porque lo necesitan tres módulos
 * que no se conocen entre sí: contratos (¿dónde vive el cliente?),
 * órdenes técnicas (¿dónde se cerró?) y redes (¿qué caja queda cerca?).
 *
 * OJO: el equivalente en SQL está en NapBox::scopeCercanasA, que aplica
 * la misma fórmula dentro de la consulta para no traerse cientos de
 * cajas a memoria. Son dos implementaciones de lo mismo a propósito:
 * aquí se comparan DOS puntos concretos, allá se ordena una tabla.
 */
class Geolocation
{
    /** Radio medio de la Tierra en metros (esfera de referencia WGS84). */
    private const EARTH_RADIUS_M = 6_371_000;

    /**
     * Distancia en metros entre dos puntos (fórmula del haversine).
     *
     * Trata la Tierra como una esfera: sobre las distancias de las que
     * hablamos aquí —de metros a pocos kilómetros— el error frente al
     * elipsoide real es de centímetros, muy por debajo del margen de
     * error del GPS de un celular.
     *
     * Devuelve null si falta cualquiera de las cuatro coordenadas, para
     * que quien llame distinga "están lejos" de "no se puede saber".
     */
    public static function distanceInMeters(
        ?float $fromLatitude,
        ?float $fromLongitude,
        ?float $toLatitude,
        ?float $toLongitude,
    ): ?float {
        if ($fromLatitude === null || $fromLongitude === null
            || $toLatitude === null || $toLongitude === null) {
            return null;
        }

        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude))
            * sin($longitudeDelta / 2) ** 2;

        return round(self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a))), 1);
    }

    /**
     * ¿La coordenada es utilizable?
     *
     * Descarta el (0, 0) —el "punto nulo" en mitad del Atlántico— que
     * es lo que llega cuando un dispositivo responde sin haber
     * conseguido posición: guardarlo pondría clientes en el mar.
     */
    public static function isUsable(?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        if (abs($latitude) < 0.00001 && abs($longitude) < 0.00001) {
            return false;
        }

        return abs($latitude) <= 90 && abs($longitude) <= 180;
    }

    /**
     * Distancia en el texto que usa la gente: metros hasta el
     * kilómetro, y de ahí en adelante kilómetros con un decimal.
     */
    public static function humanize(?float $meters): string
    {
        if ($meters === null) {
            return 'sin datos';
        }

        return $meters < 1000
            ? round($meters) . ' m'
            : number_format($meters / 1000, 1, ',', '.') . ' km';
    }
}
