<?php

namespace App\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\ContractStatusFromOrder;
use App\Jobs\CortarContratoPorMora;
use App\Models\BranchBillingSetting;
use App\Models\Contract;
use App\Models\ContractCutoff;
use App\Models\ContractCutoffItem;
use App\Models\ContractStatusOption;
use App\Models\Invoice;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Corte masivo por mora, por número de contrato.
 *
 * Corta en los dos sitios a la vez: en el sistema —el contrato queda
 * «Suspendido» con una orden administrativa en su historial— y en la
 * red —se deshabilitan su cuenta PPPoE y su ONT—. Las dos cosas las
 * hace ContractStatusFromOrder::ordenAdministrativa(), el mismo camino
 * que el cambio de estado a mano: un corte masivo deja en el contrato
 * la misma huella que uno hecho de uno en uno.
 *
 * SOLO SE CORTA A QUIEN DEBE
 * --------------------------
 * La lista la sube una persona y puede venir de una exportación vieja.
 * Antes de cortar se comprueba que el contrato deba de verdad las
 * facturas vencidas que la sucursal exige para suspender
 * (BranchBillingSetting::suspension_threshold, dos por defecto). Se
 * comprueba DOS veces: al revisar la lista y otra vez cuando le llega
 * el turno en la cola, porque entre las dos pudo pagar.
 *
 * POR QUÉ EN LA COLA
 * ------------------
 * La OLT abre una sesión SSH por ONT: varios segundos por contrato.
 * Cien contratos no caben en una petición web. Cada contrato es un
 * trabajo aparte, así que uno que falla no detiene a los demás, y el
 * resultado de cada uno queda en su renglón de la tanda.
 */
class ContractMassCutoff
{
    /** Cómo sale cada renglón al revisar la lista: etiqueta y color. */
    public const ESTADOS = [
        'lista' => ['Se corta', 'danger'],
        'sin_mora' => ['No debe lo suficiente', 'success'],
        'ya_cortado' => ['Ya está cortado', 'secondary'],
        'no_aplica' => ['No aplica', 'warning'],
        'otra_sucursal' => ['De otra sucursal', 'warning'],
        'no_encontrado' => ['No existe', 'dark'],
    ];

    public const DETALLE = 'Corte masivo por mora';

    public function __construct(
        private readonly ContractStatusFromOrder $estados,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** Cuántas facturas vencidas exige la sucursal para suspender. */
    public function umbral(int $branchId): int
    {
        return max(1, (int) BranchBillingSetting::forBranch($branchId)->suspension_threshold);
    }

    /**
     * Qué pasaría con cada número de la lista. No toca nada.
     *
     * @param  array<int, string>  $numeros
     * @return array<int, array{numero: string, estado: string, mensaje: string, contrato_id: ?int,
     *               cliente: ?string, estado_contrato: ?string, vencidas: int, monto: float}>
     */
    public function resolver(array $numeros, int $branchId): array
    {
        $umbral = $this->umbral($branchId);

        $contratos = Contract::where('branch_id', $branchId)
            ->whereIn('contract_number', $numeros)
            ->with('client')
            ->get()
            ->keyBy(fn (Contract $c) => mb_strtolower((string) $c->contract_number));

        $ids = $contratos->pluck('id')->all();
        $deudas = $this->deudaVencida($ids);
        $equipos = $this->equiposActivos($ids);

        return array_map(function (string $numero) use ($contratos, $deudas, $equipos, $umbral, $branchId) {
            $contrato = $contratos->get(mb_strtolower($numero));

            if (!$contrato) {
                // Sí o no, sin decir en cuál ni de quién.
                $ajeno = Contract::where('branch_id', '!=', $branchId)->where('contract_number', $numero)->exists();

                return $this->fila($numero, $ajeno ? 'otra_sucursal' : 'no_encontrado', $ajeno
                    ? 'Ese contrato es de otra sucursal: el corte es de la sucursal en la que se trabaja.'
                    : 'No hay ningún contrato con ese número.');
            }

            $deuda = $deudas[$contrato->id] ?? ['vencidas' => 0, 'monto' => 0.0];
            [$estado, $mensaje] = $this->evaluar($contrato, $deuda, $equipos[$contrato->id] ?? [], $umbral);

            return $this->fila($numero, $estado, $mensaje, $contrato, $deuda);
        }, $numeros);
    }

    /**
     * Crea la tanda con toda la lista y manda a la cola los que se cortan.
     *
     * Recibe los NÚMEROS, no lo que se revisó: vuelve a resolverlos, así
     * que lo que se corta nunca sale de un formulario manipulable.
     *
     * @param  array<int, string>  $numeros
     */
    public function crearTanda(array $numeros, int $branchId, ?int $userId, string $motivo, string $origen): ContractCutoff
    {
        $filas = $this->resolver($numeros, $branchId);
        $aCortar = array_filter($filas, fn ($f) => $f['estado'] === 'lista');

        if ($aCortar === []) {
            throw new RuntimeException('Ningún contrato de la lista se puede cortar. Revise la lista de nuevo.');
        }

        $tanda = DB::transaction(function () use ($filas, $branchId, $userId, $motivo, $origen) {
            $tanda = ContractCutoff::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'reason' => $motivo,
                'source' => $origen,
                'threshold' => $this->umbral($branchId),
            ]);

            foreach ($filas as $fila) {
                $seCorta = $fila['estado'] === 'lista';

                $tanda->items()->create([
                    'contract_id' => $fila['contrato_id'],
                    'contract_number' => $fila['numero'],
                    'status' => $seCorta ? ContractCutoffItem::PENDIENTE : ContractCutoffItem::OMITIDO,
                    'message' => $seCorta ? null : $fila['mensaje'],
                    'overdue_count' => $fila['contrato_id'] ? $fila['vencidas'] : null,
                    'overdue_amount' => $fila['contrato_id'] ? $fila['monto'] : null,
                    'processed_at' => $seCorta ? null : now(),
                ]);
            }

            return $tanda;
        });

        $this->auditLogger->action(
            'contracts.mass_cut',
            sprintf('Ordenó el corte masivo por mora de %d contrato(s) (%s): %s', count($aCortar), $origen, $motivo),
            [
                'tanda' => $tanda->id,
                'origen' => $origen,
                'en_la_lista' => count($filas),
                'a_cortar' => count($aCortar),
                'omitidos' => count($filas) - count($aCortar),
                'motivo' => $motivo,
            ],
            $tanda,
            'contratos',
        );

        $tanda->items()->where('status', ContractCutoffItem::PENDIENTE)->pluck('id')
            ->each(fn (int $id) => CortarContratoPorMora::dispatch($id));

        return $tanda;
    }

    /**
     * Corta UN contrato de la tanda. Lo llama el trabajo de la cola.
     */
    public function ejecutar(ContractCutoffItem $item): void
    {
        if ($item->status !== ContractCutoffItem::PENDIENTE) {
            return;   // ya procesado: reintentar el trabajo no corta dos veces
        }

        $tanda = $item->cutoff;

        // Se vuelve a revisar: entre la lista y este turno pudo pagar.
        $fila = $this->resolver([$item->contract_number], $tanda->branch_id)[0];

        if ($fila['estado'] !== 'lista') {
            $this->cerrar($item, ContractCutoffItem::OMITIDO, 'Al llegar su turno: ' . $fila['mensaje']);

            return;
        }

        $contrato = Contract::findOrFail($fila['contrato_id']);

        try {
            $orden = $this->estados->ordenAdministrativa(
                $contrato,
                ContractStatus::Suspendido->value,
                sprintf(
                    '%s (tanda #%d): %s. Debe %d factura(s) vencida(s) por $%s.',
                    self::DETALLE,
                    $tanda->id,
                    $tanda->reason,
                    $fila['vencidas'],
                    number_format($fila['monto'], 2, ',', '.'),
                ),
                $tanda->user_id,
                self::DETALLE,
            );

            $parte = $this->estados->ultimoParte() ?? ['hechos' => [], 'pendientes' => []];
            $mensaje = implode('; ', array_filter([
                'contrato suspendido',
                ...$parte['hechos'],
                $parte['pendientes'] !== [] ? 'PENDIENTE: ' . implode('; ', $parte['pendientes']) : null,
            ]));

            $this->cerrar(
                $item,
                $parte['pendientes'] === [] ? ContractCutoffItem::CORTADO : ContractCutoffItem::INCOMPLETO,
                ucfirst($mensaje) . '.',
                $orden->id,
            );

            // Por contrato: responde «¿por qué me cortaron?».
            $this->auditLogger->action(
                'contracts.cut_for_debt',
                sprintf('Cortó por mora el contrato %s (tanda #%d): %s', $contrato->numero_visible, $tanda->id, $mensaje),
                [
                    'contrato' => $contrato->numero_visible,
                    'tanda' => $tanda->id,
                    'vencidas' => $fila['vencidas'],
                    'monto' => $fila['monto'],
                    'hechos' => $parte['hechos'],
                    'pendientes' => $parte['pendientes'],
                ],
                $contrato,
                'contratos',
            );
        } catch (\Throwable $e) {
            report($e);
            $this->cerrar($item, ContractCutoffItem::ERROR, 'No se pudo cortar: ' . $e->getMessage());
        }
    }

    // ==================== Reglas ====================

    /**
     * @param  array{vencidas: int, monto: float}  $deuda
     * @param  array{pppoe?: int, ont?: int}  $equipos  cuántos siguen habilitados
     * @return array{0: string, 1: string}
     */
    private function evaluar(Contract $contrato, array $deuda, array $equipos, int $umbral): array
    {
        $estado = (string) $contrato->status;

        if (ContractStatus::esFinal($estado)) {
            return ['no_aplica', "El contrato está «{$estado}»: ya terminó."];
        }

        if ($estado === ContractStatus::PorInstalar->value) {
            return ['no_aplica', 'El contrato todavía no tiene servicio instalado.'];
        }

        // Ya pagó y espera al técnico: cortarlo borraría esa señal.
        if ($estado === ContractStatus::PorReconexion->value) {
            return ['no_aplica', 'Ya pagó y está esperando la reconexión.'];
        }

        if ($deuda['vencidas'] < $umbral) {
            return ['sin_mora', $deuda['vencidas'] === 0
                ? 'No tiene facturas vencidas.'
                : sprintf('Debe %d factura(s) vencida(s) por $%s; se corta con %d.',
                    $deuda['vencidas'], number_format($deuda['monto'], 2, ',', '.'), $umbral)];
        }

        $pppoe = $equipos['pppoe'] ?? 0;
        $ont = $equipos['ont'] ?? 0;
        $conServicio = ContractStatusOption::porNombre($estado)?->has_service ?? true;

        if (!$conServicio && $pppoe + $ont === 0) {
            return ['ya_cortado', "Ya está «{$estado}» y no tiene equipos habilitados."];
        }

        $equiposTexto = collect([
            $pppoe ? "{$pppoe} cuenta(s) PPPoE" : null,
            $ont ? "{$ont} ONT" : null,
        ])->filter()->implode(' y ');

        return ['lista', sprintf(
            'Debe %d factura(s) vencida(s) por $%s. %s',
            $deuda['vencidas'],
            number_format($deuda['monto'], 2, ',', '.'),
            $equiposTexto !== ''
                ? "Se suspende y se deshabilitan {$equiposTexto}."
                : 'No tiene equipos habilitados vinculados: solo se suspende en el sistema.',
        )];
    }

    /**
     * Facturas vencidas y lo que se debe de ellas, por contrato.
     *
     * Por la FECHA de vencimiento y no por el estado «Vencida»: ese lo
     * pone el proceso de la madrugada, y un corte hecho antes de que
     * corra no puede perdonar a quien ya debe.
     *
     * @return array<int, array{vencidas: int, monto: float}>
     */
    private function deudaVencida(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Invoice::whereIn('contract_id', $ids)
            ->whereIn('status', InvoiceStatus::payable())
            ->where('pending_invoice_amount', '>', 0)
            ->whereDate('due_date', '<', today())
            ->groupBy('contract_id')
            ->selectRaw('contract_id, COUNT(*) as vencidas, SUM(pending_invoice_amount) as monto')
            ->get()
            ->mapWithKeys(fn ($f) => [(int) $f->contract_id => [
                'vencidas' => (int) $f->vencidas,
                'monto' => round((float) $f->monto, 2),
            ]])
            ->all();
    }

    /**
     * Cuántas cuentas PPPoE y ONT siguen habilitadas, por contrato.
     *
     * @return array<int, array{pppoe?: int, ont?: int}>
     */
    private function equiposActivos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $equipos = [];

        foreach (PppoeAccount::whereIn('contract_id', $ids)->where('disabled', false)
            ->groupBy('contract_id')->selectRaw('contract_id, COUNT(*) as n')->get() as $f) {
            $equipos[(int) $f->contract_id]['pppoe'] = (int) $f->n;
        }

        // Sin dato (null) cuenta como habilitada: más vale mandar la
        // orden a la OLT de más que dejar navegando a quien se cortó.
        foreach (Ont::whereIn('contract_id', $ids)
            ->where(fn ($q) => $q->where('admin_enabled', true)->orWhereNull('admin_enabled'))
            ->groupBy('contract_id')->selectRaw('contract_id, COUNT(*) as n')->get() as $f) {
            $equipos[(int) $f->contract_id]['ont'] = (int) $f->n;
        }

        return $equipos;
    }

    private function fila(string $numero, string $estado, string $mensaje, ?Contract $contrato = null, ?array $deuda = null): array
    {
        return [
            'numero' => $numero,
            'estado' => $estado,
            'mensaje' => $mensaje,
            'contrato_id' => $contrato?->id,
            'cliente' => $contrato?->client?->fullName(),
            'estado_contrato' => $contrato?->status,
            'vencidas' => $deuda['vencidas'] ?? 0,
            'monto' => $deuda['monto'] ?? 0.0,
        ];
    }

    private function cerrar(ContractCutoffItem $item, string $estado, string $mensaje, ?int $ordenId = null): void
    {
        $item->update([
            'status' => $estado,
            'message' => mb_substr($mensaje, 0, 1000),
            'technical_order_id' => $ordenId,
            'processed_at' => now(),
        ]);
    }
}
