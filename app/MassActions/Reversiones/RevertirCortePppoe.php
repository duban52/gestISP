<?php

namespace App\MassActions\Reversiones;

use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\MassActionItem;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Services\Audit\AuditLogger;
use App\Services\MikrotikApiService;

/**
 * Deshace un corte masivo de PPPoE.
 *
 * QUÉ DESHACE
 * -----------
 * Vuelve a habilitar el secret en el Mikrotik y quita la marca de
 * deshabilitada en la cuenta. El cliente vuelve a navegar en cuanto su
 * equipo reintente la conexión.
 *
 * EL ESTADO DEL CONTRATO NO SE TOCA AQUÍ
 * --------------------------------------
 * El corte de PPPoE suspende el contrato de paso, pero un contrato
 * puede tener varias cuentas y puede haber quedado suspendido por otra
 * cosa —una factura vencida, un corte por cartera—. Devolverlo a
 * Activo desde aquí sería decidir, con información de una sola cuenta,
 * algo que depende de toda la cartera del cliente. Se restablece el
 * servicio, que es lo que se cortó; el estado lo resuelve el flujo de
 * cobranza, que es quien sabe.
 *
 * SI EL ROUTER NO RESPONDE, NO SE MIENTE
 * --------------------------------------
 * La cuenta solo se marca como habilitada cuando el Mikrotik confirma.
 * Marcarla antes dejaría al sistema diciendo que el cliente tiene
 * servicio mientras sigue sin navegar.
 */
class RevertirCortePppoe implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly MikrotikApiService $mikrotik,
        private readonly AuditLogger $auditoria,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $cuenta = $item->subject;

        if (!$cuenta instanceof PppoeAccount) {
            return 'La cuenta PPPoE ya no existe en el sistema.';
        }

        // Lo que dejó el corte: deshabilitada. Si ya está habilitada,
        // alguien la restableció antes y no hay nada que deshacer.
        if (!$cuenta->disabled) {
            return 'La cuenta ya está habilitada: alguien la restableció después del corte.';
        }

        if (!$cuenta->router_id || !Router::find($cuenta->router_id)) {
            return 'La cuenta no tiene router asignado o el router ya no existe.';
        }

        return null;
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var PppoeAccount $cuenta */
        $cuenta = $item->subject;
        $router = Router::findOrFail($cuenta->router_id);

        // Primero el equipo. Si falla, lanza y el ítem queda como
        // error: la cuenta NO se marca habilitada mientras el cliente
        // siga sin navegar.
        $this->mikrotik->setPppSecretState($router, $cuenta, false);

        $cuenta->update(['disabled' => false]);

        $this->auditoria->action(
            'pppoe.restored',
            'Restableció el servicio de ' . $cuenta->username . ' al revertir un corte masivo',
            [
                'usuario_pppoe' => $cuenta->username,
                'router' => $router->name,
                'origen' => 'reversion_corte_masivo',
            ],
            $cuenta,
            'red',
        );
    }

    public function advertencia(): string
    {
        return 'Se volverá a habilitar el secret de cada cuenta en el Mikrotik y el cliente '
            . 'recuperará la navegación. El estado del contrato NO se toca: puede estar '
            . 'suspendido por otra razón.';
    }
}
