<?php

namespace App\Console\Commands;

use App\Billing\Dian\Transport\DianTestSetTransport;
use App\Billing\Dian\Transport\TransmissionResult;
use App\Models\Company;
use App\Models\ElectronicDocument;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Manda el set de pruebas de la habilitación.
 *
 *     php artisan dian:set-de-pruebas --empresa=3
 *     php artisan dian:set-de-pruebas --empresa=3 --dry-run
 *
 * QUÉ ES EL SET DE PRUEBAS
 * ------------------------
 * El trámite con el que la DIAN comprueba que su software emite bien
 * antes de dejarle emitir de verdad. Se le manda un lote de documentos
 * a un identificador de set que ella asigna, y cuando los da todos por
 * buenos, habilita a la empresa.
 *
 * ES OTRA CONVERSACIÓN, NO EL ENVÍO DE CADA DÍA
 * ---------------------------------------------
 * Van VARIOS documentos en un mismo ZIP, con `SendTestSetAsync`, y la
 * DIAN no contesta si están bien: contesta una `ZipKey` de acuse. El
 * resultado se consulta después. Por eso «enviado» aquí **no** quiere
 * decir «aprobado», y este comando no marca a nadie como habilitado.
 * Encender la producción es otra cosa, con su propio guardián
 * (`dian:habilitar`).
 *
 * QUÉ DOCUMENTOS MANDA
 * --------------------
 * Los documentos electrónicos ya generados y **firmados** de la empresa
 * que todavía no ha aceptado la DIAN. No los inventa: generar facturas
 * sintéticas dentro de una base de producción es exactamente la clase de
 * cosa que no debe hacer un comando por su cuenta.
 *
 * Qué mezcla concreta de documentos exige la DIAN —con descuento, con
 * varios impuestos, etc.— sale del set que ella asigna, y hay que
 * producirla emitiendo en el ambiente de pruebas.
 */
class SendDianTestSet extends Command
{
    protected $signature = 'dian:set-de-pruebas
                            {--empresa= : La empresa que se habilita}
                            {--limite=50 : Cuántos documentos incluir como máximo}
                            {--dry-run : Enseña lo que mandaría, sin mandarlo}';

    protected $description = 'Manda el set de pruebas de la habilitación a la DIAN';

    public function handle(DianTestSetTransport $transporte, AuditLogger $trazabilidad): int
    {
        $empresa = Company::find($this->option('empresa'));

        if (!$empresa) {
            $this->error('Indique una empresa existente: --empresa=ID');

            return self::FAILURE;
        }

        $testSetId = $empresa->dianConfiguration?->test_set_id;

        if (blank($testSetId)) {
            $this->error('La empresa no tiene identificador del set de pruebas.');
            $this->line('Lo asigna la DIAN al configurar el modo de operación para la habilitación.');

            return self::FAILURE;
        }

        $documentos = ElectronicDocument::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('status', ElectronicDocument::FIRMADO)
            ->orderBy('id')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($documentos->isEmpty()) {
            $this->error('No hay documentos firmados que mandar.');
            $this->line('Emita facturas electrónicas en el ambiente de pruebas para producirlos.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Set de pruebas de %s — %d documento(s), set %s',
            $empresa->nombreVisible(),
            $documentos->count(),
            $testSetId,
        ));

        $this->table(
            ['Documento', 'Factura', 'CUFE'],
            $documentos->map(fn (ElectronicDocument $documento) => [
                $documento->id,
                $documento->invoice?->full_number ?? '—',
                substr((string) $documento->cufe, 0, 20) . '…',
            ]),
        );

        if ($this->option('dry-run')) {
            $this->comment('En seco: no se mandó nada.');

            return self::SUCCESS;
        }

        if (!$this->confirm('¿Mandar el set a la DIAN?', false)) {
            $this->line('No se ha mandado nada.');

            return self::SUCCESS;
        }

        // El nombre de cada archivo dentro del ZIP lleva el CUFE: es lo
        // que permite cruzar la respuesta de la DIAN con el documento.
        $lote = $documentos
            ->mapWithKeys(fn (ElectronicDocument $documento) => [
                $documento->cufe . '.xml' => (string) $documento->signed_xml,
            ])
            ->all();

        $resultado = $transporte->enviarSetDePruebas($lote, $testSetId);

        $trazabilidad->action(
            'dian.set_de_pruebas',
            sprintf('Mandó el set de pruebas de %s (%d documentos)', $empresa->nombreVisible(), count($lote)),
            [
                'test_set_id' => $testSetId,
                'documentos' => $documentos->pluck('id')->all(),
                'resultado' => $resultado->resultado,
                'zip_key' => $resultado->trackId,
                'errores' => $resultado->errores,
            ],
            $empresa,
            'facturacion',
        );

        if ($resultado->resultado !== TransmissionResult::ACEPTADO) {
            $this->error('No se pudo mandar el set:');

            foreach ($resultado->errores as $error) {
                $this->line('  · ' . $error);
            }

            return self::FAILURE;
        }

        $this->info('Set recibido por la DIAN.');
        $this->line('ZipKey: <options=bold>' . $resultado->trackId . '</>');
        $this->newLine();
        $this->warn('Recibido NO es aprobado: la DIAN valida después.');
        $this->line('Cuando apruebe la habilitación, enciéndala con:');
        $this->line('  php artisan dian:habilitar --empresa=' . $empresa->id);

        return self::SUCCESS;
    }
}
