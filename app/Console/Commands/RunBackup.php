<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupRepository;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Genera la copia de seguridad de la base de datos.
 *
 * Lo llama el cron del servidor a través de
 * deploy/backup/gestisp-backup.sh, que después empaqueta el código y
 * la configuración y lo envía todo a la NAS.
 *
 *   php artisan backup:run                    (copia automática)
 *   php artisan backup:run --origen=manual    (marcada como manual)
 *   php artisan backup:run --keep=14          (retención distinta)
 *   php artisan backup:run --sin-purgar       (no borra copias viejas)
 *
 * Devuelve 0 si la copia quedó bien y 1 si falló, para que el script
 * del servidor sepa si tiene que avisar.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run
                            {--origen=auto : Marca la copia como "auto" o "manual"}
                            {--keep= : Días de copias que se conservan en el servidor}
                            {--sin-purgar : No borra las copias antiguas}';

    protected $description = 'Genera el volcado comprimido de la base de datos y aplica la retención local';

    public function handle(DatabaseBackup $respaldo, BackupRepository $repositorio, AuditLogger $auditoria): int
    {
        $origen = $this->option('origen') === BackupFile::ORIGEN_MANUAL
            ? BackupFile::ORIGEN_MANUAL
            : BackupFile::ORIGEN_AUTOMATICO;

        $this->info('Generando la copia de seguridad de la base de datos...');
        $inicio = microtime(true);

        try {
            $copia = $respaldo->generar($origen);
        } catch (BackupException $e) {
            // Al log además de a pantalla: la salida del cron se
            // pierde, el log queda
            Log::error('Falló la copia de seguridad programada', ['error' => $e->getMessage()]);
            $this->error('No se pudo generar la copia: ' . $e->getMessage());

            return self::FAILURE;
        }

        $segundos = round(microtime(true) - $inicio, 1);

        $this->info(sprintf(
            'Copia generada: %s (%s en %s segundos)',
            $copia->nombre,
            $copia->tamanoLegible(),
            $segundos,
        ));

        // La ruta completa en su propia línea: el script del servidor
        // la necesita para verificarla antes de enviarla a la NAS
        $this->line($copia->ruta);

        $auditoria->action(
            action: 'backup.created',
            description: sprintf('Generó la copia de seguridad %s (%s)', $copia->nombre, $copia->tamanoLegible()),
            context: ['archivo' => $copia->nombre, 'bytes' => $copia->bytes, 'segundos' => $segundos, 'origen' => $origen],
            category: 'sistema',
        );

        $this->purgar($repositorio);

        return self::SUCCESS;
    }

    /**
     * Aplica la retención local y cuenta lo que se liberó.
     */
    private function purgar(BackupRepository $repositorio): void
    {
        if ($this->option('sin-purgar')) {
            return;
        }

        $dias = $this->option('keep') !== null ? (int) $this->option('keep') : null;
        $resultado = $repositorio->purgar($dias);

        if ($resultado['borradas'] > 0) {
            $this->comment(sprintf(
                'Se eliminaron %d copia(s) antigua(s) del servidor y se liberaron %s.',
                $resultado['borradas'],
                BackupFile::formatearTamano($resultado['liberado']),
            ));
        }

        $libre = $repositorio->espacioLibre();

        if ($libre !== null) {
            $this->line('Espacio libre en disco: ' . BackupFile::formatearTamano($libre));

            // Un aviso a tiempo evita la copia truncada de la semana
            // que viene
            if ($libre < 2 * 1024 * 1024 * 1024) {
                $this->warn('Queda menos de 2 GB libres. Revise el espacio del servidor.');
                Log::warning('Poco espacio libre en el servidor de gestISP', ['bytes_libres' => $libre]);
            }
        }
    }
}
