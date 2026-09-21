<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\TechnicalOrderDetail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Corta o restablece el servicio de un contrato en los equipos.
 *
 * QUÉ ES Y QUÉ NO ES
 * ------------------
 * Esto DESHABILITA o HABILITA: la cuenta PPPoE sigue siendo del
 * contrato y la ONT sigue siendo suya. Es lo que hace un corte por
 * mora, que se revierte en cuanto el cliente paga.
 *
 * No confundir con ContractDecommissioner, que es la baja definitiva:
 * allí la cuenta se desvincula y la ONT se borra de la OLT, porque el
 * contrato terminó y esos recursos vuelven al inventario.
 *
 * LA REGLA DE ORO, LA MISMA DE SIEMPRE
 * ------------------------------------
 * La contabilidad NO depende de que el equipo conteste. La OLT se cae
 * y el router se vuelve inalcanzable; si el cierre de una orden
 * dependiera de eso, un corte de red impediría cerrar órdenes. Lo que
 * no se pudo hacer se devuelve como PENDIENTE, para que quien cerró la
 * orden sepa que hay algo que terminar a mano.
 */
class ContractServiceSwitch
{
    public function __construct(
        private readonly MikrotikApiService $mikrotik,
        private readonly OltSshService $olt,
    ) {
    }

    /**
     * Aplica al contrato lo que pide el detalle de la orden.
     *
     * @return array{hechos: string[], pendientes: string[]}
     */
    public function aplicar(Contract $contrato, TechnicalOrderDetail $detalle): array
    {
        $parte = ['hechos' => [], 'pendientes' => []];

        if ($detalle->pppoe_action !== TechnicalOrderDetail::SIN_ACCION) {
            $this->cuentas($contrato, $detalle->pppoe_action === TechnicalOrderDetail::HABILITAR, $parte);
        }

        if ($detalle->ont_action !== TechnicalOrderDetail::SIN_ACCION) {
            $this->onts($contrato, $detalle->ont_action === TechnicalOrderDetail::HABILITAR, $parte);
        }

        return $parte;
    }

    /**
     * Las cuentas PPPoE del contrato.
     *
     * Se guarda el estado en la ficha SOLO si el router lo confirmó:
     * decir «cortada» de una cuenta que sigue navegando es peor que no
     * decir nada, porque nadie vuelve a mirarla.
     */
    private function cuentas(Contract $contrato, bool $habilitar, array &$parte): void
    {
        $cuentas = PppoeAccount::where('contract_id', $contrato->id)->get();

        if ($cuentas->isEmpty()) {
            return;
        }

        foreach ($cuentas as $cuenta) {
            // Ya estaba como se pide: no se toca el equipo ni se
            // anuncia un trabajo que no se hizo.
            if ($cuenta->disabled === !$habilitar) {
                continue;
            }

            $router = $cuenta->router_id ? Router::find($cuenta->router_id) : null;

            if (!$router) {
                $parte['pendientes'][] = sprintf(
                    'la cuenta %s no tiene router registrado: hay que %s a mano',
                    $cuenta->username,
                    $habilitar ? 'habilitarla' : 'deshabilitarla',
                );

                continue;
            }

            try {
                $this->mikrotik->setPppSecretState($router, $cuenta, !$habilitar);
                $cuenta->update(['disabled' => !$habilitar]);

                $parte['hechos'][] = sprintf(
                    'se %s la cuenta %s',
                    $habilitar ? 'habilitó' : 'deshabilitó',
                    $cuenta->username,
                );
            } catch (Throwable $e) {
                Log::warning('No se pudo cambiar el estado de la cuenta PPPoE al cerrar la orden.', [
                    'cuenta' => $cuenta->username,
                    'router' => $router->name,
                    'habilitar' => $habilitar,
                    'error' => $e->getMessage(),
                ]);

                $parte['pendientes'][] = sprintf(
                    'no se pudo %s la cuenta %s en %s (%s)',
                    $habilitar ? 'habilitar' : 'deshabilitar',
                    $cuenta->username,
                    $router->name,
                    $e->getMessage(),
                );
            }
        }
    }

    /** Las ONTs del contrato: se activan o se desactivan en la OLT. */
    private function onts(Contract $contrato, bool $habilitar, array &$parte): void
    {
        $onts = Ont::where('contract_id', $contrato->id)->get();

        foreach ($onts as $ont) {
            if ($ont->admin_enabled === $habilitar) {
                continue;
            }

            $olt = $ont->olt_id ? Olt::find($ont->olt_id) : null;

            if (!$olt) {
                $parte['pendientes'][] = sprintf(
                    'la ONT %s no tiene OLT registrada: hay que %sla a mano',
                    $ont->sn,
                    $habilitar ? 'activar' : 'desactivar',
                );

                continue;
            }

            try {
                $this->olt->setOntAdminState($olt, $ont, $habilitar);
                $ont->update(['admin_enabled' => $habilitar]);

                $parte['hechos'][] = sprintf(
                    'se %s la ONT %s',
                    $habilitar ? 'activó' : 'desactivó',
                    $ont->sn,
                );
            } catch (Throwable $e) {
                Log::warning('No se pudo cambiar el estado de la ONT al cerrar la orden.', [
                    'ont' => $ont->sn,
                    'olt' => $olt->name,
                    'habilitar' => $habilitar,
                    'error' => $e->getMessage(),
                ]);

                $parte['pendientes'][] = sprintf(
                    'no se pudo %s la ONT %s en %s (%s)',
                    $habilitar ? 'activar' : 'desactivar',
                    $ont->sn,
                    $olt->name,
                    $e->getMessage(),
                );
            }
        }
    }
}
