<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * Asigna el número de contrato visible, consecutivo por sucursal.
 *
 * El formato es PREFIJO + 6 dígitos (ENG000001). El prefijo lo define
 * cada sucursal; el consecutivo se guarda en la propia sucursal y se
 * incrementa con la fila BLOQUEADA, de modo que dos altas simultáneas
 * jamás reciban el mismo número. Es el mismo criterio que usa la
 * numeración de facturas.
 *
 * El id del contrato NO se toca: sigue siendo el identificador
 * interno del sistema.
 */
class ContractNumberGenerator
{
    /** Dígitos del consecutivo. */
    private const DIGITOS = 6;

    /** Prefijo de emergencia si la sucursal no tiene uno. */
    private const PREFIJO_POR_DEFECTO = 'CTR';

    /**
     * Devuelve el siguiente número libre de la sucursal y deja el
     * consecutivo reservado.
     *
     * Debe ejecutarse dentro de una transacción para que el bloqueo
     * de la fila tenga efecto hasta el commit. Si no hay una abierta,
     * se abre aquí.
     */
    public function siguiente(int $branchId): string
    {
        if (DB::transactionLevel() > 0) {
            return $this->reservar($branchId);
        }

        return DB::transaction(fn () => $this->reservar($branchId));
    }

    /**
     * Asigna el número a un contrato que aún no lo tenga.
     */
    public function asignar(Contract $contract): Contract
    {
        if ($contract->contract_number) {
            return $contract;
        }

        $contract->update([
            'contract_number' => $this->siguiente((int) $contract->branch_id),
        ]);

        return $contract;
    }

    /**
     * Registra un número que viene de otro sistema.
     *
     * Al migrar clientes se respeta el consecutivo que ya tenían,
     * pero hay que adelantar el contador de la sucursal si ese número
     * es mayor que el último entregado; de lo contrario el próximo
     * contrato nuevo intentaría repetir un número ya usado.
     */
    public function registrarNumeroExterno(int $branchId, string $numero): void
    {
        $sucursal = Branch::lockForUpdate()->find($branchId);

        if (!$sucursal) {
            return;
        }

        $consecutivo = $this->consecutivoDe($numero, $sucursal->contract_prefix);

        if ($consecutivo !== null && $consecutivo > $sucursal->contract_next_number) {
            $sucursal->update(['contract_next_number' => $consecutivo]);
        }
    }

    /**
     * Formatea un consecutivo con el prefijo de la sucursal.
     */
    public function formatear(string $prefijo, int $consecutivo): string
    {
        return $prefijo . str_pad((string) $consecutivo, self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * Incrementa el consecutivo de la sucursal con la fila bloqueada.
     */
    private function reservar(int $branchId): string
    {
        $sucursal = Branch::lockForUpdate()->findOrFail($branchId);

        $prefijo = $sucursal->contract_prefix ?: self::PREFIJO_POR_DEFECTO;
        $siguiente = (int) $sucursal->contract_next_number + 1;

        // Si el prefijo se cambió y ya existen contratos con números
        // más altos (por ejemplo, importados), se continúa desde ahí.
        $siguiente = max($siguiente, $this->mayorConsecutivoUsado($branchId, $prefijo) + 1);

        $sucursal->update(['contract_next_number' => $siguiente]);

        return $this->formatear($prefijo, $siguiente);
    }

    /**
     * Mayor consecutivo ya usado en la sucursal con ese prefijo.
     */
    private function mayorConsecutivoUsado(int $branchId, string $prefijo): int
    {
        $numeros = Contract::where('branch_id', $branchId)
            ->whereNotNull('contract_number')
            ->where('contract_number', 'like', $prefijo . '%')
            ->pluck('contract_number');

        $mayor = 0;

        foreach ($numeros as $numero) {
            $consecutivo = $this->consecutivoDe($numero, $prefijo);

            if ($consecutivo !== null && $consecutivo > $mayor) {
                $mayor = $consecutivo;
            }
        }

        return $mayor;
    }

    /**
     * Extrae la parte numérica de un número de contrato.
     */
    private function consecutivoDe(string $numero, ?string $prefijo): ?int
    {
        $resto = $prefijo && str_starts_with($numero, $prefijo)
            ? substr($numero, strlen($prefijo))
            : $numero;

        $digitos = preg_replace('/\D/', '', $resto);

        return $digitos === '' ? null : (int) $digitos;
    }
}
