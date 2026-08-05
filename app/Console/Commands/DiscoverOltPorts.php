<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltHardwareDiscovery;
use App\Services\Snmp\SnmpClient;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Descubre las tarjetas, los puertos PON y los uplinks de las OLTs.
 *
 * Es lo que hace que al crear una zona o colgar una caja se puedan
 * elegir puertos que todavía están VACÍOS: hasta ahora solo se conocían
 * los puertos donde ya había ONTs, que son justo los que no interesan
 * para crecer.
 *
 *   php artisan olt:discover-ports
 *   php artisan olt:discover-ports --olt=3
 */
class DiscoverOltPorts extends Command
{
    protected $signature = 'olt:discover-ports
                            {--olt= : ID de una OLT específica}';

    protected $description = 'Descubre por SNMP las tarjetas, puertos PON y uplinks de las OLTs';

    public function __construct(private readonly OltHardwareDiscovery $descubridor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!SnmpClient::isAvailable()) {
            $this->error('La extensión SNMP de PHP no está instalada en este servidor.');
            $this->line('  Instálela con: apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-snmp');

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
        $huboError = false;

        foreach ($olts as $olt) {
            try {
                $resumen = $this->descubridor->descubrir($olt);

                $filas[] = [
                    $olt->name,
                    $resumen['interfaces'],
                    $resumen['tarjetas'],
                    $resumen['pon'] . ($resumen['pon_nuevos'] ? " (+{$resumen['pon_nuevos']})" : ''),
                    $resumen['uplinks'],
                    'OK',
                ];
            } catch (RuntimeException $e) {
                // Una OLT que falla no puede detener a las demás: en un
                // ISP con seis OLTs, que una esté caída es normal.
                $huboError = true;
                $filas[] = [$olt->name, '—', '—', '—', '—', $e->getMessage()];
            }
        }

        $this->table(
            ['OLT', 'Interfaces', 'Tarjetas', 'Puertos PON', 'Uplinks', 'Estado'],
            $filas
        );

        return $huboError ? self::FAILURE : self::SUCCESS;
    }
}
