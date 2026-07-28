<?php

namespace App\Exports;

use App\Models\BillingRun;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reporte descargable de una corrida de facturación.
 *
 * Una fila por factura con los datos que necesita quien revisa la
 * generación: a quién se le facturó (documento y nombre), sobre qué
 * contrato, qué se le cobró (desglose de servicios y cargos) y en qué
 * estado quedó.
 *
 * Sirve igual para Excel y para CSV: el formato lo decide quien lo
 * descarga.
 */
class BillingRunExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(private readonly BillingRun $run)
    {
    }

    public function headings(): array
    {
        return [
            'Factura',
            'N.º de contrato',
            'Identificación',
            'Nombres',
            'Apellidos',
            'Plan',
            'Período facturado',
            'Detalle facturado',
            'Cargos adicionales',
            'Subtotal',
            'Impuestos',
            'Total',
            'Saldo pendiente',
            'Emitida',
            'Vence',
            'Estado',
        ];
    }

    public function collection()
    {
        return $this->run->facturasDelReporte()->map(function ($factura) {
            $cliente = $factura->contract?->client;

            // Se separan los ítems del plan de los cargos adicionales
            // para que en el reporte se vea de dónde sale cada peso.
            $servicios = [];
            $cargos = [];

            foreach ($factura->invoice_items as $item) {
                $linea = $item->description . ' ($' . number_format((float) $item->total, 0, ',', '.') . ')';

                str_contains(mb_strtolower($item->description), 'cargo')
                    ? $cargos[] = $linea
                    : $servicios[] = $linea;
            }

            return [
                $factura->displayNumber(),
                $factura->contract?->contract_number ?? ('id ' . $factura->contract_id),
                $cliente?->identity_number,
                $cliente?->name,
                $cliente?->last_name,
                $factura->contract?->plan?->name,
                $factura->billed_period ?: $factura->billed_month_name,
                implode(' · ', $servicios),
                implode(' · ', $cargos),
                (float) $factura->subtotal,
                (float) $factura->tax,
                (float) $factura->total,
                (float) $factura->pending_invoice_amount,
                $factura->issue_date?->format('d/m/Y'),
                $factura->due_date?->format('d/m/Y'),
                $factura->status,
            ];
        });
    }

    public function title(): string
    {
        return 'Facturación ' . $this->run->billed_year_month;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
