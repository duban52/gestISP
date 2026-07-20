<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Ont;
use App\Models\PppoeAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vinculación de equipos con contratos.
 *
 * Las ONTs importadas desde la OLT y las cuentas PPPoE importadas
 * del router llegan sin contrato: están dando servicio pero el
 * sistema no sabe a qué cliente pertenecen. Esto permite asignarlas
 * después, que es lo que destapa el informe de aprovisionamiento.
 *
 * Es una operación SOLO de base de datos: no se toca la OLT ni el
 * Mikrotik. Vincular no cambia nada en el equipo, únicamente
 * registra a quién pertenece lo que ya estaba funcionando.
 *
 * Reglas que se aplican en todos los casos:
 *
 * - El equipo y el contrato deben ser de la sucursal activa. La
 *   sucursal se guarda en sesión como TEXTO, así que se convierte
 *   antes de comparar ('1' !== 1 rechazaría todo).
 * - Un contrato admite UNA sola ONT: Contract::ont() es hasOne, y
 *   con dos ONTs devolvería una cualquiera de las dos.
 * - Al vincular una ONT se copia su serial al cpe_sn del contrato,
 *   igual que hace la activación; al desvincularla se limpia.
 */
class ContractLinker
{
    /**
     * Asocia una ONT a un contrato.
     */
    public function linkOnt(Ont $ont, int $contractId): Contract
    {
        $contrato = $this->contratoDeLaSucursal($contractId);

        $this->verificarSucursal((int) $ont->branch_id);

        if ($ont->contract_id) {
            throw new RuntimeException(
                'Esta ONT ya está vinculada a un contrato. Desvincúlela antes de asignarla a otro.'
            );
        }

        // Contract::ont() es hasOne: con dos ONTs el sistema
        // mostraría una cualquiera y la otra quedaría invisible
        $ontExistente = Ont::where('contract_id', $contrato->id)->first();

        if ($ontExistente) {
            throw new RuntimeException(
                "El contrato #{$contrato->id} ya tiene la ONT {$ontExistente->sn} asignada. " .
                'Un contrato solo admite una ONT.'
            );
        }

        DB::transaction(function () use ($ont, $contrato) {
            $ont->update(['contract_id' => $contrato->id]);

            // Mismo efecto que al activar una ONT desde cero
            $contrato->update(['cpe_sn' => $ont->sn]);
        });

        return $contrato->refresh();
    }

    /**
     * Quita la asociación de una ONT con su contrato.
     */
    public function unlinkOnt(Ont $ont): void
    {
        $this->verificarSucursal((int) $ont->branch_id);

        if (!$ont->contract_id) {
            throw new RuntimeException('Esta ONT no está vinculada a ningún contrato.');
        }

        DB::transaction(function () use ($ont) {
            $contrato = Contract::find($ont->contract_id);

            // Solo se limpia el serial si es el de ESTA ONT: si el
            // contrato apunta a otro equipo, borrarlo sería perder
            // un dato que no nos corresponde
            if ($contrato && $contrato->cpe_sn === $ont->sn) {
                $contrato->update(['cpe_sn' => null]);
            }

            $ont->update(['contract_id' => null]);
        });
    }

    /**
     * Asocia una cuenta PPPoE a un contrato.
     *
     * A diferencia de las ONTs, aquí no se bloquea que un contrato
     * tenga varias cuentas: el esquema lo permite y hay casos
     * legítimos (un cliente con dos servicios). El buscador avisa
     * cuántas tiene ya para que la decisión sea consciente.
     */
    public function linkPppoe(PppoeAccount $cuenta, int $contractId): Contract
    {
        $contrato = $this->contratoDeLaSucursal($contractId);

        $this->verificarSucursal((int) $cuenta->branch_id);

        if ($cuenta->contract_id) {
            throw new RuntimeException(
                'Esta cuenta ya está vinculada a un contrato. Desvincúlela antes de asignarla a otro.'
            );
        }

        $cuenta->update(['contract_id' => $contrato->id]);

        return $contrato;
    }

    public function unlinkPppoe(PppoeAccount $cuenta): void
    {
        $this->verificarSucursal((int) $cuenta->branch_id);

        if (!$cuenta->contract_id) {
            throw new RuntimeException('Esta cuenta no está vinculada a ningún contrato.');
        }

        $cuenta->update(['contract_id' => null]);
    }

    /**
     * Contrato existente y perteneciente a la sucursal activa.
     */
    private function contratoDeLaSucursal(int $contractId): Contract
    {
        $contrato = Contract::find($contractId);

        if (!$contrato) {
            throw new RuntimeException('El contrato indicado no existe.');
        }

        $this->verificarSucursal((int) $contrato->branch_id);

        return $contrato;
    }

    private function verificarSucursal(int $branchId): void
    {
        if ($branchId !== (int) session('branch_id')) {
            throw new RuntimeException('El equipo o el contrato pertenecen a otra sucursal.');
        }
    }
}
