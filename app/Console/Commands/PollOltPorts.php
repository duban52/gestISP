<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltPortPoller;
use App\Services\Snmp\SnmpClient;
use Illuminate\Console\Command;

/**
 * Muestrea el tráfico de los puertos PON y de los uplinks.
 *
 * Alimenta la gráfica del modal de puerto y el aviso de uplink
 * saturado. Cuesta dos recorridos de tabla por OLT, así que una OLT
 * de doscientos puertos cuesta lo mismo que una de ocho.
 *
 *   php artisan olt:poll-ports
 *   php artisan olt:poll-ports --olt=3
 *   php artisan olt:poll-ports --prune=30
 */
class PollOltPorts extends Command
{
    protected $signature = 'olt:poll-ports
                            {--olt= : ID de una OLT específica}
                            {--prune= : Elimina muestras con más de N días}';

    protected $description = 'Muestrea por SNMP el tráfico de los puertos PON y uplinks';

    public function __construct(private readonly OltPortPoller $poller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!SnmpClient::isAvailable()) {
            $this->error('La extensión SNMP de PHP no está instalada en este servidor.');

            return self::FAILURE;
        }

        $consulta = Olt::query()->where('active', true);

        if ($oltId = $this->option('olt')) {
            $consulta->where('id', $oltId);
        }

        $olts = $consulta->get();

        if ($olts->isEmpty()) {
            $this->warn('No hay OLTs activas para consultar.');

            return self::SUCCESS;
        }

        $filas = [];

        foreach ($olts as $olt) {
            $resultado = $this->poller->poll($olt);

            $filas[] = [
                $olt->name,
                $resultado['pon'],
                $resultado['uplinks'],
                $resultado['con_trafico'],
                $resultado['elapsed_ms'] . ' ms',
                $resultado['reachable'] ? 'OK' : 'SIN RESPUESTA',
            ];
        }

        $this->table(
            ['OLT', 'Puertos PON', 'Uplinks', 'Con tráfico', 'Tiempo', 'Estado'],
            $filas
        );

        if ($dias = $this->option('prune')) {
            $borradas = $this->poller->podarMetricasViejas((int) $dias);
            $this->info("Historial: {$borradas} muestras eliminadas (más de {$dias} días).");
        }

        return self::SUCCESS;
    }
}
