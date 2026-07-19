<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\Ont;
use App\Services\OltSnmpService;
use Illuminate\Console\Command;

/**
 * Mapea las interfaces de la OLT y actualiza los índices SNMP de
 * las ONTs.
 *
 *  - if_index: ifIndex del puerto PON (para las métricas ópticas).
 *  - traffic_if_index: ifIndex propio de cada ONT (para el tráfico),
 *    con la opción --traffic.
 *
 * Sirve para reparar ONTs que quedaron sin índice. Las que se
 * activan ahora ya lo guardan solas.
 *
 *   php artisan olt:sync-interfaces
 *   php artisan olt:sync-interfaces --olt=2 --traffic
 */
class SyncOltInterfaces extends Command
{
    protected $signature = 'olt:sync-interfaces
                            {--olt= : ID de una OLT específica}
                            {--traffic : Resuelve además el ifIndex de tráfico de cada ONT}';

    protected $description = 'Mapea ifIndex SNMP → slot/port y actualiza los índices de las ONTs';

    public function __construct(private readonly OltSnmpService $snmp)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Olt::query();

        if ($oltId = $this->option('olt')) {
            $query->where('id', $oltId);
        }

        foreach ($query->get() as $olt) {
            $this->info("Sincronizando interfaces de {$olt->name}...");

            $start = microtime(true);

            // Un solo recorrido de ifDescr con GETBULK (el código
            // anterior usaba un walk SNMPv1, mucho más lento)
            $descriptions = $this->snmp->interfaceDescriptions($olt);

            if (empty($descriptions)) {
                $this->warn('  Sin respuesta SNMP.');
                continue;
            }

            $ms = round((microtime(true) - $start) * 1000);
            $this->line('  ' . count($descriptions) . " interfaces leídas en {$ms} ms.");

            $updated = $this->mapPonPorts($olt, $descriptions);
            $this->info("  ONTs con if_index actualizado: {$updated}");

            if ($this->option('traffic')) {
                $withTraffic = $this->mapOntInterfaces($olt, $descriptions);
                $this->info("  ONTs con ifIndex de tráfico resuelto: {$withTraffic}");
            }
        }

        $this->info('Completado.');

        return self::SUCCESS;
    }

    /**
     * Asocia cada puerto PON (GPON_UNI frame/slot/port) con su
     * ifIndex y lo aplica a las ONTs de ese puerto.
     */
    private function mapPonPorts(Olt $olt, array $descriptions): int
    {
        $updated = 0;

        foreach ($descriptions as $ifIndex => $descr) {
            if (!preg_match('/GPON_UNI\s+(\d+)\/(\d+)\/(\d+)/', $descr, $m)) {
                continue;
            }

            [, $frame, $slot, $port] = $m;

            $affected = Ont::where('olt_id', $olt->id)
                ->where('slot', (int) $slot)
                ->where('port', (int) $port)
                ->update(['if_index' => $ifIndex]);

            if ($affected > 0) {
                $updated += $affected;
                $this->line("  · GPON_UNI {$frame}/{$slot}/{$port} → ifIndex {$ifIndex} ({$affected} ONT)");
            }
        }

        return $updated;
    }

    /**
     * Resuelve el ifIndex individual de cada ONT (tráfico).
     */
    private function mapOntInterfaces(Olt $olt, array $descriptions): int
    {
        $resolved = 0;

        foreach (Ont::where('olt_id', $olt->id)->get() as $ont) {
            $ifIndex = $this->snmp->resolveOntIfIndex($olt, $ont, $descriptions);

            if ($ifIndex) {
                $ont->update(['traffic_if_index' => $ifIndex]);
                $resolved++;
            }
        }

        return $resolved;
    }
}
