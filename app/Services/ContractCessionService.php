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
use App\Models\ContractStatusOption;
use App\Models\Invoice;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Models\TechnicalOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cesión de contrato: el servicio pasa a otro titular sin cortarse.
 *
 * QUÉ HACE, EN ORDEN
 * ------------------
 *  1. Comprueba que se puede ceder (ver `revisar()`): sin eso no se
 *     toca nada.
 *  2. Cierra la cuenta del cedente en SU contrato:
 *       · el mes en curso, si todavía no se le facturó;
 *       · la liquidación de sus cuotas pendientes (equipos financiados).
 *  3. Abre un contrato nuevo para el cesionario con los datos del
 *     servicio: plan, dirección, ubicación, credenciales, grupo.
 *  4. Le pasa los equipos y el puerto NAP. SIN TOCAR LA RED: la ONT, la
 *     cuenta PPPoE y la fibra son las mismas y siguen funcionando.
 *  5. Deja el contrato viejo «Cedido», con sus facturas, sus pagos y su
 *     historial a nombre de quien los contrajo.
 *
 * Todo en UNA transacción. Si algo falla a mitad, no queda un cedente
 * con su liquidación emitida y su contrato todavía vivo, ni un
 * contrato nuevo sin equipos.
 *
 * POR QUÉ UN CONTRATO NUEVO
 * -------------------------
 * Ver la migración de `contract_cessions`: las facturas leen al
 * cliente EN VIVO del contrato. Cambiar el titular del viejo pondría a
 * nombre del nuevo todo el historial fiscal del anterior.
 *
 * LO QUE NO HACE, A PROPÓSITO
 * ---------------------------
 *  · No pasa el descuento: era una condición pactada con el cedente.
 *  · No manda la bienvenida: dice «coordinaremos la instalación», y
 *    aquí no hay nada que instalar.
 *  · No cambia la descripción de la ONT en la OLT ni el comentario del
 *    secret en el router. Siguen con los datos del cedente; el sistema
 *    los asocia por contrato, no por esos textos. Se avisa en el parte.
 */
class ContractCessionService
{
    public function __construct(
        private readonly InvoiceGenerator $generador,
        private readonly ContractLiquidator $liquidador,
        private readonly CreditBalanceService $saldos,
        private readonly OdnManager $odn,
        private readonly ContractNumberGenerator $numeros,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Qué impide ceder este contrato, y qué pasará si se cede.
     *
     * Lo usa la pantalla ANTES de confirmar: quien cede tiene que ver
     * qué se le va a facturar al cedente antes de hacerlo, no después.
     *
     * @return array{bloqueos: string[], deuda: float, facturas_pendientes: int,
     *               factura_del_mes: bool, cuotas_pendientes: int, estado_nuevo: ?string}
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

        // ---- Paz y salvo ----
        //
        // Decisión del negocio: no se cede con deuda. La cesión emite
        // después las facturas de cierre —el mes en curso y la
        // liquidación—, que el cedente tendrá que pagar; lo que se exige
        // aquí es que no deba nada de ANTES.
        $pendientes = Invoice::where('contract_id', $origen->id)
            ->whereIn('status', InvoiceStatus::payable())
            ->where('pending_invoice_amount', '>', 0)
            ->get(['pending_invoice_amount']);

        $deuda = round((float) $pendientes->sum('pending_invoice_amount'), 2);

        if ($deuda > 0) {
            $bloqueos[] = sprintf(
                'El cedente debe $%s en %d factura(s). Para ceder el contrato tiene que estar a paz y salvo.',
                number_format($deuda, 2, ',', '.'),
                $pendientes->count(),
            );
        }

        // Una orden abierta quedaría colgada del contrato viejo: una
        // instalación, un corte o una reconexión a medio hacer sobre un
        // servicio que ya es de otro.
        if (TechnicalOrder::where('contract_id', $origen->id)->where('status', '!=', 'Cerrada')->exists()) {
            $bloqueos[] = 'El contrato tiene una orden técnica en curso. Ciérrela antes de ceder.';
        }

        if ($cesionario) {
            if ($cesionario->id === $origen->client_id) {
                $bloqueos[] = 'El nuevo titular es el mismo cliente que ya tiene el contrato.';
            }

            // La corrida mensual elige los contratos por la sucursal del
            // CLIENTE. Un cesionario de otra sede haría que el contrato
            // se facturara con las reglas y la numeración de otra.
            if ((int) $cesionario->branch_id !== (int) $origen->branch_id) {
                $bloqueos[] = 'El nuevo titular es de otra sucursal. Solo se puede ceder a un cliente de la misma sucursal del contrato.';
            }
        }

        return [
            'bloqueos' => $bloqueos,
            'deuda' => $deuda,
            'facturas_pendientes' => $pendientes->count(),
            'factura_del_mes' => $this->leFaltaElMes($origen),
            'cuotas_pendientes' => $origen->additionalCharges()->where('status', 'pendiente')->count(),
            'estado_nuevo' => $this->estadoDelNuevo($origen),
        ];
    }

    /**
     * Cede el contrato. Devuelve el registro de la cesión.
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
            // contrato a la vez dejarían dos contratos nuevos con los
            // mismos equipos.
            $origen = Contract::whereKey($origen->id)->lockForUpdate()->firstOrFail();

            $revision = $this->revisar($origen, $cesionario);

            if ($revision['bloqueos'] !== []) {
                throw new RuntimeException(implode(' ', $revision['bloqueos']));
            }

            $parte = ['hechos' => [], 'avisos' => []];

            // ---- 1. Las facturas de cierre del cedente ----
            $this->cerrarCuentaDelCedente($origen, $userId, $parte);

            // ---- 2. Lo que el viejo tiene que SOLTAR antes ----
            //
            // `cpe_sn`, `user_pppoe` y `nap_port_id` son únicos en la
            // base: el contrato nuevo no puede nacer con ellos mientras
            // el viejo los tenga. Se copian primero y se sueltan después.
            $servicio = $this->datosDelServicio($origen);
            $puerto = $origen->nap_port_id ? $origen->napPort()->with('napBox')->first() : null;

            if ($puerto) {
                $this->odn->liberarPuerto($origen);
            }

            $origen->update([
                'user_pppoe' => null,
                'password_pppoe' => null,
                'cpe_sn' => null,
            ]);

            // ---- 3. El contrato nuevo ----
            $nuevo = $this->contratoNuevo($origen, $servicio, $cesionario, $datos, $userId);
            $parte['hechos'][] = 'se creó el contrato ' . $nuevo->numero_visible . ' a nombre del nuevo titular';

            // ---- 4. Equipos y puerto: cambian de contrato, no de red ----
            $this->pasarEquipos($origen, $nuevo, $puerto, $parte);

            // ---- 5. El contrato viejo queda cerrado ----
            $origen->update(['status' => ContractStatus::Cedido->value]);

            // Lo que le quedó a favor al cedente —pagó por adelantado—
            // sigue siendo suyo: no se regala al cesionario.
            $saldo = $this->saldos->saldo($origen);

            if ($saldo > 0) {
                $parte['avisos'][] = sprintf(
                    'Al cedente le queda un saldo a favor de $%s en el contrato %s: es suyo y hay que devolvérselo',
                    number_format($saldo, 2, ',', '.'),
                    $origen->numero_visible,
                );
            }

            $parte['avisos'][] = 'la descripción de la ONT en la OLT y el comentario de la cuenta en el router '
                . 'siguen con los datos del cedente; el sistema los asocia por contrato, pero conviene actualizarlos';

            $cesion = ContractCession::create([
                'from_contract_id' => $origen->id,
                'to_contract_id' => $nuevo->id,
                'from_client_id' => $origen->client_id,
                'to_client_id' => $cesionario->id,
                'user_id' => $userId,
                'reason' => $motivo,
                'summary' => $parte,
                'ceded_at' => now(),
            ]);

            $this->dejarConstancia($origen, $nuevo, $cesionario, $motivo, $userId, $parte, $cesion);

            return $cesion;
        });
    }

    // ==================== Los pasos ====================

    /**
     * El mes en curso y la liquidación de las cuotas pendientes.
     *
     * Van ANTES de cerrar el contrato: un contrato «Cedido» ya no es
     * facturable, y el propio sistema rechazaría emitirle estas
     * facturas.
     */
    private function cerrarCuentaDelCedente(Contract $origen, ?int $userId, array &$parte): void
    {
        // El mes en curso lo paga el cedente. Si ya se le facturó, no
        // se hace nada; si no, se le factura ahora.
        if ($this->leFaltaElMes($origen)) {
            $resultado = $this->generador->generateForContract($origen, now(), $userId);

            if ($resultado['generated']) {
                $parte['hechos'][] = sprintf(
                    'se le facturó al cedente el mes en curso (factura %s por $%s)',
                    $resultado['invoice']->displayNumber(),
                    number_format((float) $resultado['invoice']->total, 2, ',', '.'),
                );
            }
        }

        // Las cuotas que le quedaban: las contrajo él.
        $liquidacion = $this->liquidador->liquidar($origen, $userId);

        if ($liquidacion) {
            $parte['hechos'][] = sprintf(
                'se le liquidaron al cedente sus cuotas pendientes (factura %s por $%s)',
                $liquidacion->displayNumber(),
                number_format((float) $liquidacion->total, 2, ',', '.'),
            );
        }
    }

    /**
     * Lo que se copia del servicio: la misma casa, los mismos equipos.
     *
     * Se toma ANTES de que el viejo suelte sus columnas únicas: después
     * ya no las tiene.
     *
     * @return array<string, mixed>
     */
    private function datosDelServicio(Contract $origen): array
    {
        return [
            'plan_id' => $origen->plan_id,
            'affinity_group_id' => $origen->affinity_group_id,
            'neighborhood' => $origen->neighborhood,
            'address' => $origen->address,
            'municipality' => $origen->municipality,
            'department' => $origen->department,
            'home_type' => $origen->home_type,
            'social_stratum' => $origen->social_stratum,
            'latitude' => $origen->latitude,
            'longitude' => $origen->longitude,
            'located_at' => $origen->located_at,
            'located_by' => $origen->located_by,
            'location_source' => $origen->location_source,
            'cpe_sn' => $origen->cpe_sn,
            'user_pppoe' => $origen->user_pppoe,
            'password_pppoe' => $origen->password_pppoe,
            'ssid_wifi' => $origen->ssid_wifi,
            'password_wifi' => $origen->password_wifi,
            // El cesionario asume lo que le quedaba de permanencia: la
            // cesión continúa el contrato, no lo reinicia.
            'permanence_clause' => $origen->permanence_clause,
        ];
    }

    /**
     * El contrato del cesionario, con los datos del servicio.
     *
     * @param  array<string, mixed>  $servicio  lo que devolvió datosDelServicio()
     */
    private function contratoNuevo(Contract $origen, array $servicio, Client $cesionario, array $datos, ?int $userId): Contract
    {
        // El grupo decide si sus facturas son electrónicas. Puede no ser
        // el mismo: el cedente era persona natural y el nuevo es una
        // empresa que exige factura electrónica.
        if (array_key_exists('affinity_group_id', $datos)) {
            $servicio['affinity_group_id'] = $datos['affinity_group_id'] ?: null;
        }

        $nuevo = Contract::create($servicio + [
            // La empresa la deriva BelongsToCompany de la sucursal.
            'branch_id' => $origen->branch_id,
            'client_id' => $cesionario->id,
            'status' => $this->estadoDelNuevo($origen),
            'activation_date' => now()->toDateString(),
            // El mes de la cesión ya lo paga el cedente.
            'billing_start_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'comment' => 'Recibido por cesión del contrato ' . $origen->numero_visible,
            'user_id' => $userId,
        ]);

        $this->numeros->asignar($nuevo);

        return $nuevo->refresh();
    }

    /**
     * La ONT, las cuentas PPPoE y el puerto NAP pasan al contrato nuevo.
     *
     * NO SE TOCA LA RED: son los mismos equipos, en la misma casa, y
     * siguen dando servicio. Solo cambia a qué contrato pertenecen.
     */
    private function pasarEquipos(Contract $origen, Contract $nuevo, ?\App\Models\NapPort $puerto, array &$parte): void
    {
        // El puerto ya lo soltó el viejo (ver ceder()): el índice único
        // sobre nap_port_id no deja que lo tengan los dos ni un instante.
        if ($puerto) {
            $this->odn->asignarPuerto($nuevo, $puerto->fresh(['napBox']));

            $parte['hechos'][] = 'pasó el puerto ' . $puerto->napBox->code . ' / P' . $puerto->number;
        }

        foreach (PppoeAccount::where('contract_id', $origen->id)->get() as $cuenta) {
            $cuenta->update(['contract_id' => $nuevo->id]);
            $parte['hechos'][] = 'pasó la cuenta PPPoE ' . $cuenta->username . ' (mismas credenciales)';
        }

        foreach (Ont::where('contract_id', $origen->id)->get() as $ont) {
            $ont->update(['contract_id' => $nuevo->id]);
            $parte['hechos'][] = 'pasó la ONT ' . $ont->sn;
        }
    }

    /** El comentario en los dos contratos y la trazabilidad. */
    private function dejarConstancia(
        Contract $origen,
        Contract $nuevo,
        Client $cesionario,
        string $motivo,
        ?int $userId,
        array $parte,
        ContractCession $cesion,
    ): void {
        $nombreCesionario = trim($cesionario->name . ' ' . $cesionario->last_name);
        $nombreCedente = trim(($origen->client?->name ?? '') . ' ' . ($origen->client?->last_name ?? ''));

        // Los comentarios son lo que ve quien abre la ficha: sin
        // entrar a la trazabilidad, se entiende por qué este contrato
        // está cerrado o de dónde salió. Llevan el DOCUMENTO además del
        // nombre: dos clientes pueden llamarse igual, y es con el
        // documento con lo que se identifica a quien firmó.
        ContractComment::create([
            'contract_id' => $nuevo->id,
            'user_id' => $userId,
            'body' => sprintf(
                'Se realizó cesión del contrato %s. Titular anterior: %s. Motivo: %s',
                $origen->numero_visible,
                $this->identificar($origen->client),
                $motivo,
            ),
        ]);

        ContractComment::create([
            'contract_id' => $origen->id,
            'user_id' => $userId,
            'body' => sprintf(
                'Se realizó cesión de este contrato; el servicio sigue en el contrato %s. Titular nuevo: %s. Motivo: %s',
                $nuevo->numero_visible,
                $this->identificar($cesionario),
                $motivo,
            ),
        ]);

        $this->auditLogger->action(
            'contracts.ceded',
            sprintf(
                'Cedió el contrato %s de %s a %s, que queda con el contrato %s',
                $origen->numero_visible,
                $nombreCedente,
                $nombreCesionario,
                $nuevo->numero_visible,
            ),
            [
                'contrato_cedido' => $origen->numero_visible,
                'contrato_nuevo' => $nuevo->numero_visible,
                'cedente' => $nombreCedente,
                'cesionario' => $nombreCesionario,
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

    // ==================== Reglas ====================

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

    /**
     * En qué estado nace el contrato del cesionario.
     *
     * Con servicio, ACTIVO y pagando, sea cual sea la condición del
     * cedente: un «Exonerado» era un beneficio de esa persona, no de
     * la casa. Sin servicio —una suspensión temporal que pidió el
     * cedente—, el mismo estado: cambiar de titular no instala ni
     * reconecta nada.
     */
    private function estadoDelNuevo(Contract $origen): ?string
    {
        $estado = ContractStatusOption::porNombre($origen->status);

        if (!$estado || $estado->has_service) {
            return ContractStatus::Activo->value;
        }

        return $origen->status;
    }
}
