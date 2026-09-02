<?php

namespace App\Exports;

use App\Models\CashRegisterTransaction;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use App\Tenancy\CurrentContext;

class TransactionsReport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    public function query()
    {
        // Filtrar pagos por la sucursal actual en la sesión
        $query = CashRegisterTransaction::query();

        // Si hay una sucursal configurada en la sesión, se filtra por ella
        if (session()->has('branch_id')) {
            $query->whereHas('cashRegister', function ($query) {
                $query->whereIn('branch_id', app(CurrentContext::class)->branchIds());
            });
        }

        return $query; // Devolvemos la consulta sin ejecutarla
    }

    public function headings(): array
    {
        return [
            'Id de transacción',
            'Tipo de transaccion',
            'Monto',
            'Medio de pago',
            'Fecha de transaccion',
            'Descripcion',
            'Registrado por'
        ];
    }

    // Mapeo de datos para cada fila
    public function map($transaction): array
    {
        return [
            $transaction->id,
            $transaction->transaction_type,
            $transaction->amount,
            $transaction->payment_method,
            $transaction->created_at,
            $transaction->description,
            $transaction->user->name ?? '',
        ];
    }
}
