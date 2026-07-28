<?php

namespace App\Exports;

use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reporte descargable de retenciones practicadas.
 *
 * Es el insumo del contador: con estas filas se cruzan los
 * certificados de retención de los clientes y se descuentan los
 * impuestos anticipados en la declaración. Por eso lleva base,
 * tarifa y número de certificado, no solo el valor.
 */
class RetentionsExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /**
     * @param  SupportCollection<int, \App\Models\PaymentRetention>  $retenciones
     */
    public function __construct(private readonly SupportCollection $retenciones)
    {
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Tipo de retención',
            'Concepto',
            'N.º de contrato',
            'Identificación',
            'Cliente',
            'Factura',
            'Base',
            'Tarifa %',
            'Valor retenido',
            'N.º de certificado',
            'Recibo',
            'Registró',
        ];
    }

    public function collection()
    {
        return $this->retenciones->map(function ($retencion) {
            $cliente = $retencion->contract?->client;

            return [
                $retencion->created_at?->format('d/m/Y'),
                $retencion->tipo_legible,
                $retencion->concept_label ?? $retencion->concept_code,
                $retencion->contract?->numero_visible,
                $cliente?->identity_number,
                trim(($cliente?->name ?? '') . ' ' . ($cliente?->last_name ?? '')),
                $retencion->invoice?->displayNumber(),
                (float) $retencion->base,
                (float) $retencion->rate,
                (float) $retencion->amount,
                $retencion->certificate_number,
                $retencion->payment_id,
                trim(($retencion->user?->name ?? '') . ' ' . ($retencion->user?->last_name ?? '')),
            ];
        });
    }

    public function title(): string
    {
        return 'Retenciones';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
