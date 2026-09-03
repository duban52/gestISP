<?php

namespace App\Billing\Services;

use App\Models\NumberingRange;
use App\Models\Branch;
use App\Services\Numbering\DocumentNumberService;
use App\Models\Invoice;
use App\Models\InvoiceNumberingSequence;
use RuntimeException;

/**
 * Asignación de numeración formal a las facturas.
 *
 * Toma la secuencia activa de la sucursal (creándola con un
 * prefijo interno si no existe), incrementa el consecutivo con la
 * fila BLOQUEADA (lockForUpdate) y escribe prefijo, número y
 * número completo en la factura. Dos facturas generadas en
 * paralelo jamás reciben el mismo consecutivo.
 *
 * DEBE ejecutarse dentro de una transacción (InvoiceGenerator ya
 * la abre); el lock vive hasta el commit.
 *
 * Cuando llegue la resolución DIAN solo hay que registrar en la
 * secuencia su número, vigencia y rango — el rango se hace cumplir
 * aquí: si el consecutivo lo agota, la generación falla con un
 * error claro en lugar de emitir números no autorizados.
 */
class InvoiceNumerator
{
    /**
     * Prefijo por defecto de la secuencia interna de cada sucursal
     * (FAC + id de la sucursal, para que el número completo sea
     * único entre sucursales).
     */
    private function defaultPrefix(int $branchId): string
    {
        // Se mantiene FAC, que es lo que ya usan las series en
        // produccion.
        //
        // Se intento cambiarlo a DOC —para que un documento interno no
        // pareciera una factura electronica— y estaba mal pensado por
        // dos motivos:
        //
        //   1. Este metodo solo actua al CREAR una serie. Las
        //      sucursales que ya existen conservarian FAC y solo las
        //      nuevas recibirian DOC: una inconsistencia peor que
        //      cualquiera de las dos opciones.
        //
        //   2. Cambiar el prefijo de una serie viva crea una
        //      discontinuidad en la numeracion que hay que justificar.
        //
        // La distincion que de verdad protege esta en otro sitio: la
        // electronica lleva el prefijo que autorizo la resolucion —uno
        // completamente distinto— y, sobre todo, en que la
        // representacion impresa del documento interno no imita a una
        // factura electronica. Cambiar esto seria una decision
        // deliberada con su migracion, no un efecto secundario.
        return 'FAC' . $branchId;
    }

    /**
     * Asigna número a la factura y la persiste.
     */
    public function assign(Invoice $invoice): Invoice
    {
        // De donde sale el numero depende del TIPO de la factura, que
        // ya viene decidido y congelado:
        //
        //   · Electronica -> del RANGO AUTORIZADO por la resolucion.
        //     Es el unico numero legitimo que puede llevar.
        //   · Interna     -> de su propia serie, que no consume ningun
        //     consecutivo autorizado.
        //
        // Que sean dos tablas distintas no es un detalle de
        // implementacion: es lo que hace imposible que un documento
        // interno gaste un consecutivo de la DIAN.
        $sequence = $invoice->document_kind === ElectronicInvoicingDecider::ELECTRONICO
            ? $this->lockAuthorizedRange($invoice)
            : $this->lockActiveSequence($invoice->branch_id);

        // Sumar uno, comprobar el rango y formatear los hace
        // DocumentNumberService, el mismo que numera contratos y notas.
        // Aqui solo se decide QUE serie y se escribe el resultado en la
        // factura. La comprobacion de rango es lo que mas importa que
        // sea comun: emitir pasado el rango autorizado es emitir con
        // numeros que nadie autorizo, y esa regla no puede tener dos
        // versiones que se separen con el tiempo.
        $numero = app(DocumentNumberService::class)->reservarEn($sequence);

        $invoice->update([
            'prefix' => $sequence->prefix,
            'number' => $numero->consecutivo,
            'full_number' => $numero->completo,
            // Solo para las internas: la columna apunta a
            // invoice_numbering_sequences. De que RANGO salio una
            // electronica queda en su electronic_document, junto con la
            // resolucion que lo autorizo.
            'numbering_sequence_id' => $sequence instanceof InvoiceNumberingSequence
                ? $sequence->id
                : null,
        ]);

        return $invoice;
    }

    /**
     * El rango autorizado con el que numerar una factura electronica.
     *
     * SI NO HAY, FALLA
     * ----------------
     * No se crea uno ni se cae a la serie interna. Un rango de
     * numeracion no se inventa: lo autoriza la DIAN en una resolucion,
     * y emitir con numeros de fuera de ese rango es emitir con numeros
     * que nadie autorizo — que es peor que no emitir.
     *
     * Falla en voz alta, con el motivo, para que se vea al primer
     * intento y no el dia que la DIAN rechace el lote.
     *
     * SE PREFIERE EL RANGO DE LA SUCURSAL
     * -----------------------------------
     * La resolucion se le da al NIT, pero se puede repartir por sede.
     * Si la sucursal tiene el suyo, se usa ese; si no, el de la
     * empresa. Nunca el de OTRA sucursal.
     */
    private function lockAuthorizedRange(Invoice $invoice): NumberingRange
    {
        $companyId = (int) Branch::withoutGlobalScopes()
            ->whereKey($invoice->branch_id)
            ->value('company_id');

        $buscar = fn (?int $branchId) => NumberingRange::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('active', true)
            ->whereHas('resolution', fn ($q) => $q
                ->where('active', true)
                ->whereDate('valid_from', '<=', now())
                ->whereDate('valid_until', '>=', now()))
            ->lockForUpdate()
            ->first();

        $rango = $buscar((int) $invoice->branch_id) ?? $buscar(null);

        if (!$rango) {
            throw new RuntimeException(
                'No hay ningun rango de numeracion autorizado y vigente para emitir esta factura '
                . 'electronica. Registre la resolucion de la DIAN y su rango antes de emitir.'
            );
        }

        return $rango;
    }

    /**
     * Obtiene y bloquea la secuencia activa de la sucursal,
     * creándola (interna, sin resolución) si aún no existe.
     */
    private function lockActiveSequence(int $branchId): InvoiceNumberingSequence
    {
        $kind = ElectronicInvoicingDecider::INTERNO;

        $buscar = fn () => InvoiceNumberingSequence::where('branch_id', $branchId)
            ->where('kind', $kind)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if ($sequence = $buscar()) {
            return $sequence;
        }

        // La serie interna SI se crea sola: es de la empresa y no la
        // autoriza nadie. Es justo lo contrario del rango fiscal.
        InvoiceNumberingSequence::firstOrCreate(
            ['branch_id' => $branchId, 'kind' => $kind, 'active' => true],
            [
                'prefix' => $this->defaultPrefix($branchId),
                'range_start' => 1,
                'current_number' => 0,
            ],
        );

        // Releer con lock (firstOrCreate no bloquea)
        return $buscar() ?? InvoiceNumberingSequence::where('branch_id', $branchId)
            ->where('kind', $kind)
            ->where('active', true)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
