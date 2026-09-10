<?php

namespace App\Console\Commands;

use App\Billing\Dian\Transport\SoapDianTransport;
use App\Billing\Events\ElectronicDocumentAccepted;
use App\Models\DocumentTransmission;
use App\Models\ElectronicDocument;
use Illuminate\Console\Command;

/**
 * Recupera el acuse de los documentos que la DIAN ya aceptó.
 *
 *     php artisan dian:recuperar-acuses
 *     php artisan dian:recuperar-acuses --dry-run
 *     php artisan dian:recuperar-acuses --entregar
 *
 * QUÉ PROBLEMA RESUELVE
 * ---------------------
 * El acuse —el `ApplicationResponse` con que la DIAN acredita la
 * validación— empezó a guardarse el 2026-09-10. Todo lo aceptado antes
 * lo tiene en blanco, y sin él no se puede armar el `AttachedDocument`
 * que se le entrega al cliente.
 *
 * NO HAY QUE PEDIRLE NADA A LA DIAN. La respuesta SOAP completa quedó
 * guardada en `document_transmissions.response`, y el acuse viaja
 * dentro, en base64. Esto lo desempaqueta y lo guarda donde le
 * corresponde.
 *
 * TAMBIÉN SIRVE SI ALGO FALLA
 * ---------------------------
 * Un documento aceptado cuyo acuse no se guardó —porque el proceso que
 * lo transmitió tenía código viejo cargado, por ejemplo— se arregla
 * corriendo esto, sin reemitir ni volver a transmitir.
 *
 * `--entregar` MANDA CORREOS DE VERDAD
 * ------------------------------------
 * Por eso no viene puesto. Sin él, esto solo rellena la columna; con
 * él, además dispara la entrega de los documentos que nunca se le
 * enviaron al cliente. Es una acción visible para el cliente y se pide
 * a propósito.
 *
 * La guarda de `delivered_at` sigue mandando: lo ya entregado no se
 * entrega otra vez aunque se use `--entregar`.
 */
class RecoverDianReceipts extends Command
{
    protected $signature = 'dian:recuperar-acuses
                            {--empresa= : Solo los de esta empresa}
                            {--dry-run : Enseña lo que haría, sin guardar nada}
                            {--entregar : Además, entrega al cliente lo que nunca se le entregó}';

    protected $description = 'Recupera el acuse de la DIAN desde las respuestas ya guardadas';

    public function handle(SoapDianTransport $transporte): int
    {
        $recuperados = $this->recuperar($transporte);

        if ($this->option('dry-run')) {
            $this->comment('En seco: no se guardó nada.');

            return self::SUCCESS;
        }

        // LAS DOS PASADAS SON INDEPENDIENTES, Y TIENEN QUE SERLO.
        //
        // La primera version entregaba solo lo que habia recuperado en
        // esa misma corrida. Eso hacia la bandera inservible en el uso
        // normal: se recupera primero, se comprueba, y se entrega
        // despues —que es justo cuando ya no queda nada por recuperar—.
        if ($this->option('entregar')) {
            $this->entregar();
        } elseif ($recuperados > 0) {
            $this->newLine();
            $this->comment(
                'Los acuses ya están guardados, pero a estos clientes nunca se les entregó su factura. '
                . 'Para hacerlo: php artisan dian:recuperar-acuses --entregar',
            );
        }

        return self::SUCCESS;
    }

    /**
     * Rellena el acuse de los documentos aceptados que no lo tienen.
     *
     * @return int cuántos se recuperaron
     */
    private function recuperar(SoapDianTransport $transporte): int
    {
        $pendientes = $this->documentos()->whereNull('dian_response_xml')->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay documentos aceptados sin acuse.');

            return 0;
        }

        $this->line(sprintf('Documentos aceptados sin acuse: %d', $pendientes->count()));
        $this->newLine();

        $filas = [];
        $recuperados = 0;

        foreach ($pendientes as $documento) {
            $acuse = $this->acuseDe($documento, $transporte);

            $filas[] = [
                $documento->id,
                $documento->invoice?->full_number ?? ($documento->credit_debit_note_id ? 'nota' : '—'),
                $documento->accepted_at?->format('Y-m-d H:i') ?? '—',
                $acuse ? number_format(strlen($acuse)) . ' bytes' : 'NO SE PUDO',
            ];

            if (!$acuse || $this->option('dry-run')) {
                continue;
            }

            $documento->forceFill(['dian_response_xml' => $acuse])->save();
            $recuperados++;
        }

        $this->table(['Documento', 'Número', 'Aceptado', 'Acuse'], $filas);

        if ($this->option('dry-run')) {
            return 0;
        }

        $this->info(sprintf('Acuses recuperados: %d de %d.', $recuperados, $pendientes->count()));

        if ($recuperados < $pendientes->count()) {
            $this->warn(
                'Los que dicen NO SE PUDO no tienen la respuesta guardada, o no traía el acuse dentro. '
                . 'Esos no se pueden recuperar desde aquí.',
            );
        }

        return $recuperados;
    }

    /**
     * Entrega lo que la DIAN valido y nunca le llego al cliente.
     *
     * Mira TODO lo entregable, no solo lo que se acaba de recuperar:
     * vease el comentario de `handle()`.
     */
    private function entregar(): void
    {
        $sinEntregar = $this->documentos()
            ->whereNotNull('dian_response_xml')
            ->whereNull('delivered_at')
            ->get();

        if ($sinEntregar->isEmpty()) {
            $this->info('No hay facturas validadas pendientes de entregar.');

            return;
        }

        $this->newLine();
        $this->line(sprintf('Facturas validadas sin entregar: %d', $sinEntregar->count()));

        foreach ($sinEntregar as $documento) {
            ElectronicDocumentAccepted::dispatch($documento);
        }

        $this->info(sprintf('Entregas encoladas: %d.', $sinEntregar->count()));
        $this->comment('Hace falta que el worker de la cola esté corriendo para que salgan.');
    }

    /** Los documentos que la DIAN acepto, de la empresa indicada o de todas. */
    private function documentos(): \Illuminate\Database\Eloquent\Builder
    {
        return ElectronicDocument::withoutGlobalScopes()
            ->where('status', ElectronicDocument::ACEPTADO)
            ->when($this->option('empresa'), fn ($q, $empresa) => $q->where('company_id', $empresa))
            ->orderBy('id');
    }

    /**
     * El acuse, sacado de la última respuesta guardada de ese documento.
     *
     * Se recorren TODAS sus transmisiones de la más reciente a la más
     * vieja: un documento con varios intentos puede tener respuestas sin
     * acuse —un error 500— antes de la buena.
     */
    private function acuseDe(ElectronicDocument $documento, SoapDianTransport $transporte): ?string
    {
        $respuestas = DocumentTransmission::withoutGlobalScopes()
            ->where('electronic_document_id', $documento->id)
            ->whereNotNull('response')
            ->orderByDesc('id')
            ->pluck('response');

        foreach ($respuestas as $respuesta) {
            if ($acuse = $transporte->acuseDe((string) $respuesta)) {
                return $acuse;
            }
        }

        return null;
    }
}
