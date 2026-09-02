<?php

namespace App\Exports;

use App\Models\MaterialMovement;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use App\Tenancy\CurrentContext;

class MaterialsMovementsExport implements FromQuery, WithHeadings, WithMapping
{use Exportable;

    public function query()
    {
        // Filtrar pagos por la sucursal actual en la sesión
        $query = MaterialMovement::query();

        // Si hay una sucursal configurada en la sesión, se filtra por ella
        if (session()->has('branch_id')) {
            $query->whereHas('warehouseDestination', function ($query) {
                $query->whereIn('branch_id', app(CurrentContext::class)->branchIds());
            });
        }

        return $query; // Devolvemos la consulta sin ejecutarla
    }

    public function headings(): array
    {
        return [
            'Id del movimiento',
            'Fecha del movimiento',
            'Tipo de movimiento',
            'Almacen de origen',
            'Almacen de destino',
            'Material',
            'Cantidad',
            'Unidad de medida',
            'Serial',
            'Motivo',
            'Realizado por'
        ];
    }

    // Mapeo de datos para cada fila
    public function map($movement): array
    {
        return [
            $movement->id,
            $movement->created_at,
            $movement->type,
            $movement->warehouseOrigin->description ?? 'N/A',
            $movement->warehouseDestination->description ?? 'N/A',
            $movement->material->name,
            $movement->quantity,
            $movement->unit_of_measurement,
            $movement->serial_number ?? 'N/A',
            $movement->reason,
            $movement->user->name ?? 'N/A',

        ];
    }
}
