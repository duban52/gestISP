<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contract;
use App\Models\DocumentSequence;
use App\Services\Numbering\DocumentNumberService;

/**
 * Asigna el número de contrato visible, consecutivo por sucursal.
 *
 * El formato es PREFIJO + 6 dígitos (ENG000001). El prefijo lo define
 * cada sucursal; el consecutivo se incrementa con la fila de la serie
 * BLOQUEADA, de modo que dos altas simultáneas jamás reciban el mismo
 * número.
 *
 * El id del contrato NO se toca: sigue siendo el identificador
 * interno del sistema.
 *
 * QUÉ CAMBIÓ EN LA FASE 6
 * -----------------------
 * El contador ya no vive en dos columnas de `branches`, sino en una
 * fila de `document_sequences`, y el bloqueo y el incremento los hace
 * `DocumentNumberService` — el mismo que numera las notas.
 *
 * **La API pública de esta clase no cambió.** Sigue teniendo
 * `siguiente()`, `asignar()`, `registrarNumeroExterno()` y
 * `formatear()`, con la misma firma y el mismo comportamiento. Lo que
 * se cambió es el motor de debajo: quien la llamaba —el alta de
 * contratos y la importación de clientes— no se enteró.
 *
 * `branches.contract_prefix` SIGUE SIENDO EL CAMPO QUE SE EDITA
 * ------------------------------------------------------------
 * Cambiar el prefijo desde la ficha de la sucursal sigue funcionando:
 * `Branch` sincroniza el cambio con la serie. Se conserva ahí y no se
 * movió el campo a otra pantalla porque es donde la gente ya sabe
 * buscarlo, y porque durante la transición sirve de red de seguridad —
 * el mismo criterio que con `branches.nit`.
 */
class ContractNumberGenerator
{
    /** Dígitos del consecutivo. */
    private const DIGITOS = 6;

    /** Prefijo de emergencia si la sucursal no tiene uno. */
    private const PREFIJO_POR_DEFECTO = 'CTR';

    public function __construct(
        private readonly DocumentNumberService $numeros = new DocumentNumberService(),
    ) {
    }

    /**
     * Devuelve el siguiente número libre de la sucursal y deja el
     * consecutivo reservado.
     *
     * Debe ejecutarse dentro de una transacción para que el bloqueo
     * de la fila tenga efecto hasta el commit. Si no hay una abierta,
     * la abre el servicio.
     */
    public function siguiente(int $branchId): string
    {
        $sucursal = Branch::withoutGlobalScopes()->findOrFail($branchId);
        $prefijo = $sucursal->contract_prefix ?: self::PREFIJO_POR_DEFECTO;

        return $this->numeros->siguiente(
            DocumentSequence::CONTRATO,
            (int) $sucursal->company_id,
            $branchId,
            ['prefix' => $prefijo, 'padding' => self::DIGITOS],
            // La semilla solo actúa al crear la serie: se arranca desde
            // el mayor consecutivo YA USADO, no desde cero. Sin esto,
            // una sucursal con mil contratos recibiría el número 1 la
            // primera vez, y el UNIQUE de contract_number lo rechazaría.
            semilla: fn () => $this->mayorConsecutivoUsado($branchId, $prefijo),
        )->completo;
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
        $sucursal = Branch::withoutGlobalScopes()->find($branchId);

        if (!$sucursal) {
            return;
        }

        $prefijo = $sucursal->contract_prefix ?: self::PREFIJO_POR_DEFECTO;
        $consecutivo = $this->consecutivoDe($numero, $sucursal->contract_prefix);

        if ($consecutivo === null) {
            return;
        }

        $this->numeros->registrarExterno(
            DocumentSequence::CONTRATO,
            (int) $sucursal->company_id,
            $branchId,
            $consecutivo,
            ['prefix' => $prefijo, 'padding' => self::DIGITOS],
            semilla: fn () => $this->mayorConsecutivoUsado($branchId, $prefijo),
        );
    }

    /**
     * Formatea un consecutivo con el prefijo indicado.
     */
    public function formatear(string $prefijo, int $consecutivo): string
    {
        return $prefijo . str_pad((string) $consecutivo, self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * Mayor consecutivo ya usado en la sucursal con ese prefijo.
     *
     * Se conserva porque es lo que hace que el prefijo se pueda cambiar
     * sin repetir números: al cambiarlo, los contratos viejos siguen
     * con el prefijo anterior y este cálculo mira solo los del nuevo.
     */
    private function mayorConsecutivoUsado(int $branchId, string $prefijo): int
    {
        $numeros = Contract::withoutGlobalScopes()
            ->where('branch_id', $branchId)
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
