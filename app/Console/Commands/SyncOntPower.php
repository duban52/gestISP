<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltSnmpService;
use Illuminate\Console\Command;

class SyncOntPower extends Command
{
    protected $signature   = 'onts:sync-power {--olt= : ID de una OLT específica}';
    protected $description = 'Actualiza la potencia rx de todas las ONTs via SNMP';

    public function __construct(protected OltSnmpService $snmpService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $oltId = $this->option('olt');

        $query = Olt::query();
        if ($oltId) {
            $query->where('id', $oltId);
        }

        foreach ($query->get() as $olt) {
            $this->info("Sincronizando {$olt->name}...");

            $updated = $this->snmpService->syncRxPower($olt);

            $this->info("  → {$updated} ONTs actualizadas.");
        }

        $this->info('Completado.');
        return self::SUCCESS;
    }
}
