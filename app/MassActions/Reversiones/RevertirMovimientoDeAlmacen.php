<?php

namespace App\MassActions\Reversiones;

use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\Inventory;
use App\Models\MassActionItem;
use App\Models\MaterialMovement;
use App\Services\Audit\AuditLogger;
use App\Services\InventoryMover;

/**
 * Deshace un movimiento de almacén con un CONTRAMOVIMIENTO.
 *
 * NO SE BORRA EL MOVIMIENTO ORIGINAL
 * ----------------------------------
 * Y es lo que distingue un inventario serio de una tabla de números.
 * El material entró, salió o se trasladó: eso ocurrió, y alguien lo
 * firmó. Lo que se hace es registrar el movimiento CONTRARIO, que
 * devuelve las existencias donde estaban y deja las dos operaciones a
 * la vista. Borrar la primera dejaría un almacén que cuadra y un
 * historial que no explica por qué.
 *
 *   Entrada       →  se revierte con una Salida del mismo destino
 *   Salida        →  con una Entrada al mismo origen
 *   Transferencia →  con una Transferencia en sentido contrario
 *
 * CUÁNDO SE NIEGA
 * ---------------
 * Cuando el material ya no está donde lo dejó el movimiento. Un equipo
 * que entró y después salió —se instaló en casa de un cliente— no se
 * puede «desentrar»: la existencia que habría que quitar ya no está, y
 * restarla igual dejaría el almacén en negativo. Un consumible del que
 * ya no queda cantidad suficiente, lo mismo.
 */
class RevertirMovimientoDeAlmacen implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly InventoryMover $inventario,
        private readonly AuditLogger $auditoria,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $movimiento = $item->subject;

        if (!$movimiento instanceof MaterialMovement) {
            return 'El movimiento ya no existe.';
        }

        // Dónde quedó el material después del movimiento: es de donde
        // habría que sacarlo para deshacerlo.
        $almacen = match ($movimiento->type) {
            'Entrada', 'Transferencia' => $movimiento->warehouse_destination_id,
            'Salida' => null,   // salió: no hay nada que sacar, se devuelve
            default => null,
        };

        if ($movimiento->type === 'Salida') {
            return null;
        }

        if (!$almacen) {
            return 'El movimiento no tiene almacén de destino: no se puede deshacer.';
        }

        if ($movimiento->serial_number) {
            $existe = Inventory::where('warehouse_id', $almacen)
                ->where('material_id', $movimiento->material_id)
                ->where('serial_number', $movimiento->serial_number)
                ->exists();

            if (!$existe) {
                return sprintf(
                    'El equipo %s ya no está en ese almacén: se movió o se instaló después.',
                    $movimiento->serial_number,
                );
            }

            return null;
        }

        $existencia = Inventory::where('warehouse_id', $almacen)
            ->where('material_id', $movimiento->material_id)
            ->whereNull('serial_number')
            ->first();

        if (!$existencia || $existencia->quantity < $movimiento->quantity) {
            return sprintf(
                'No quedan %s unidades en el almacén para devolver: hay %s.',
                $movimiento->quantity,
                $existencia->quantity ?? 0,
            );
        }

        return null;
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var MaterialMovement $movimiento */
        $movimiento = $item->subject;

        // El contrario, con los almacenes intercambiados.
        [$tipo, $origen, $destino] = match ($movimiento->type) {
            'Entrada' => ['Salida', $movimiento->warehouse_destination_id, null],
            'Salida' => ['Entrada', null, $movimiento->warehouse_origin_id],
            default => ['Transferencia', $movimiento->warehouse_destination_id, $movimiento->warehouse_origin_id],
        };

        $contra = MaterialMovement::create([
            'type' => $tipo,
            'material_id' => $movimiento->material_id,
            'quantity' => $movimiento->quantity,
            'unit_of_measurement' => $movimiento->unit_of_measurement,
            'warehouse_origin_id' => $origen,
            'warehouse_destination_id' => $destino,
            'serial_number' => $movimiento->serial_number,
            'user_id' => auth()->id(),
            'reason' => 'Reversión de la acción masiva #' . $item->mass_action_id
                . ' (movimiento ' . $movimiento->id . ')',
            // La observación del original viaja al contramovimiento: lo
            // que explicaba por qué se movió el material explica igual
            // de bien por qué volvió, y el comprobante de la reversión
            // no puede salir más mudo que el que deshace.
            'observations' => $movimiento->observations,
            // El costo viaja con el material: devolver algo no lo
            // revalúa ni lo abarata.
            'purchase_unit_value' => $movimiento->purchase_unit_value,
        ]);

        $this->inventario->aplicar(
            $tipo,
            $origen,
            $destino,
            $movimiento->material_id,
            (int) $movimiento->quantity,
            (string) $movimiento->unit_of_measurement,
            $movimiento->serial_number,
            $movimiento->purchase_unit_value !== null ? (float) $movimiento->purchase_unit_value : null,
        );

        $this->auditoria->action(
            'materials.movement_reverted',
            sprintf(
                'Revirtió un movimiento de almacén (%s) con un contramovimiento %s',
                $movimiento->type,
                $tipo,
            ),
            [
                'movimiento' => $movimiento->id,
                'contramovimiento' => $contra->id,
                'serial' => $movimiento->serial_number,
                'accion' => $item->mass_action_id,
            ],
            $contra,
            'almacen',
        );
    }

    public function advertencia(): string
    {
        return 'Se registrará el movimiento CONTRARIO de cada renglón, devolviendo las '
            . 'existencias a donde estaban. El movimiento original NO se borra: quedan los dos '
            . 'a la vista. Los equipos que ya se movieron o se instalaron después no se tocan.';
    }
}
