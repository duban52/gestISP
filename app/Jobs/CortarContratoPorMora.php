<?php

namespace App\Jobs;

use App\Models\ContractCutoffItem;
use App\Models\User;
use App\Services\ContractMassCutoff;
use App\Tenancy\CurrentContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * Corta un contrato de una tanda de corte masivo (ver ContractMassCutoff).
 *
 * En la cola no hay sesión: se pone la empresa de la sucursal de la
 * tanda —sin ella el alcance de empresa no filtra— y como usuario a
 * quien ordenó el corte, que es a quien la trazabilidad tiene que
 * nombrar. Solo si no los hay ya: con la cola síncrona este trabajo
 * corre dentro de la petición, y limpiarlos al terminar dejaría a la
 * petición sin contexto.
 */
class CortarContratoPorMora implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un corte no se reintenta a ciegas: si falla, el renglón dice por
     * qué y el operador decide. Volver a pasarlo en otra tanda es seguro.
     */
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $itemId)
    {
    }

    public function handle(ContractMassCutoff $cortes): void
    {
        $item = ContractCutoffItem::with('cutoff')->find($this->itemId);

        if (!$item) {
            return;
        }

        $contexto = app(CurrentContext::class);
        $ponerContexto = !$contexto->activo();
        $ponerUsuario = !Auth::check() && $item->cutoff->user_id;

        if ($ponerContexto) {
            $contexto->establecerDesdeSucursal($item->cutoff->branch_id);
        }

        if ($ponerUsuario && ($usuario = User::find($item->cutoff->user_id))) {
            Auth::setUser($usuario);
        }

        try {
            $cortes->ejecutar($item);
        } finally {
            if ($ponerContexto) {
                $contexto->limpiar();
            }

            if ($ponerUsuario) {
                Auth::forgetUser();
            }
        }
    }

    /** Si el trabajo muere (tiempo agotado), el renglón no puede quedarse «en cola». */
    public function failed(\Throwable $e): void
    {
        ContractCutoffItem::whereKey($this->itemId)
            ->where('status', ContractCutoffItem::PENDIENTE)
            ->update([
                'status' => ContractCutoffItem::ERROR,
                'message' => mb_substr('El corte no terminó: ' . $e->getMessage(), 0, 1000),
                'processed_at' => now(),
            ]);
    }
}
