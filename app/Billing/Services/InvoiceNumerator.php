<?php

namespace App\Billing\Services;

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
        return 'FAC' . $branchId;
    }

    /**
     * Asigna número a la factura y la persiste.
     */
    public function assign(Invoice $invoice): Invoice
    {
        $sequence = $this->lockActiveSequence($invoice->branch_id);

        $next = max($sequence->current_number + 1, $sequence->range_start);

        if ($sequence->range_end !== null && $next > $sequence->range_end) {
            throw new RuntimeException(
                "La secuencia de numeración {$sequence->prefix} agotó su rango autorizado " .
                "({$sequence->range_start}-{$sequence->range_end}). Registre una nueva resolución/secuencia."
            );
        }

        $sequence->update(['current_number' => $next]);

        $invoice->update([
            'prefix' => $sequence->prefix,
            'number' => $next,
            'full_number' => $sequence->prefix . '-' . $next,
            'numbering_sequence_id' => $sequence->id,
        ]);

        return $invoice;
    }

    /**
     * Obtiene y bloquea la secuencia activa de la sucursal,
     * creándola (interna, sin resolución) si aún no existe.
     */
    private function lockActiveSequence(int $branchId): InvoiceNumberingSequence
    {
        $sequence = InvoiceNumberingSequence::where('branch_id', $branchId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if ($sequence) {
            return $sequence;
        }

        InvoiceNumberingSequence::firstOrCreate(
            ['branch_id' => $branchId, 'active' => true],
            ['prefix' => $this->defaultPrefix($branchId), 'range_start' => 1, 'current_number' => 0]
        );

        // Releer con lock (firstOrCreate no bloquea)
        return InvoiceNumberingSequence::where('branch_id', $branchId)
            ->where('active', true)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
