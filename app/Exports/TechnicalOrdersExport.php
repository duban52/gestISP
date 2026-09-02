<?php

namespace App\Exports;

use App\Models\TechnicalOrder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Http\Request;
use App\Tenancy\CurrentContext;

class TechnicalOrdersExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $filters;

    public function __construct(Request $request)
    {
        $this->filters = $request->all(); // Almacenar todos los filtros de la solicitud
    }

    public function query()
    {
        $query = TechnicalOrder::query();

        // Filtrar por sucursal de la sesión
        if (session()->has('branch_id')) {
            $query->whereIn('branch_id', app(CurrentContext::class)->branchIds());
        }

        // Aplicar filtros dinámicos
        if (!empty($this->filters['filter_field']) && !empty($this->filters['filter_value'])) {
            $query->where($this->filters['filter_field'], 'like', '%' . $this->filters['filter_value'] . '%');
        }

        // Filtrar por rango de fechas si están presentes
        if (!empty($this->filters['start_date'])) {
            $query->whereDate('created_at', '>=', $this->filters['start_date']);
        }
        if (!empty($this->filters['end_date'])) {
            $query->whereDate('created_at', '<=', $this->filters['end_date']);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Número de orden',
            'Número de contrato',
            'Estado',
            'Tipo de orden',
            'Detalle',
            'Técnico asignado',
            'Fecha de creación',
        ];
    }

    public function map($technical_order): array
    {
        return [
            $technical_order->id,
            $technical_order->order_number ?? 'N/A',
            $technical_order->contract ? $technical_order->contract->contract_number : 'N/A',
            $technical_order->status,
            $technical_order->type,
            $technical_order->detail,
            $technical_order->assignedUser ? $technical_order->assignedUser->name : 'No asignado',
            $technical_order->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
