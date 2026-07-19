<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OntPoller;
use App\Services\Snmp\SnmpClient;
use Illuminate\Console\Command;

/**
 * Muestrea por SNMP las métricas de todas las ONTs.
 *
 * Reemplaza al antiguo onts:sync-power: además de la potencia
 * guarda temperatura, voltaje, corriente, distancia, estado y
 * tráfico, y deja el historial que alimenta las gráficas.
 *
 *   php artisan onts:poll
 *   php artisan onts:poll --olt=3
 *   php artisan onts:poll --resolve-traffic   (resuelve ifIndex de tráfico)
 *   php artisan onts:poll --prune=30          (limpia historial viejo)
 */
class PollOnts extends Command
{
    protected $signature = 'onts:poll
                            {--olt= : ID de una OLT específica}
                            {--resolve-traffic : Resuelve el ifIndex de tráfico de las ONTs que no lo tengan}
                            {--prune= : Elimina muestras con más de N días}';

    protected $description = 'Muestrea por SNMP las métricas de las ONTs y guarda el historial';

    public function __construct(private readonly OntPoller $poller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!SnmpClient::isAvailable()) {
            $this->error('La extensión SNMP de PHP no está instalada en este servidor.');
            $this->line('  Instálela con: apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-snmp');
            $this->line('  Después reinicie PHP-FPM y verifique con: php -m | grep snmp');

            return self::FAILURE;
        }

        $query = Olt::query()->where('active', true);

        if ($oltId = $this->option('olt')) {
            $query->where('id', $oltId);
        }

        $olts = $query->get();

        if ($olts->isEmpty()) {
            $this->warn('No hay OLTs activas para consultar.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($olts as $olt) {
            $result = $this->poller->poll($olt, (bool) $this->option('resolve-traffic'));

            $rows[] = [
                $olt->name,
                $result['onts'],
                $result['updated'],
                $result['with_traffic'],
                $result['elapsed_ms'] . ' ms',
                ($result['reachable'] ?? true) ? 'OK' : 'SIN RESPUESTA',
            ];
        }

        $this->table(
            ['OLT', 'ONTs', 'Actualizadas', 'Con tráfico', 'Tiempo', 'Estado'],
            $rows
        );

        if ($days = $this->option('prune')) {
            $deleted = $this->poller->pruneOldMetrics((int) $days);
            $this->info("Historial: {$deleted} muestras eliminadas (más de {$days} días).");
        }

        return self::SUCCESS;
    }
}
