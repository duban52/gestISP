<?php

namespace App\Console\Commands;

use App\Models\Audit;
use Illuminate\Console\Command;

/**
 * Depura la bitácora de trazabilidad.
 *
 * NO se ejecuta solo: borrar registros de auditoría es una decisión
 * del responsable del sistema, no algo que deba pasar por su cuenta.
 * Por eso el comando no está programado en el scheduler.
 *
 *   php artisan audits:prune                 (usa config/audit.php)
 *   php artisan audits:prune --days=365
 *   php artisan audits:prune --days=365 --dry-run
 */
class PruneAudits extends Command
{
    protected $signature = 'audits:prune
                            {--days= : Días de historial que se conservan}
                            {--dry-run : Muestra cuántos se borrarían, sin borrar}';

    protected $description = 'Elimina los registros de trazabilidad más antiguos que el periodo indicado';

    public function handle(): int
    {
        $dias = (int) ($this->option('days') ?: config('audit.retention_days', 730));

        if ($dias < 30) {
            $this->error('Por seguridad no se permite conservar menos de 30 días.');

            return self::FAILURE;
        }

        $limite = now()->subDays($dias);
        $total = Audit::where('created_at', '<', $limite)->count();

        $this->info("Se conservan los últimos {$dias} días (desde {$limite->format('d/m/Y')}).");
        $this->info('Registros anteriores a esa fecha: ' . number_format($total));

        if ($total === 0) {
            $this->comment('No hay nada que depurar.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Modo --dry-run: no se borró nada.');

            return self::SUCCESS;
        }

        if (!$this->confirm("¿Eliminar definitivamente {$total} registro(s) de auditoría?", false)) {
            $this->comment('Operación cancelada.');

            return self::SUCCESS;
        }

        // Por lotes: un DELETE de millones de filas bloquearía la tabla
        $borrados = 0;

        do {
            $lote = Audit::where('created_at', '<', $limite)->limit(1000)->delete();
            $borrados += $lote;
        } while ($lote > 0);

        $this->info('Registros eliminados: ' . number_format($borrados));

        return self::SUCCESS;
    }
}
