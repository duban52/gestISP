<?php

namespace App\Exports;

use App\Models\PppoeAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exportación de las cuentas PPPoE tal como quedaron filtradas.
 *
 * QUÉ LLEVA Y POR QUÉ
 * -------------------
 * Lleva las CONTRASEÑAS. Es lo que hace útil el archivo: sirve para
 * migrar a otro router, para reconfigurar equipos en campo o para
 * auditar contra lo que hay en el Mikrotik, y ninguna de esas cosas se
 * puede hacer sin la credencial. El sistema ya las muestra en pantalla
 * —en la ficha de la cuenta y en la del contrato—, así que el archivo
 * no expone nada nuevo; simplemente lo hace portable.
 *
 * Justamente porque es portable, la descarga queda anotada en la
 * trazabilidad con cuántos registros se llevó y con qué filtros: un
 * archivo con las claves de todos los clientes es algo de lo que
 * conviene saber quién lo sacó y cuándo.
 *
 * Se distingue el estado ADMINISTRATIVO (habilitada o cortada) del de
 * CONEXIÓN (conectada ahora o no). Mezclarlos haría que un listado de
 * "caídos" incluyera a los que están cortados a propósito.
 */
class PppoeAccountsExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /**
     * @param  Collection<int, PppoeAccount>  $cuentas
     */
    public function __construct(
        private readonly Collection $cuentas,
    ) {
    }

    public function headings(): array
    {
        return [
            'Usuario',
            'Contraseña',
            'Router',
            'Perfil',
            'Estado',
            'Conexión',
            'Última conexión',
            'IP actual',
            'IP fija',
            'N.º contrato',
            'Identificación',
            'Cliente',
            'Teléfono',
            'Dirección',
            'Estado del contrato',
            'Comentario',
            'Registrada',
        ];
    }

    public function collection()
    {
        return $this->cuentas->map(function (PppoeAccount $cuenta) {
            $contrato = $cuenta->contract;
            $cliente = $contrato?->client;
            $ultima = $cuenta->ultimaConexion();

            return [
                $cuenta->username,
                $cuenta->password,
                $cuenta->router?->name ?? '—',
                $cuenta->profile ?? '—',
                $cuenta->estado_administrativo,
                $cuenta->estado_conexion,
                // Fecha y hora: para decidir si un cliente lleva una
                // hora caído o tres semanas hace falta la hora.
                $ultima?->format('d/m/Y H:i') ?? 'Nunca',
                $cuenta->latestMetric?->address ?? '—',
                $cuenta->remote_address ?? '—',
                $contrato?->numero_visible ?? 'Sin contrato',
                $cliente?->identity_number ?? '—',
                trim(($cliente?->name ?? '') . ' ' . ($cliente?->last_name ?? '')) ?: '—',
                $cliente?->number_phone ?? '—',
                $contrato?->address ?? '—',
                $contrato?->status ?? '—',
                $cuenta->comment ?? '—',
                $cuenta->created_at?->format('d/m/Y') ?? '—',
            ];
        });
    }

    public function title(): string
    {
        return 'Cuentas PPPoE';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
