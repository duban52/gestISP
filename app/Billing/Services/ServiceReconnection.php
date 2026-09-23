<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Models\AditionalCharge;
use App\Models\ContractStatusOption;
use App\Models\Contract;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Models\TechnicalOrder;
use App\Models\TechnicalOrderDetail;
use App\Services\Audit\AuditLogger;
use App\Services\ContractServiceSwitch;

/**
 * Lo que pasa cuando un contrato CORTADO se pone al día.
 *
 * Dos cosas, y en este orden:
 *
 *  1. SE LE COBRA LA RECONEXIÓN. El valor es el de la sucursal. Va como
 *     cargo adicional, así que entra como un renglón propio en la
 *     próxima factura —que es donde el contrato dice que lo encontrará—
 *     y no como un cobro suelto en caja. Si la sucursal no tiene precio
 *     de reconexión, no se cobra nada.
 *
 *  2. SE LE DEVUELVE EL SERVICIO. Se habilitan su cuenta PPPoE y su ONT.
 *     Si los equipos responden, el contrato queda ACTIVO en el acto: el
 *     cliente pagó y navega, sin esperar a que pase un técnico.
 *
 * SI LA RED NO RESPONDE, O NO HAY EQUIPOS QUE HABILITAR, se vuelve al
 * camino de siempre: el contrato queda «Por Reconexión» y se crea la
 * orden técnica para que alguien vaya. Es lo que evita que un contrato
 * figure activo mientras el cliente sigue sin internet.
 */
class ServiceReconnection
{
    public function __construct(
        private readonly ContractServiceSwitch $equipos,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * ¿Este contrato está cortado? Lo dice el catálogo de estados: sin
     * servicio, sin ser una baja ni una espera de instalación o de
     * visita. Así un estado nuevo —«Cortado por fraude»— también cuenta
     * sin tocar este código.
     */
    public function estaCortado(Contract $contrato): bool
    {
        $estado = (string) $contrato->status;

        if (in_array($estado, [
            ContractStatus::PorInstalar->value,
            ContractStatus::PorReconexion->value,
        ], true) || ContractStatus::esFinal($estado)) {
            return false;
        }

        return ContractStatusOption::porNombre($estado)?->has_service === false;
    }

    /**
     * Reconecta —o manda al técnico— y cobra la reconexión.
     *
     * @return array{estado: string, hechos: string[], pendientes: string[], cargo: ?AditionalCharge}
     */
    public function alPagar(Contract $contrato, ?int $userId = null, ?int $branchId = null): array
    {
        $cargo = $this->cobrar($contrato, $userId);

        $parte = $this->tieneEquipos($contrato)
            ? $this->equipos->aplicar($contrato, TechnicalOrderDetail::HABILITAR, TechnicalOrderDetail::HABILITAR)
            : ['hechos' => [], 'pendientes' => ['el contrato no tiene cuenta PPPoE ni ONT vinculadas']];

        $reconectado = $parte['pendientes'] === [];

        $contrato->update([
            'status' => $reconectado ? ContractStatus::Activo->value : ContractStatus::PorReconexion->value,
            'overdue_invoices_count' => 0,
            'suspension_warning_date' => null,
        ]);

        if (!$reconectado) {
            // No se pudo desde aquí: que vaya alguien. La orden dice qué
            // falló, para que el técnico sepa a qué va.
            TechnicalOrder::create([
                // La sede del CONTRATO, no la de quien cobra: es el
                // técnico de esa zona el que tiene que ir.
                'contract_id' => $contrato->id,
                'branch_id' => $contrato->branch_id ?? $branchId,
                'type' => 'Servicio',
                'detail' => 'Reconexión',
                'initial_comment' => 'Orden de reconexión automática por pago. '
                    . 'No se pudo reconectar a distancia: ' . implode('; ', $parte['pendientes']) . '.',
            ]);
        }

        $this->auditLogger->action(
            $reconectado ? 'contracts.reconnected' : 'contracts.reconnection_pending',
            $reconectado
                ? sprintf('Reconectó el contrato %s al quedar al día: %s', $contrato->numero_visible, implode('; ', $parte['hechos']) ?: 'no había nada que habilitar')
                : sprintf('El contrato %s quedó «Por Reconexión»: %s', $contrato->numero_visible, implode('; ', $parte['pendientes'])),
            [
                'contrato' => $contrato->numero_visible,
                'hechos' => $parte['hechos'],
                'pendientes' => $parte['pendientes'],
                'cargo_reconexion' => $cargo?->amount,
            ],
            $contrato,
            'contratos',
        );

        return $parte + ['estado' => $contrato->status, 'cargo' => $cargo];
    }

    /**
     * El cargo por reconexión, si la sucursal lo tiene puesto.
     *
     * UNO POR CORTE: si ya hay uno pendiente de facturar, no se agrega
     * otro. Dos pagos parciales que dejen el contrato al día no pueden
     * cobrar dos reconexiones.
     *
     * El IVA es el del servicio principal del plan: la reconexión de un
     * servicio excluido no puede salir gravada porque sí. Si hay que
     * tratarla distinto, se corrige el cargo antes de facturar.
     */
    private function cobrar(Contract $contrato, ?int $userId): ?AditionalCharge
    {
        $precio = round((float) ($contrato->branch?->reconnection_price ?? 0), 2);

        if ($precio <= 0) {
            return null;
        }

        $yaCobrado = $contrato->additionalCharges()
            ->where('status', 'pendiente')
            ->where('description', 'like', self::DESCRIPCION . '%')
            ->exists();

        if ($yaCobrado) {
            return null;
        }

        $servicio = $contrato->plan?->services()->orderByDesc('base_price')->first();

        return AditionalCharge::create([
            'contract_id' => $contrato->id,
            'user_id' => $userId,
            'description' => self::DESCRIPCION,
            'amount' => $precio,
            'tax_percentage' => (float) ($servicio->tax_percentage ?? 0),
            'tax_classification' => $servicio?->tax_classification,
            // De contado: entra completo en la próxima factura.
            'installments_total' => 1,
            'installments_billed' => 0,
            'status' => 'pendiente',
        ]);
    }

    /** Sin equipos vinculados no hay nada que habilitar a distancia. */
    private function tieneEquipos(Contract $contrato): bool
    {
        return PppoeAccount::where('contract_id', $contrato->id)->exists()
            || Ont::where('contract_id', $contrato->id)->exists();
    }

    /** Con el que se reconoce el cargo ya puesto en este corte. */
    public const DESCRIPCION = 'Reconexión del servicio';
}
