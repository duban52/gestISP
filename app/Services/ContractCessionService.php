<?php

namespace App\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\ContractLiquidator;
use App\Billing\Services\CreditBalanceService;
use App\Billing\Services\InvoiceGenerator;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractCession;
use App\Models\ContractComment;
use App\Models\Invoice;
use App\Models\TechnicalOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cesión de contrato: el MISMO contrato pasa a otro titular.
 *
 * Conserva su número, su id, su estado, su plan, su dirección, sus
 * equipos y su historial. Lo único que cambia es `client_id`. La red
 * no se toca: la ONT, la cuenta PPPoE y el puerto NAP siguen siendo del
 * contrato, y el servicio no se corta.
 *
 * LO QUE HACE QUE ESTO SEA SEGURO
 * -------------------------------
 * Cada factura guarda a quién se le emitió (Invoice::titular()). Las
 * del cedente siguen a su nombre en el XML, el PDF, las notas crédito
 * y los recordatorios aunque el contrato ya sea de otro.
 *
 * EL ORDEN, Y POR QUÉ
 * -------------------
 *  1. `emitirCierre()`: al cedente se le facturan el mes en curso —si
 *     todavía no se le había facturado— y la liquidación de sus cuotas
 *     pendientes. Son suyas: el nuevo titular empieza el mes siguiente.
 *  2. El cedente las PAGA: sin paz y salvo no se cede.
 *  3. `ceder()`: cambia el titular.
 *
 * Son pasos separados a propósito. Emitir las facturas de cierre DENTRO
 * de la cesión dejaría en el contrato una deuda del cedente: la mora
 * del proceso diario cortaría el servicio del nuevo titular por algo
 * que él no debe, y en caja le aparecería para pagar.
 *
 * LO QUE NO HACE, A PROPÓSITO
 * ---------------------------
 *  · No cambia el estado: el contrato sigue como estaba.
 *  · Quita el descuento: era una condición pactada con el cedente.
 *  · No mueve el saldo a favor: queda en el contrato y pagará las
 *    próximas facturas, que ya serán del nuevo titular. Se avisa antes
 *    de confirmar, para que lo arreglen entre ellos.
 *  · No cambia la descripción de la ONT en la OLT ni el comentario del
 *    secret en el router: el sistema los asocia por contrato. Se avisa.
 */
class ContractCessionService
{
    public function __construct(
        private readonly InvoiceGenerator $generador,
        private readonly ContractLiquidator $liquidador,
        private readonly CreditBalanceService $saldos,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Qué impide ceder este contrato, y qué pasará si se cede.
     *
     * Lo usa la pantalla ANTES de confirmar.
     *
     * @return array{bloqueos: string[], deuda: float, facturas_pendientes: int, factura_del_mes: bool,
     *               cuotas_pendientes: int, cierre_pendiente: bool, saldo_a_favor: float}
     */
    public function revisar(Contract $origen, ?Client $cesionario = null): array
    {
        $bloqueos = [];

        if (ContractStatus::esFinal($origen->status)) {
            $bloqueos[] = sprintf('El contrato está «%s»: ya terminó y no se puede ceder.', $origen->status);
        }

        // Un contrato por instalar no tiene servicio que pasarle a nadie.
        if ($origen->status === ContractStatus::PorInstalar->value) {
            $bloqueos[] = 'El contrato todavía no tiene servicio instalado: no hay nada que ceder. '
                . 'Anúlelo y cree uno nuevo a nombre del nuevo titular.';
        }

        // ---- Las facturas de cierre ----
        //
        // Van antes que el paz y salvo: una vez emitidas, lo que falta
        // es que el cedente las pague.
        $delMes = $this->leFaltaElMes($origen);
        $cuotas = $origen->additionalCharges()->where('status', 'pendiente')->count();

        if ($delMes || $cuotas > 0) {
            $bloqueos[] = 'Primero hay que emitirle al titular actual sus facturas de cierre ('
                . implode(' y ', array_filter([
                    $delMes ? 'el mes en curso' : null,
                    $cuotas > 0 ? "la liquidación de {$cuotas} cargo(s) diferido(s)" : null,
                ]))
                . ') y que las pague: son suyas, y el nuevo titular empieza a pagar el mes siguiente.';
        }

        // ---- Paz y salvo ----
        //
        // Decisión del negocio: no se cede con deuda. Con el mismo
        // contrato es además lo que impide que la mora le corte el
        // servicio al nuevo titular por lo que debía el anterior.
        $pendientes = Invoice::where('contract_id', $origen->id)
            ->whereIn('status', InvoiceStatus::payable())
            ->where('pending_invoice_amount', '>', 0)
            ->get(['pending_invoice_amount']);

        $deuda = round((float) $pendientes->sum('pending_invoice_amount'), 2);

        if ($deuda > 0) {
            $bloqueos[] = sprintf(
                'El titular actual debe $%s en %d factura(s). Para ceder el contrato tiene que estar a paz y salvo.',
                number_format($deuda, 2, ',', '.'),
                $pendientes->count(),
            );
        }

        // Una orden abierta la pidió y la firmará el titular anterior:
        // una instalación, un corte o un traslado a medio hacer.
        if (TechnicalOrder::where('contract_id', $origen->id)->where('status', '!=', 'Cerrada')->exists()) {
            $bloqueos[] = 'El contrato tiene una orden técnica en curso. Ciérrela antes de ceder.';
        }

        if ($cesionario) {
            if ($cesionario->id === $origen->client_id) {
                $bloqueos[] = 'El nuevo titular es el mismo cliente que ya tiene el contrato.';
            }

            // La corrida mensual elige los contratos por la sucursal del
            // CLIENTE. Un titular de otra sede haría que el contrato se
            // facturara con las reglas y la numeración de otra.
            if ((int) $cesionario->branch_id !== (int) $origen->branch_id) {
                $bloqueos[] = 'El nuevo titular es de otra sucursal. Solo se puede ceder a un cliente de la misma sucursal del contrato.';
            }
        }

        return [
            'bloqueos' => $bloqueos,
            'deuda' => $deuda,
            'facturas_pendientes' => $pendientes->count(),
            'factura_del_mes' => $delMes,
            'cuotas_pendientes' => $cuotas,
            'cierre_pendiente' => $delMes || $cuotas > 0,
            'saldo_a_favor' => $this->saldos->saldo($origen),
        ];
    }

    /**
     * Las facturas de cierre del cedente: el mes en curso y la
     * liquidación de sus cuotas. Devuelve lo que se emitió.
     *
     * @return string[]
     */
    public function emitirCierre(Contract $origen, ?int $userId = null): array
    {
        return DB::transaction(function () use ($origen, $userId) {
            $origen = Contract::whereKey($origen->id)->lockForUpdate()->firstOrFail();

            if (ContractStatus::esFinal($origen->status) || $origen->status === ContractStatus::PorInstalar->value) {
                throw new RuntimeException('Este contrato no se puede ceder, así que no tiene facturas de cierre que emitir.');
            }

            $hechos = [];

            // El mes en curso lo paga el cedente, si todavía no se le facturó.
            if ($this->leFaltaElMes($origen)) {
                $resultado = $this->generador->generateForContract($origen, now(), $userId);

                if ($resultado['generated']) {
                    $hechos[] = sprintf(
                        'factura %s del mes en curso por $%s',
                        $resultado['invoice']->displayNumber(),
                        number_format((float) $resultado['invoice']->total, 2, ',', '.'),
                    );
                }
            }

            // Las cuotas que le quedaban: las contrajo él.
            $liquidacion = $this->liquidador->liquidar($origen, $userId);

            if ($liquidacion) {
                $hechos[] = sprintf(
                    'factura %s de liquidación de sus cuotas por $%s',
                    $liquidacion->displayNumber(),
                    number_format((float) $liquidacion->total, 2, ',', '.'),
                );
            }

            if ($hechos === []) {
                throw new RuntimeException('No había facturas de cierre que emitir.');
            }

            $this->auditLogger->action(
                'contracts.cession_closing',
                sprintf(
                    'Emitió las facturas de cierre del contrato %s antes de cederlo: %s',
                    $origen->numero_visible,
                    implode('; ', $hechos),
                ),
                [
                    'contrato' => $origen->numero_visible,
                    'titular' => $this->identificar($origen->client),
                    'facturas' => $hechos,
                ],
                $origen,
                'contratos',
            );

            return $hechos;
        });
    }

    /**
     * Cede el contrato: cambia el titular. Devuelve el registro de la cesión.
     *
     * @param  array{reason: string, affinity_group_id?: ?int}  $datos
     */
    public function ceder(Contract $origen, Client $cesionario, array $datos, ?int $userId = null): ContractCession
    {
        $motivo = trim((string) ($datos['reason'] ?? ''));

        if ($motivo === '') {
            throw new RuntimeException('Indique el motivo de la cesión: es lo que explicará el cambio de titular dentro de un año.');
        }

        return DB::transaction(function () use ($origen, $cesionario, $datos, $motivo, $userId) {
            // BLOQUEADO hasta el final: dos personas cediendo el mismo
            // contrato a la vez lo dejarían con el titular del último y
            // dos registros de cesión desde el mismo cedente.
            $origen = Contract::whereKey($origen->id)->lockForUpdate()->firstOrFail();

            $revision = $this->revisar($origen, $cesionario);

            if ($revision['bloqueos'] !== []) {
                throw new RuntimeException(implode(' ', $revision['bloqueos']));
            }

            $cedente = $origen->client;
            $parte = ['hechos' => [], 'avisos' => []];
            $cambios = ['client_id' => $cesionario->id];

            // El grupo decide si sus facturas son electrónicas: el
            // cedente era persona natural y el nuevo puede ser una
            // empresa que la exige.
            if (array_key_exists('affinity_group_id', $datos)) {
                $cambios['affinity_group_id'] = $datos['affinity_group_id'] ?: null;
            }

            if ($origen->discount_type) {
                $cambios += [
                    'discount_type' => null,
                    'discount_value' => null,
                    'discount_months' => null,
                    'discount_applied' => 0,
                    'discount_reason' => null,
                ];
                $parte['hechos'][] = 'se quitó el descuento, que era una condición del titular anterior';
            }

            $origen->update($cambios);
            array_unshift($parte['hechos'], 'el contrato ' . $origen->numero_visible . ' quedó a nombre de ' . $cesionario->fullName());

            if ($revision['saldo_a_favor'] > 0) {
                $parte['avisos'][] = sprintf(
                    'el saldo a favor de $%s quedó en el contrato y pagará las próximas facturas del nuevo titular',
                    number_format($revision['saldo_a_favor'], 2, ',', '.'),
                );
            }

            $parte['avisos'][] = 'la descripción de la ONT en la OLT y el comentario de la cuenta en el router '
                . 'siguen con los datos del titular anterior; el sistema los asocia por contrato, pero conviene actualizarlos';

            // El mismo contrato en los dos lados: así se distingue de
            // las cesiones de antes, que abrían un contrato nuevo.
            $cesion = ContractCession::create([
                'from_contract_id' => $origen->id,
                'to_contract_id' => $origen->id,
                'from_client_id' => $cedente?->id,
                'to_client_id' => $cesionario->id,
                'user_id' => $userId,
                'reason' => $motivo,
                'summary' => $parte,
                'ceded_at' => now(),
            ]);

            $this->dejarConstancia($origen, $cedente, $cesionario, $motivo, $userId, $parte, $cesion);

            return $cesion;
        });
    }

    /** El comentario en el contrato y la trazabilidad. */
    private function dejarConstancia(
        Contract $contrato,
        ?Client $cedente,
        Client $cesionario,
        string $motivo,
        ?int $userId,
        array $parte,
        ContractCession $cesion,
    ): void {
        // Es lo que ve quien abre la ficha: sin entrar a la
        // trazabilidad, se entiende por qué cambió el titular. Lleva el
        // DOCUMENTO además del nombre: dos clientes pueden llamarse
        // igual, y es con el documento con lo que se identifica a quien
        // firmó.
        ContractComment::create([
            'contract_id' => $contrato->id,
            'user_id' => $userId,
            'body' => sprintf(
                'Se realizó cesión del contrato %s. Titular anterior: %s. Titular nuevo: %s. Motivo: %s',
                $contrato->numero_visible,
                $this->identificar($cedente),
                $this->identificar($cesionario),
                $motivo,
            ),
        ]);

        $this->auditLogger->action(
            'contracts.ceded',
            sprintf(
                'Cedió el contrato %s de %s a %s',
                $contrato->numero_visible,
                $cedente?->fullName() ?? 'sin cliente',
                $cesionario->fullName(),
            ),
            [
                'contrato' => $contrato->numero_visible,
                'cedente' => $this->identificar($cedente),
                'cesionario' => $this->identificar($cesionario),
                'motivo' => $motivo,
                'hechos' => $parte['hechos'],
                'avisos' => $parte['avisos'],
            ],
            $cesion,
            'contratos',
        );
    }

    /**
     * «CC 1037045539 — Rebeca Arango»: documento y nombre completo.
     *
     * El tipo corto (CC, NIT…) si el cliente lo tiene; si no, el nombre
     * del catálogo fiscal. Sin cliente se dice, en vez de dejar un
     * hueco en un registro que es para siempre.
     */
    private function identificar(?Client $cliente): string
    {
        if (!$cliente) {
            return 'sin cliente registrado';
        }

        $tipo = $cliente->type_document ?: ($cliente->tipoDocumento() ?: 'Documento');

        return sprintf('%s %s — %s', $tipo, $cliente->identity_number, $cliente->fullName());
    }

    /**
     * ¿Hay que facturarle el mes en curso al cedente?
     *
     * Solo si su contrato estaba en un estado que la corrida mensual
     * factura y el mes todavía no se le facturó. Un suspendido no se
     * factura, y ceder no puede cambiar eso.
     */
    private function leFaltaElMes(Contract $origen): bool
    {
        if (!in_array($origen->status, ContractStatus::billable(), true)) {
            return false;
        }

        return !Invoice::where('contract_id', $origen->id)
            ->where('billed_year_month', now()->format('Ym'))
            ->exists();
    }
}
