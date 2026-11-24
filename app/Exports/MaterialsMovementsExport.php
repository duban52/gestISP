<?php

namespace App\Exports;

use App\Models\MaterialMovement;
use App\Models\Warehouse;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MaterialsMovementsExport implements FromQuery, WithHeadings, WithMapping
{use Exportable;

    /**
     * Los movimientos que tocan un almacén que el usuario puede ver.
     *
     * La MISMA regla que el historial en pantalla (ver
     * Warehouse::scopeVisibles). Antes esto filtraba por sucursal solo
     * si la sesión tenía `branch_id`: en panel consolidado no lo tiene,
     * así que exportaba los movimientos de TODAS las sucursales. Y
     * miraba solo el destino, con lo que las salidas desaparecían del
     * Excel. Un Excel se guarda y se reenvía: es justo donde un filtro
     * mal puesto hace más daño.
     */
    public function query()
    {
        $visibles = Warehouse::visibles()->pluck('id');

        return MaterialMovement::query()->where(function ($q) use ($visibles) {
            $q->whereIn('warehouse_origin_id', $visibles)
                ->orWhereIn('warehouse_destination_id', $visibles);
        });
    }

    public function headings(): array
    {
        return [
            // El renglón y la OPERACIÓN a la que pertenece: un equipo con
            // serial genera un renglón por serial, pero el movimiento es uno.
            'Id del renglon',
            'Movimiento',
            'Fecha del movimiento',
            'Tipo de movimiento',
            'Almacen de origen',
            'Almacen de destino',
            'Material',
            'Cantidad',
            'Unidad de medida',
            'Serial',
            'Motivo',
            'Proveedor',
            'Numero de factura',
            'Fecha de la factura',
            'Observacion',
            'Realizado por'
        ];
    }

    // Mapeo de datos para cada fila
    public function map($movement): array
    {
        return [
            $movement->id,
            $movement->operation_id,
            $movement->created_at,
            $movement->type,
            $movement->warehouseOrigin->description ?? 'N/A',
            $movement->warehouseDestination->description ?? 'N/A',
            $movement->material->name,
            $movement->quantity,
            $movement->unit_of_measurement,
            $movement->serial_number ?? 'N/A',
            $movement->reason,
            $movement->supplier ?? '',
            $movement->invoice_number ?? '',
            $movement->invoice_date?->format('Y-m-d') ?? '',
            $movement->observations ?? '',
            $movement->user->name ?? 'N/A',

        ];
    }
}
