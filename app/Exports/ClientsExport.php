<?php

namespace App\Exports;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Los clientes, en Excel.
 *
 * LO QUE SE VE ES LO QUE SE DESCARGA
 * ----------------------------------
 * Era `Client::query()` a secas, sin encabezados: bajaba la empresa
 * entera con los nombres de columna de la base de datos, y no
 * respetaba un solo filtro de la pantalla. Quien filtraba por «sin
 * contrato» y le daba a exportar se llevaba los nueve mil.
 *
 * Ahora recibe la consulta YA filtrada desde el controlador, que es
 * la misma que alimenta la pantalla y el PDF.
 */
class ClientsExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly ?Builder $consulta = null,
    ) {
    }

    public function query()
    {
        return ($this->consulta ?? Client::query())
            ->with(['contracts.plan'])
            ->withCount('contracts')
            ->orderBy('name');
    }

    public function headings(): array
    {
        return [
            'Documento',
            'Tipo de documento',
            'Nombre',
            'Apellido',
            'Tipo de cliente',
            'Telefono',
            'Telefono adicional',
            'Correo',
            'Contratos',
            'Numeros de contrato',
            'Planes',
            'Estados',
            'Sucursal de origen',
            'Fecha de registro',
        ];
    }

    /** @param  Client  $client */
    public function map($client): array
    {
        $contratos = $client->contracts;

        return [
            $client->identity_number,
            $client->type_document,
            $client->name,
            $client->last_name,
            $client->type_client,
            $client->number_phone,
            $client->aditional_phone,
            $client->email,
            $client->contracts_count ?? $contratos->count(),
            // Un cliente puede tener varios contratos: van en una celda
            // separados por coma, que es lo que permite buscarlos con
            // Ctrl+F sin inventar una fila por contrato.
            $contratos->pluck('numero_visible')->filter()->implode(', '),
            $contratos->pluck('plan.name')->filter()->unique()->implode(', '),
            $contratos->pluck('status')->filter()->unique()->implode(', '),
            $client->branch?->name,
            $client->created_at?->format('Y-m-d'),
        ];
    }
}
