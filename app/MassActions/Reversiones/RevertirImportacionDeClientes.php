<?php

namespace App\MassActions\Reversiones;

use App\MassActions\RevierteUnaAccionMasiva;
use App\Models\Client;
use App\Models\Contract;
use App\Models\CreditDebitNote;
use App\Models\Invoice;
use App\Models\MassActionItem;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Deshace una importación de clientes y contratos: los borra.
 *
 * ES LA REVERSIÓN MÁS PELIGROSA DEL SISTEMA, y por eso es la que más
 * se niega. Una importación equivocada mete mil contratos mal; dejarlos
 * marcados en vez de borrarlos deja la base sucia para siempre. Pero
 * borrar lo que ya tuvo vida es otra cosa.
 *
 * SE BORRA EN CASCADA (decisión del 2026-10-02)
 * ---------------------------------------------
 * El contrato, y con él lo que cuelgue: facturas internas sin cobrar,
 * cargos, notas internas del contrato, el saldo a favor, el puerto de
 * la caja NAP que ocupaba, las cuentas PPPoE que se le crearon, el
 * vínculo con su ONT. El cliente también, si lo creó esta importación
 * y no le quedan otros contratos.
 *
 * LOS TRES FRENOS QUE NO SE PASAN
 * -------------------------------
 * Hay tres cosas que no son «algo que cuelga» sino HECHOS, y un
 * contrato que tenga alguna no se borra: queda en conflicto, con su
 * motivo, para que una persona decida.
 *
 *   1. UN PAGO. Entró dinero y está en el cuadre de una caja. Borrar
 *      la factura dejaría ese dinero sin documento que lo soporte, y
 *      el arqueo de ese día deja de cuadrar para siempre.
 *   2. UNA FACTURA ELECTRÓNICA YA TRANSMITIDA. Existe en los registros
 *      de la DIAN con su CUFE. Borrarla aquí no la borra allá: deja a
 *      los dos lados diciendo cosas distintas, y esa diferencia
 *      aparece el día que alguien los cruce.
 *   3. UNA NOTA CRÉDITO O DÉBITO. Es un documento con su propio
 *      consecutivo, emitido y entregado.
 *
 * La factura de SALDO MIGRADO sí se borra: la creó la propia
 * importación, es interna y mientras no se haya cobrado no soporta
 * ningún hecho.
 */
class RevertirImportacionDeClientes implements RevierteUnaAccionMasiva
{
    public function __construct(
        private readonly AuditLogger $auditoria,
    ) {
    }

    public function revisar(MassActionItem $item): ?string
    {
        $contrato = $item->subject;

        if (!$contrato instanceof Contract) {
            // Ya no está: alguien lo borró antes. No es un fallo —el
            // objetivo era que no existiera—, pero tampoco hay nada
            // que hacer.
            return 'El contrato ya no existe.';
        }

        if ($motivo = $this->freno($contrato)) {
            return $motivo;
        }

        return null;
    }

    /**
     * El hecho que impide borrar este contrato, si lo hay.
     *
     * Se mira con consultas y no cargando las relaciones: un contrato
     * con doscientas facturas no tiene por qué traerlas a memoria para
     * responder «¿alguna tiene pagos?».
     */
    private function freno(Contract $contrato): ?string
    {
        $facturas = Invoice::withoutGlobalScopes()->where('contract_id', $contrato->id)->pluck('id');

        if ($facturas->isNotEmpty()) {
            if (Payment::whereIn('invoice_id', $facturas)->exists()) {
                return 'Tiene facturas con pagos registrados: ese dinero está en el cuadre de una caja.';
            }

            if (CreditDebitNote::whereIn('invoice_id', $facturas)->exists()) {
                return 'Tiene notas crédito o débito emitidas, que son documentos con su propio consecutivo.';
            }

            $transmitida = \App\Models\ElectronicDocument::withoutGlobalScopes()
                ->whereIn('invoice_id', $facturas)
                ->whereNotNull('cufe')
                ->exists();

            if ($transmitida) {
                return 'Tiene facturas electrónicas ya firmadas o transmitidas: existen en los registros de la DIAN.';
            }
        }

        return null;
    }

    public function revertir(MassActionItem $item): void
    {
        /** @var Contract $contrato */
        $contrato = $item->subject;

        $clienteId = $contrato->client_id;
        $numero = $contrato->numero_visible;

        DB::transaction(function () use ($contrato) {
            // El puerto de la caja NAP se libera: lo ocupaba este
            // contrato y si no, queda marcado como usado por nadie.
            if ($contrato->napPort) {
                $contrato->napPort->update(['contract_id' => null]);
            }

            // La ONT no se borra —es un equipo que existe y puede
            // estar en la calle—: se le quita el vínculo.
            $contrato->ont()->update(['contract_id' => null]);

            $facturas = Invoice::withoutGlobalScopes()->where('contract_id', $contrato->id)->pluck('id');

            if ($facturas->isNotEmpty()) {
                \App\Models\InvoiceItem::whereIn('invoice_id', $facturas)->delete();
                Invoice::withoutGlobalScopes()->whereIn('id', $facturas)->forceDelete();
            }

            $contrato->accountCredits()->delete();
            $contrato->additionalCharges()->delete();
            $contrato->comments()->delete();
            $contrato->pppoeAccounts()->delete();

            \App\Models\TechnicalOrder::where('contract_id', $contrato->id)->delete();

            $contrato->delete();
        });

        // El cliente, solo si lo trajo esta importación y se queda sin
        // contratos. Un cliente que ya existía antes no es cosa de esta
        // importación, y uno con otro contrato sigue siendo cliente.
        if ($item->valorAntes('cliente_creado') && $clienteId) {
            $quedan = Contract::withoutGlobalScopes()->where('client_id', $clienteId)->exists();

            if (!$quedan) {
                Client::withoutGlobalScopes()->whereKey($clienteId)->delete();
            }
        }

        $this->auditoria->action(
            'contracts.import_reverted',
            'Borró el contrato ' . $numero . ' al revertir una importación masiva',
            ['contrato' => $numero, 'accion' => $item->mass_action_id],
            null,
            'contratos',
        );
    }

    public function advertencia(): string
    {
        return 'Se BORRARÁN los contratos creados por la importación y lo que cuelga de ellos '
            . '(facturas internas sin cobrar, cargos, comentarios, saldo a favor, cuentas PPPoE '
            . 'y órdenes), además de los clientes que creó la importación y se queden sin '
            . 'contratos. Esto NO se puede deshacer. Los contratos con pagos, con notas o con '
            . 'facturas electrónicas transmitidas a la DIAN no se tocan: quedan en conflicto.';
    }
}
