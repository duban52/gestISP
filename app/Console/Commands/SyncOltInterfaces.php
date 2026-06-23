<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\Ont;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncOltInterfaces extends Command
{
    protected $signature   = 'olt:sync-interfaces {--olt= : ID de una OLT específica}';
    protected $description = 'Mapea ifIndex SNMP → slot/port y actualiza if_index en ONTs';

    public function handle(): int
    {
        $oltId = $this->option('olt');

        $query = Olt::query();
        if ($oltId) {
            $query->where('id', $oltId);
        }

        foreach ($query->get() as $olt) {
            $this->info("Sincronizando interfaces de {$olt->name}...");

            $host      = $olt->ip_address . ':' . ($olt->snmp_port ?? 161);
            $community = $olt->read_snmp_comunity;

            $interfaces = @snmprealwalk($host, $community, '1.3.6.1.2.1.2.2.1.2', 2000000, 5);

            if (empty($interfaces)) {
                $this->warn("  Sin respuesta SNMP.");
                continue;
            }

            $mapped   = 0;
            $updated  = 0;

            foreach ($interfaces as $oid => $value) {
                // Solo nos interesan las interfaces GPON_UNI
                if (!str_contains($value, 'GPON_UNI')) {
                    continue;
                }

                // Extraer ifIndex del final del OID
                if (!preg_match('/\.(\d+)$/', $oid, $oidMatch)) {
                    continue;
                }

                // Extraer frame/slot/port del string "...GPON_UNI 0/3/13"
                if (!preg_match('/GPON_UNI\s+(\d+)\/(\d+)\/(\d+)/', $value, $portMatch)) {
                    continue;
                }

                $ifIndex = (int) $oidMatch[1];
                $frame   = (int) $portMatch[1];
                $slot    = (int) $portMatch[2];
                $port    = (int) $portMatch[3];

                $mapped++;

                // Actualizar ONTs que coincidan con este slot/port
                $affected = Ont::where('olt_id', $olt->id)
                    ->where('slot', $slot)
                    ->where('port', $port)
                    ->update(['if_index' => $ifIndex]);

                if ($affected > 0) {
                    $updated += $affected;
                    $this->line("  ✓ GPON_UNI {$frame}/{$slot}/{$port} → ifIndex={$ifIndex} ({$affected} ONT actualizadas)");
                    Log::debug('INTERFACE MAPEADA', [
                        'olt'     => $olt->name,
                        'ifIndex' => $ifIndex,
                        'frame'   => $frame,
                        'slot'    => $slot,
                        'port'    => $port,
                    ]);
                }
            }

            $this->info("  Interfaces GPON encontradas: {$mapped}");
            $this->info("  ONTs actualizadas con if_index: {$updated}");
        }

        $this->info('Completado.');
        return self::SUCCESS;
    }
}
