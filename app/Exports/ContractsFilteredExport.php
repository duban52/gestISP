<?php

namespace App\Exports;

use App\Services\ContractQuery;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exportación del listado de contratos tal como quedó filtrado.
 *
 * Las columnas y sus valores salen de App\Services\ContractQuery, el
 * mismo sitio del que los toma la tabla en pantalla. Es deliberado:
 * si el Excel armara sus propias columnas, con el tiempo mostraría
 * cosas distintas de las que el usuario acaba de ver, y nadie se
 * daría cuenta hasta que alguien tomara una decisión con el archivo
 * equivocado.
 */
class ContractsFilteredExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /**
     * @param  Collection<int, \App\Models\Contract>  $contratos
     * @param  array<int, string>  $columnas  Claves del catálogo, ya validadas
     */
    public function __construct(
        private readonly Collection $contratos,
        private readonly array $columnas,
    ) {
    }

    public function headings(): array
    {
        $catalogo = ContractQuery::columnas();

        return array_map(
            fn (string $clave) => $catalogo[$clave]['titulo'] ?? $clave,
            $this->columnas,
        );
    }

    public function collection()
    {
        return $this->contratos->map(
            fn ($contrato) => array_map(
                fn (string $columna) => ContractQuery::valor($contrato, $columna),
                $this->columnas,
            ),
        );
    }

    public function title(): string
    {
        return 'Contratos';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
