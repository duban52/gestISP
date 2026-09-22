<?php

namespace App\Exports;

use App\Models\ContractCutoffItem;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Reporte de cortes masivos por mora: un renglón por contrato de cada
 * tanda, también los que no se cortaron y por qué.
 *
 * O una tanda (su botón «Excel») o todas las de un rango de fechas
 * (el historial). Siempre dentro de las sucursales del usuario.
 */
class ContractCutoffExport implements FromQuery, WithHeadings, WithMapping
{
    /** @param  array<int, int>  $branchIds */
    public function __construct(
        private readonly array $branchIds,
        private readonly ?int $tandaId = null,
        private readonly ?string $desde = null,
        private readonly ?string $hasta = null,
    ) {
    }

    public function query()
    {
        return ContractCutoffItem::query()
            ->with(['cutoff.user', 'contract.client'])
            ->whereHas('cutoff', fn ($q) => $q
                ->whereIn('branch_id', $this->branchIds)
                ->when($this->tandaId, fn ($q) => $q->whereKey($this->tandaId))
                ->when($this->desde, fn ($q) => $q->whereDate('created_at', '>=', $this->desde))
                ->when($this->hasta, fn ($q) => $q->whereDate('created_at', '<=', $this->hasta)))
            ->orderBy('contract_cutoff_id')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'Tanda', 'Fecha', 'Ordenado por', 'Motivo', 'Contrato', 'Documento', 'Cliente',
            'Facturas vencidas', 'Debía', 'Resultado', 'Detalle', 'Orden administrativa', 'Procesado',
        ];
    }

    /** @param  ContractCutoffItem  $item */
    public function map($item): array
    {
        return [
            $item->contract_cutoff_id,
            $item->cutoff?->created_at?->format('d/m/Y H:i'),
            $item->cutoff?->user?->name,
            $item->cutoff?->reason,
            $item->contract_number,
            $item->contract?->client?->identity_number,
            $item->contract?->client?->fullName(),
            $item->overdue_count,
            $item->overdue_amount !== null ? (float) $item->overdue_amount : null,
            ContractCutoffItem::ETIQUETAS[$item->status][0] ?? $item->status,
            $item->message,
            $item->technical_order_id,
            $item->processed_at?->format('d/m/Y H:i'),
        ];
    }
}
