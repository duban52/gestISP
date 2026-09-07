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
                            {--dry-run : Muestra cuántos se borrarían, sin borrar}
                            {--force : No pregunta. Lo usa el planificador}
                            {--resumen : Enseña quién está llenando la tabla y no borra nada}';

    protected $description = 'Elimina los registros de trazabilidad más antiguos que el periodo indicado';

    public function handle(): int
    {
        // El resumen va ANTES de todo lo demas: es una consulta y no
        // borra nada, y es lo que hay que mirar cuando la tabla crece
        // sin motivo aparente.
        if ($this->option('resumen')) {
            return $this->resumen();
        }

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

        // `--force` para el planificador. Sin el, `confirm()` en un
        // proceso sin terminal devuelve el valor por defecto —false— y
        // la tarea programada no borraria NUNCA nada, sin dar ningun
        // error. Es la clase de fallo que se descubre mirando el tamaño
        // de la base seis meses despues.
        if (!$this->option('force')
            && !$this->confirm("¿Eliminar definitivamente {$total} registro(s) de auditoría?", false)) {
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

    /**
     * Quien esta llenando la tabla.
     *
     * POR QUE EXISTE
     * --------------
     * Porque `audits` llego a 4,31 GB y 7.319.292 filas en un sistema
     * con 15 contratos, y averiguar de donde salian costo leer tres
     * clases y una lista de configuracion. Con esto es una consulta.
     *
     * La causa era `OltPortMetric`: no estaba en la lista de exclusion,
     * y `olt:poll-ports` escribe una lectura por puerto PON cada cinco
     * minutos. La telemetria no la hace ninguna persona y no tiene por
     * que auditarse.
     *
     * Si algun dia vuelve a crecer, esto lo dice en un comando en vez
     * de en una tarde.
     */
    private function resumen(): int
    {
        $total = Audit::count();

        if ($total === 0) {
            $this->info('La tabla de trazabilidad esta vacia.');

            return self::SUCCESS;
        }

        $this->info('Filas en `audits`: ' . number_format($total));
        $this->newLine();

        $porModelo = Audit::query()
            ->selectRaw('COALESCE(auditable_type, CONCAT("(peticion) ", COALESCE(category, "?"))) AS origen')
            ->selectRaw('COUNT(*) AS n')
            ->groupBy('origen')
            ->orderByDesc('n')
            ->limit(15)
            ->get();

        $this->table(
            ['Origen', 'Filas', '% del total'],
            $porModelo->map(fn ($f) => [
                $f->origen,
                number_format($f->n),
                sprintf('%.1f %%', $f->n * 100 / $total),
            ]),
        );

        // Un origen que se lleva la mayor parte casi siempre es
        // telemetria colada, no actividad humana.
        $mayor = $porModelo->first();

        if ($mayor && $mayor->n > $total * 0.5) {
            $this->newLine();
            $this->warn(sprintf(
                '«%s» se lleva el %.0f%% de la tabla. Si es telemetria, marque ese modelo '
                . 'con el trait App\Billing\Concerns\NotAudited.',
                $mayor->origen,
                $mayor->n * 100 / $total,
            ));
        }

        $this->newLine();
        $this->comment('Este modo no borra nada. Para depurar: php artisan audits:prune');

        return self::SUCCESS;
    }
}
