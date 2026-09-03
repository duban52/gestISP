<?php

namespace App\Support;

/**
 * Qué sucursales entran en un informe.
 *
 * POR QUÉ EXISTE
 * --------------
 * El módulo nació con una sucursal por informe: se trabajaba en una
 * sede y se informaba de esa. El panel consolidado cambia la pregunta
 * — ahora se quiere ver una sede, varias, o todas sumadas, y poder
 * dejar alguna fuera.
 *
 * Los cuatro informes reciben lo mismo y todos tenían que normalizarlo
 * igual, así que la conversión vive aquí y no repetida en cada uno.
 *
 * QUÉ ACEPTA
 * ----------
 * Un entero (una sucursal), una lista de enteros (varias) o null
 * (ninguna restricción). Se admite el entero suelto a propósito: es
 * como llamaban al módulo las decenas de sitios y pruebas que ya
 * existían, y no había motivo para romperlos.
 *
 * QUÉ SIGNIFICA LA LISTA VACÍA
 * ----------------------------
 * «Sin filtro por sucursal». Los informes la interpretan así, y por
 * eso el CONTROLADOR nunca la pasa vacía: siempre manda las sucursales
 * del alcance del usuario. Si dejara pasar una lista vacía desde la
 * petición, alguien vería sedes a las que no tiene acceso.
 */
final class BranchFilter
{
    /**
     * @param  int|array<int, mixed>|null  $sucursales
     * @return array<int, int>
     */
    public static function normalizar(int|array|null $sucursales): array
    {
        if ($sucursales === null) {
            return [];
        }

        if (!is_array($sucursales)) {
            $sucursales = [$sucursales];
        }

        // array_values para que quede una lista y no un array con
        // huecos: whereIn() da igual, pero un array con claves
        // salteadas se serializa como objeto en JSON y confunde.
        return array_values(array_unique(array_filter(
            array_map('intval', $sucursales),
            fn (int $id) => $id > 0,
        )));
    }
}
