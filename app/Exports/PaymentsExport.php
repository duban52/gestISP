<?php

namespace App\Exports;

use App\Models\Payment;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use App\Tenancy\CurrentContext;

class PaymentsExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    public function query()
    {
        // Filtrar pagos por la sucursal actual en la sesión
        $query = Payment::query();

        // Si hay una sucursal configurada en la sesión, se filtra por ella
        if (session()->has('branch_id')) {
            $query->whereHas('invoice.contract', function ($query) {
                $query->whereIn('branch_id', app(CurrentContext::class)->branchIds());
            });
        }

        return $query; // Devolvemos la consulta sin ejecutarla
    }

    public function headings(): array
    {
        return [
            'Id del pago',          // $payment->id
            'Número de documento',  // $payment->invoice->contract->client->identity_number
            'Nombre',               // $payment->invoice->contract->client->name
            'Apellido',             // $payment->invoice->contract->client->last_name
            'Fecha de pago',        // $payment->date
            'Monto',                // $payment->amount
            'Medio de pago',        // $payment->payment_method
            'Número de referencia',// $payment->reference_number
            'Notas',                // $payment->notes
            'Registrado por'       // $payment->user->name
        ];
    }

    // Mapeo de datos para cada fila
    public function map($payment): array
    {
        return [
            $payment->id,
            $payment->invoice->contract->client->identity_number ?? '',
            $payment->invoice->contract->client->name,
            $payment->invoice->contract->client->last_name,
            $payment->date,
            $payment->amount ?? '',
            $payment->payment_method,
            $payment->reference_number ?? '',
            $payment->notes ?? '',
            $payment->user->name ?? '',
        ];
    }
}
