<?php

namespace App\Console\Commands;

use App\Jobs\ImportOltOnts;
use App\Models\Olt;
use App\Models\OntImportRun;
use App\Services\OltOntDiscovery;
use Illuminate\Console\Command;

/**
 * Importa por consola las ONTs existentes en una OLT.
 *
 * Alternativa a la pantalla web, útil para OLTs muy grandes o
 * para ejecutarla en una ventana de mantenimiento.
 *
 *   php artisan onts:import 4 --preview   (solo analiza)
 *   php artisan onts:import 4             (importa)
 */
class ImportOnts extends Command
{
    protected $signature = 'onts:import
                            {olt : ID de la OLT}
                            {--preview : Solo muestra qué se importaría, sin escribir nada}';

    protected $description = 'Importa a GestISP las ONTs que ya existen en una OLT';

    public function handle(OltOntDiscovery $discovery): int
    {
        $olt = Olt::find($this->argument('olt'));

        if (!$olt) {
            $this->error('No existe esa OLT.');

            return self::FAILURE;
        }

        $this->info("OLT: {$olt->name} ({$olt->ip_address})");
        $this->line('Leyendo el inventario del equipo...');

        try {
            $resumen = $discovery->preview($olt);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['ONTs en la OLT', 'Se importarían', 'Ya registradas', 'Sin ubicación'],
            [[$resumen['total'], $resumen['nuevas'], $resumen['existentes'], $resumen['sin_ubicacion']]]
        );

        if (!empty($resumen['muestra'])) {
            $this->newLine();
            $this->line('Muestra de lo que se importaría:');
            $this->table(
                ['Serial', 'Ubicación', 'ONT ID', 'Descripción', 'Contrato'],
                collect($resumen['muestra'])->map(fn ($o) => [
                    $o['sn'],
                    $o['slot'] !== null ? "0/{$o['slot']}/{$o['port']}" : 'sin ubicar',
                    $o['onu_id'],
                    mb_substr($o['description'] ?: '—', 0, 35),
                    $o['contract_id'] ?? '—',
                ])->all()
            );
        }

        if ($this->option('preview')) {
            $this->comment('Modo --preview: no se escribió nada.');

            return self::SUCCESS;
        }

        if ($resumen['nuevas'] === 0) {
            $this->info('No hay ONTs nuevas por importar.');

            return self::SUCCESS;
        }

        if (!$this->confirm("¿Importar {$resumen['nuevas']} ONT(s) a GestISP?", true)) {
            $this->comment('Cancelado.');

            return self::SUCCESS;
        }

        $run = OntImportRun::create([
            'olt_id' => $olt->id,
            'branch_id' => $olt->branch_id,
            'user_id' => null, // ejecución por consola
            'status' => OntImportRun::ESTADO_PENDIENTE,
            'message' => 'Iniciada desde consola.',
        ]);

        // Se ejecuta en el momento (no en cola) para poder mostrar
        // el resultado en la misma consola
        dispatch_sync(new ImportOltOnts($run->id));

        $run->refresh();

        $this->newLine();

        if ($run->status === OntImportRun::ESTADO_FALLIDO) {
            $this->error('La importación falló: ' . $run->message);

            return self::FAILURE;
        }

        $this->info($run->message);

        return self::SUCCESS;
    }
}
