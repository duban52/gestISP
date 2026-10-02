<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;

/**
 * Qué le hace al inventario un movimiento de almacén.
 *
 * POR QUÉ SALIÓ DEL CONTROLADOR
 * -----------------------------
 * Porque ahora hay DOS sitios que necesitan esta aritmética: el
 * formulario que registra un movimiento y la reversión que lo
 * deshace. Dos copias del mismo cálculo —con sus seriales, su
 * promedio ponderado y el costo que viaja en los traslados— es como
 * acaban diciendo cosas distintas sobre el mismo almacén, y un
 * inventario que no cuadra no se arregla: se vuelve a contar a mano.
 *
 * El código es el que estaba, movido tal cual. El controlador sigue
 * teniendo su `updateInventory()` y llama aquí.
 */
class InventoryMover
{
    public function aplicar(
        string $type,
        ?int $warehouseOriginId,
        ?int $warehouseDestinationId,
        int $materialId,
        int $quantity,
        string $unitOfMeasurement,
        ?string $serialNumber = null,
        ?float $purchaseUnitValue = null
    ): void {
        if ($type === 'Entrada') {
            if ($serialNumber) {
                // EQUIPO: la fila es una unidad, así que lleva su costo
                // exacto. Dos ONT compradas a precios distintos valen
                // cada una lo suyo.
                Inventory::create([
                    'warehouse_id'        => $warehouseDestinationId,
                    'material_id'         => $materialId,
                    'quantity'            => 1,
                    'unit_of_measurement' => $unitOfMeasurement,
                    'serial_number'       => $serialNumber,
                    'purchase_unit_value' => $purchaseUnitValue,
                ]);
            } else {
                // CONSUMIBLE: todas las compras se acumulan en una sola
                // fila, así que el costo se lleva por promedio
                // ponderado. Ver App\Services\InventoryCosting.
                $inventory = Inventory::updateOrCreate(
                    [
                        'warehouse_id'  => $warehouseDestinationId,
                        'material_id'   => $materialId,
                        'serial_number' => null,
                    ],
                    [
                        'quantity'            => DB::raw("COALESCE(quantity, 0) + $quantity"),
                        'unit_of_measurement' => $unitOfMeasurement,
                    ]
                );

                // `refresh()` obligatorio: con DB::raw la cantidad que
                // queda en memoria es la EXPRESIÓN, no el número, y el
                // promedio saldría de una cantidad inventada.
                app(InventoryCosting::class)->registrarEntrada(
                    $inventory->refresh(),
                    $purchaseUnitValue,
                    (float) $quantity,
                );
            }
        } elseif ($type === 'Salida') {
            if ($serialNumber) {
                Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->where('serial_number', $serialNumber)
                    ->first()?->delete();
            } else {
                $inventory = Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->first();

                $inventory?->update([
                    'quantity' => $inventory->quantity - $quantity,
                ]);
            }
        } elseif ($type === 'Transferencia') {
            if ($serialNumber) {
                // El equipo conserva su fila, solo cambia de almacén
                Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->where('serial_number', $serialNumber)
                    ->first()?->update(['warehouse_id' => $warehouseDestinationId]);
            } else {
                // Consumible: restar en origen, sumar en destino
                $originInventory = Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->first();

                // EL COSTO VIAJA CON EL MATERIAL. Se lee ANTES de
                // descontar: trasladar 200 m de cable no los abarata, y
                // si el destino los valorara a cero, mover material de
                // un almacén a otro haría desaparecer dinero del
                // inventario total sin que nadie comprara ni gastara
                // nada.
                $costoDeOrigen = $originInventory?->purchase_unit_value !== null
                    ? (float) $originInventory->purchase_unit_value
                    : null;

                $originInventory?->update([
                    'quantity' => $originInventory->quantity - $quantity,
                ]);

                $destino = Inventory::updateOrCreate(
                    [
                        'warehouse_id'  => $warehouseDestinationId,
                        'material_id'   => $materialId,
                        'serial_number' => null,
                    ],
                    [
                        'quantity'            => DB::raw("COALESCE(quantity, 0) + $quantity"),
                        'unit_of_measurement' => $unitOfMeasurement,
                    ]
                );

                app(InventoryCosting::class)->registrarEntrada(
                    $destino->refresh(),
                    $costoDeOrigen,
                    (float) $quantity,
                );
            }
        }
    }
}
