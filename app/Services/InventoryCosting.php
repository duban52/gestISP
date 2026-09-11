<?php

namespace App\Services;

use App\Models\Inventory;

/**
 * Cuánto cuesta lo que hay en un almacén.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * Los equipos y los consumibles se guardan de forma distinta, y eso
 * decide cómo se les puede poner precio:
 *
 * · EQUIPOS: una fila de inventario por número de serie. Cada fila es
 *   una unidad, así que lleva el costo EXACTO de esa unidad. Si una ONT
 *   se compró a 180.000 y la siguiente a 210.000, cada una vale lo
 *   suyo.
 *
 * · CONSUMIBLES: una sola fila por almacén y material, con la cantidad
 *   acumulada (`updateOrCreate` suma). Ahí no cabe el costo de cada
 *   compra: 200 m de cable a 1.200 y 300 m a 1.400 son una única fila
 *   de 500 m. Se lleva PROMEDIO PONDERADO, que es el método estándar
 *   para este caso y el que da un total fiel sin partir las filas.
 *
 * POR QUÉ PROMEDIO PONDERADO Y NO EL ÚLTIMO PRECIO
 * ------------------------------------------------
 * Porque quedarse con el último precio revalúa hacia atrás todo lo que
 * ya estaba: comprar 10 m caros haría que los 500 m viejos valieran de
 * golpe lo que no costaron. El promedio ponderado reparte el costo real
 * entre las unidades reales.
 *
 *     nuevo = (cantidad_actual × costo_actual + entrante × costo_entrante)
 *             ────────────────────────────────────────────────────────────
 *                          cantidad_actual + entrante
 *
 * EL NULO NO ES CERO
 * ------------------
 * El valor de compra es opcional. Si de una entrada no se sabe el
 * precio, ese material NO entra en el promedio: se conserva el costo
 * que ya había. Meterlo como cero abarataría el promedio y el
 * inventario valdría menos de lo que costó, que es la forma silenciosa
 * de que estas cifras dejen de servir. Y si nunca se supo el precio, el
 * costo se queda en null y los totales lo declaran como pendiente en
 * vez de sumarlo como gratis.
 */
class InventoryCosting
{
    /**
     * El costo unitario que debe quedar tras sumar existencias.
     *
     * @param float|null $costoActual   lo que valía cada unidad de lo que ya había
     * @param float      $cantidadActual existencias antes de la entrada
     * @param float|null $costoEntrante lo que cuesta cada unidad que entra
     * @param float      $cantidadEntrante cuántas entran
     */
    public function promedioPonderado(
        ?float $costoActual,
        float $cantidadActual,
        ?float $costoEntrante,
        float $cantidadEntrante,
    ): ?float {
        // Sin precio en la entrada no se toca lo que ya había: no se
        // sabe nada nuevo, así que no hay nada que recalcular.
        if ($costoEntrante === null) {
            return $costoActual;
        }

        // Primera compra con precio (o almacén que estaba vacío): el
        // costo es el de esta entrada, sin mezclar con un nulo.
        if ($costoActual === null || $cantidadActual <= 0) {
            return round($costoEntrante, 2);
        }

        if ($cantidadEntrante <= 0) {
            return $costoActual;
        }

        $total = ($cantidadActual * $costoActual) + ($cantidadEntrante * $costoEntrante);

        return round($total / ($cantidadActual + $cantidadEntrante), 2);
    }

    /**
     * Deja en la fila de inventario el costo que corresponde tras una
     * entrada, y la guarda.
     *
     * Se llama DESPUÉS de que la cantidad ya se sumó, así que la
     * cantidad previa se reconstruye restando lo que entró: es lo que
     * evita tener que acordarse de invocar esto antes o después.
     */
    public function registrarEntrada(
        Inventory $inventario,
        ?float $costoEntrante,
        float $cantidadEntrante,
    ): void {
        $cantidadPrevia = max(0, (float) $inventario->quantity - $cantidadEntrante);

        $inventario->purchase_unit_value = $this->promedioPonderado(
            $inventario->purchase_unit_value !== null ? (float) $inventario->purchase_unit_value : null,
            $cantidadPrevia,
            $costoEntrante,
            $cantidadEntrante,
        );

        $inventario->save();
    }

    /**
     * Lo que vale una existencia: cantidad por costo unitario.
     *
     * Devuelve null —y no 0— cuando no se sabe el costo. Un almacén con
     * 500 m de cable sin precio no vale cero pesos: vale una cifra que
     * no conocemos, y la pantalla tiene que poder decir eso.
     */
    public function valorDeLaExistencia(?float $costoUnitario, float $cantidad): ?float
    {
        if ($costoUnitario === null) {
            return null;
        }

        return round($costoUnitario * $cantidad, 2);
    }

    /**
     * Lo que vale un material dentro de un almacén.
     *
     * Recibe TODAS las filas de inventario de ese material —en los
     * equipos hay una por número de serie, cada una con su costo—, y
     * devuelve lo que suman y cuántas unidades se quedaron sin precio.
     *
     * `unitario` es el promedio ponderado de lo que sí tiene precio, y
     * sirve para enseñar «a cuánto sale la unidad». No se calcula
     * dividiendo el total entre TODAS las unidades: eso repartiría el
     * costo entre unidades que no lo tienen y haría parecer barato lo
     * que solo está incompleto.
     *
     * @param iterable<\App\Models\Inventory> $filas
     * @return array{total: float|null, unitario: float|null, unidades_sin_valorar: float}
     */
    public function valorarMaterial(iterable $filas): array
    {
        $total = 0.0;
        $unidadesValoradas = 0.0;
        $unidadesSinValorar = 0.0;
        $huboAlguna = false;

        foreach ($filas as $fila) {
            $cantidad = (float) $fila->quantity;

            if ($fila->purchase_unit_value === null) {
                $unidadesSinValorar += $cantidad;
                continue;
            }

            $huboAlguna = true;
            $total += $cantidad * (float) $fila->purchase_unit_value;
            $unidadesValoradas += $cantidad;
        }

        return [
            // null y no 0: un material sin ningún precio no vale cero
            // pesos, vale una cifra que no se conoce.
            'total' => $huboAlguna ? round($total, 2) : null,
            'unitario' => $unidadesValoradas > 0 ? round($total / $unidadesValoradas, 2) : null,
            'unidades_sin_valorar' => $unidadesSinValorar,
        ];
    }

    /**
     * Totaliza una lista de existencias.
     *
     * @param iterable<array{cantidad: float, costo: float|null}> $existencias
     * @return array{total: float, sin_valorar: int}
     *         `total` suma solo lo que tiene precio; `sin_valorar` dice
     *         cuántos materiales quedaron fuera, para que la pantalla
     *         avise en vez de presentar un total incompleto como si
     *         fuera el bueno.
     */
    public function totalizar(iterable $existencias): array
    {
        $total = 0.0;
        $sinValorar = 0;

        foreach ($existencias as $existencia) {
            $valor = $this->valorDeLaExistencia($existencia['costo'], $existencia['cantidad']);

            if ($valor === null) {
                $sinValorar++;
                continue;
            }

            $total += $valor;
        }

        return ['total' => round($total, 2), 'sin_valorar' => $sinValorar];
    }
}
