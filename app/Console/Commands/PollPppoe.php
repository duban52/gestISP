<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\PppoePoller;
use Illuminate\Console\Command;

/**
 * Muestrea el tráfico de las cuentas PPPoE de cada router.
 *
 * Alimenta la gráfica de ancho de banda de la vista de detalle de
 * la cuenta. Una petición por router, sin importar cuántos
 * clientes tenga.
 *
 *   php artisan pppoe:poll
 *   php artisan pppoe:poll --router=2
 *   php artisan pppoe:poll --prune=30
 */
class PollPppoe extends Command
{
    protected $signature = 'pppoe:poll
                            {--router= : ID de un router específico}
                            {--prune= : Elimina muestras con más de N días}';

    protected $description = 'Muestrea el tráfico de las cuentas PPPoE y guarda el historial';

    public function __construct(private readonly PppoePoller $poller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Router::query();

        if ($routerId = $this->option('router')) {
            $query->where('id', $routerId);
        }

        $routers = $query->get();

        if ($routers->isEmpty()) {
            $this->warn('No hay routers registrados.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($routers as $router) {
            $result = $this->poller->poll($router);

            $rows[] = [
                $router->name,
                $result['accounts'],
                $result['connected'],
                $result['elapsed_ms'] . ' ms',
                $result['reachable'] ? 'OK' : 'SIN RESPUESTA',
            ];
        }

        $this->table(['Router', 'Cuentas', 'Conectadas', 'Tiempo', 'Estado'], $rows);

        if ($days = $this->option('prune')) {
            $deleted = $this->poller->pruneOldMetrics((int) $days);
            $this->info("Historial: {$deleted} muestras eliminadas (más de {$days} días).");
        }

        return self::SUCCESS;
    }
}
