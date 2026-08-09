<?php

namespace App\Exports;

use App\Models\Contract;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContractsExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;


    public function query()
    {
        return Contract::query()
            ->where('branch_id', session('branch_id'))
            ->with(['client', 'plan']);
    }

    // Encabezados personalizados
    public function headings(): array
    {
        return [
            'Número de contrato',          // $contract->numero_visible
            'Número de documento',         // $contract->client->identity_number
            'Estado',                      // $contract->status
            'Fecha de activación',         // $contract->activation_date
            'Nombre',                      // $contract->client->name
            'Apellido',                    // $contract->client->last_name
            'Teléfono',                    // $contract->client->number_phone
            'Correo electrónico',          // $contract->client->email
            'Barrio',                      // $contract->neighborhood
            'Dirección',                   // $contract->address
            'Tipo de vivienda',            // $contract->home_type
            'Estrato social',              // $contract->social_stratum
            'Cláusula de permanencia',     // $contract->permanence_clause
            'Plan',                        // $contract->plan->name
            'Número de serie CPE',         // $contract->cpe_sn
            'Puerto NAP',                  // $contract->nap_port
            'Usuario PPPoE',               // $contract->user_pppoe
            'Contraseña PPPoE',            // $contract->password_pppoe
            'SSID WiFi',                   // $contract->ssid_wifi
            'Contraseña WiFi',             // $contract->password_wifi
            'Comentario',                  // $contract->comment
            'Cantidad de facturas vencidas', // $contract->overdue_invoices_count
        ];
    }

    // Mapeo de datos para cada fila
    public function map($contract): array
    {
        return [
            // El consecutivo por sucursal, no el id interno: es el
            // número por el que pregunta el cliente y con el que se
            // cruzan estos archivos contra los del contador.
            $contract->numero_visible,
            $contract->client->identity_number ?? '',
            $contract->status,
            $contract->activation_date ? $contract->activation_date : 'N/A',
            $contract->client->name ?? '',
            $contract->client->last_name ?? '',
            $contract->client->number_phone ?? '',
            $contract->client->email ?? '',
            $contract->neighborhood,
            $contract->address,
            $contract->home_type,
            $contract->social_stratum,
            $contract->permanence_clause ?? '',
            $contract->plan->name ?? 'N/A',
            $contract->cpe_sn ?? '',
            $contract->nap_port ?? '',
            $contract->user_pppoe ?? '',
            $contract->password_pppoe ?? '',
            $contract->ssid_wifi ?? '',
            $contract->password_wifi ?? '',
            $contract->comment ?? '',
            $contract->overdue_invoices_count ?? '',
        ];
    }
}
