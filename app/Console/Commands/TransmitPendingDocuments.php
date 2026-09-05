<?php

namespace App\Console\Commands;

use App\Jobs\TransmitElectronicDocument;
use App\Models\ElectronicDocument;
use Illuminate\Console\Command;

/**
 * Encola los documentos electrónicos que están firmados y sin transmitir.
 *
 *     php artisan dian:transmitir
 *     php artisan dian:transmitir --dry-run
 *     php artisan dian:transmitir --empresa=3
 *
 * PARA QUÉ HACE FALTA SI YA SE ENCOLA SOLO
 * ----------------------------------------
 * Porque «se encola solo» falla de maneras que hay que poder reparar:
 * la cola se cayó y se perdieron trabajos, la URL de la DIAN se
 * configuró después de emitir un lote, o hubo una jornada en la que su
 * servicio estuvo caído y se agotaron los reintentos.
 *
 * En todos esos casos quedan documentos firmados que nadie ha
 * transmitido, y hace falta una forma de barrerlos que no sea
 * reemitirlos —reemitir gastaría consecutivos autorizados por nada—.
 *
 * NO SE SALTA LA IDEMPOTENCIA
 * ---------------------------
 * Encola, no transmite. Quien decide si de verdad hay que mandarlo
 * sigue siendo `DocumentTransmitter`, que no reenvía nada que ya esté
 * aceptado o rechazado. Correr esto dos veces seguidas no duplica nada.
 */
class TransmitPendingDocuments extends Command
{
    protected $signature = 'dian:transmitir
                            {--empresa= : Solo los de esta empresa}
                            {--limite=200 : Cuántos encolar como máximo}
                            {--dry-run : Enseña lo que haría, sin encolar nada}';

    protected $description = 'Encola los documentos electrónicos firmados que todavía no ha aceptado la DIAN';

    public function handle(): int
    {
        $pendientes = ElectronicDocument::withoutGlobalScopes()
            ->where('status', ElectronicDocument::FIRMADO)
            ->when($this->option('empresa'), fn ($q, $empresa) => $q->where('company_id', $empresa))
            ->orderBy('id')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay documentos firmados pendientes de transmitir.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Documentos firmados sin transmitir: %d', $pendientes->count()));

        $this->table(
            ['Documento', 'Factura', 'Intentos', 'Último error'],
            $pendientes->map(fn (ElectronicDocument $documento) => [
                $documento->id,
                $documento->invoice?->full_number ?? '—',
                $documento->attempts,
                \Illuminate\Support\Str::limit((string) $documento->last_error, 60) ?: '—',
            ]),
        );

        if ($this->option('dry-run')) {
            $this->comment('En seco: no se encoló nada.');

            return self::SUCCESS;
        }

        foreach ($pendientes as $documento) {
            TransmitElectronicDocument::dispatch($documento->id);
        }

        $this->info(sprintf('Encolados %d documentos.', $pendientes->count()));

        return self::SUCCESS;
    }
}
