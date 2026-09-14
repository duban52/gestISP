<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Suelta lo que ocupaba un contrato al darlo de baja.
 *
 * EL PROBLEMA
 * -----------
 * Dar de baja un contrato solo le cambiaba el estado. El puerto de la
 * caja NAP seguía figurando ocupado, la cuenta PPPoE seguía habilitada
 * en el router y la ONT seguía provisionada en la OLT. El resultado:
 * cajas que parecen llenas teniendo espacio, clientes retirados que
 * conservan internet, y ONTs fantasma que nadie sabe de quién son.
 *
 * LO QUE HACE, Y EN QUÉ ORDEN DE IMPORTANCIA
 * ------------------------------------------
 *   1. LIBERA EL PUERTO NAP. Solo base de datos, nunca falla. Es lo que
 *      devuelve capacidad real a la red.
 *   2. CORTA Y DESVINCULA LA CUENTA PPPoE. Se deshabilita en el router
 *      —para que el servicio se caiga de verdad— y se suelta del
 *      contrato. NO se borra del router: el usuario y su historial
 *      siguen ahí por si hay que reclamar algo.
 *   3. ELIMINA LA ONT. Aquí sí se borra, en la OLT y en el sistema.
 *
 * LA REGLA QUE GOBIERNA TODO ESTO
 * -------------------------------
 * **La contabilidad nunca depende de que el equipo conteste.**
 *
 * La OLT puede estar caída y el router inalcanzable. Si el cierre de la
 * orden dependiera de eso, un corte de red impediría dar de baja a un
 * cliente. Así que lo que es base de datos se hace siempre, y lo que
 * habla con un equipo se intenta, se registra y no bloquea.
 *
 * PERO LA ONT NO SE BORRA SI LA OLT NO LO CONFIRMÓ
 * ------------------------------------------------
 * Es la excepción, y es deliberada. Borrar la ficha cuando la OLT
 * rechazó el comando dejaría la ONT provisionada allí y sin nadie
 * siguiéndole el rastro: un equipo fantasma ocupando un onu_id que el
 * sistema cree libre. Cuando falla, la ONT se desvincula del contrato
 * —para que el contrato quede limpio— y la ficha SE CONSERVA, con el
 * fallo en el informe, para poder reintentar desde la pantalla de ONTs.
 *
 * Devuelve siempre un parte de lo ocurrido: quien cierra la orden tiene
 * que enterarse de lo que quedó pendiente, no descubrirlo un mes
 * después.
 */
class ContractDecommissioner
{
    public function __construct(
        private readonly OdnManager $odn,
        private readonly MikrotikApiService $mikrotik,
        private readonly OltSshService $olt,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array{hechos: string[], pendientes: string[]}
     *         `hechos` para confirmar; `pendientes` es lo que alguien
     *         tiene que terminar a mano.
     */
    public function liberar(Contract $contrato): array
    {
        $parte = ['hechos' => [], 'pendientes' => []];

        $this->liberarPuerto($contrato, $parte);
        $this->cortarCuentas($contrato, $parte);
        $this->eliminarOnts($contrato, $parte);

        if ($parte['hechos'] !== [] || $parte['pendientes'] !== []) {
            $this->auditLogger->action(
                'contracts.decommissioned',
                sprintf(
                    'Liberó los recursos del contrato %s al darlo de baja: %s%s',
                    $contrato->numero_visible ?? $contrato->id,
                    implode('; ', $parte['hechos']) ?: 'nada que liberar',
                    $parte['pendientes'] ? '. PENDIENTE: ' . implode('; ', $parte['pendientes']) : '',
                ),
                [
                    'contrato' => $contrato->id,
                    'hechos' => $parte['hechos'],
                    'pendientes' => $parte['pendientes'],
                ],
                $contrato,
                'red',
            );
        }

        return $parte;
    }

    /**
     * El puerto de la caja NAP.
     *
     * Solo base de datos: la ocupación de un puerto se deduce de si hay
     * un contrato apuntándolo, no se guarda en ninguna columna. Por eso
     * esto no puede fallar ni depende de ningún equipo.
     */
    private function liberarPuerto(Contract $contrato, array &$parte): void
    {
        if (!$contrato->nap_port_id) {
            return;
        }

        $etiqueta = $contrato->nap_port;

        $this->odn->liberarPuerto($contrato);

        $parte['hechos'][] = 'se liberó el puerto ' . ($etiqueta ?: 'de la caja NAP');
    }

    /**
     * Las cuentas PPPoE: deshabilitar en el router y soltar del contrato.
     *
     * `hasMany` y no `hasOne`: el esquema no impide que un contrato
     * tenga varias, y saltarse las demás dejaría a un retirado con
     * internet por la segunda cuenta.
     *
     * NO SE BORRA DEL ROUTER. Se deshabilita, que es lo que corta el
     * servicio. El secret sigue ahí con su historial de sesiones, que es
     * lo que se mira si el cliente reclama que le cortaron antes de
     * tiempo.
     */
    private function cortarCuentas(Contract $contrato, array &$parte): void
    {
        $cuentas = PppoeAccount::where('contract_id', $contrato->id)->get();

        foreach ($cuentas as $cuenta) {
            $cortada = $this->deshabilitarEnElRouter($cuenta, $parte);

            // Se desvincula PASE LO QUE PASE con el router: el contrato
            // está de baja y la cuenta no puede seguir colgando de él.
            $cuenta->update(['contract_id' => null]);

            if ($cortada) {
                $parte['hechos'][] = 'se cortó y desvinculó la cuenta ' . $cuenta->username;
            }
        }

        // Las credenciales que el contrato reflejaba dejan de tener
        // dueño. Se limpian aquí y no en el bucle: un contrato con dos
        // cuentas solo refleja una.
        if ($cuentas->isNotEmpty()) {
            $contrato->update(['user_pppoe' => null, 'password_pppoe' => null]);
        }
    }

    /** @return bool si el router confirmó el corte */
    private function deshabilitarEnElRouter(PppoeAccount $cuenta, array &$parte): bool
    {
        if ($cuenta->disabled) {
            return true; // ya estaba cortada
        }

        $router = $cuenta->router_id ? Router::find($cuenta->router_id) : null;

        if (!$router) {
            $parte['pendientes'][] = 'la cuenta ' . $cuenta->username
                . ' no tiene router registrado: hay que deshabilitarla a mano';

            return false;
        }

        try {
            $this->mikrotik->setPppSecretState($router, $cuenta, true);
            $cuenta->update(['disabled' => true]);

            return true;
        } catch (Throwable $e) {
            Log::warning('No se pudo deshabilitar la cuenta PPPoE al dar de baja el contrato.', [
                'cuenta' => $cuenta->username,
                'router' => $router->name,
                'error' => $e->getMessage(),
            ]);

            $parte['pendientes'][] = 'no se pudo cortar la cuenta ' . $cuenta->username
                . ' en ' . $router->name . ' (' . $e->getMessage() . '): sigue con servicio';

            return false;
        }
    }

    /**
     * Las ONTs: borrarlas de la OLT y del sistema.
     *
     * SOLO SE BORRA LA FICHA SI LA OLT LO CONFIRMÓ. Ver el comentario de
     * la clase: borrarla a ciegas dejaría un equipo provisionado que
     * nadie sigue.
     */
    private function eliminarOnts(Contract $contrato, array &$parte): void
    {
        $onts = Ont::where('contract_id', $contrato->id)->with('olt')->get();

        foreach ($onts as $ont) {
            if (!$ont->olt) {
                $ont->update(['contract_id' => null]);
                $parte['pendientes'][] = 'la ONT ' . $ont->sn
                    . ' no tiene OLT registrada: hay que eliminarla a mano';
                continue;
            }

            try {
                $this->olt->deleteOnt($ont->olt, $ont);
            } catch (Throwable $e) {
                Log::warning('No se pudo eliminar la ONT en la OLT al dar de baja el contrato.', [
                    'ont' => $ont->sn,
                    'olt' => $ont->olt->name,
                    'error' => $e->getMessage(),
                ]);

                // Se suelta del contrato pero la ficha SE QUEDA: sigue
                // provisionada en la OLT y hay que poder reintentarlo.
                $ont->update(['contract_id' => null]);

                $parte['pendientes'][] = 'no se pudo eliminar la ONT ' . $ont->sn
                    . ' en ' . $ont->olt->name . ' (' . $e->getMessage() . '): '
                    . 'sigue provisionada, elimínela desde la pantalla de ONTs';

                continue;
            }

            $sn = $ont->sn;
            $ont->delete();

            $parte['hechos'][] = 'se eliminó la ONT ' . $sn;
        }

        // El serial que el contrato guardaba ya no apunta a nada.
        if ($onts->isNotEmpty() && $contrato->cpe_sn) {
            $contrato->update(['cpe_sn' => null]);
        }
    }
}
