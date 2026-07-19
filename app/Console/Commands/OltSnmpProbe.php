<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\Ont;
use App\Services\OltSnmpService;
use App\Services\Snmp\SnmpClient;
use Illuminate\Console\Command;

/**
 * Diagnóstico SNMP de una OLT.
 *
 * Sirve para VALIDAR contra el equipo real los OIDs definidos en
 * config/olt_snmp.php: muestra qué responde cada uno, el valor
 * crudo y el normalizado, y cuánto tarda la consulta.
 *
 * Es la herramienta a usar cuando una métrica sale vacía o con un
 * valor extraño: si el OID de su modelo de OLT es distinto, se
 * corrige en el archivo de configuración sin tocar código.
 *
 *   php artisan olt:snmp-probe 1
 *   php artisan olt:snmp-probe 1 --ont=5
 *   php artisan olt:snmp-probe 1 --interfaces      (lista ifDescr)
 *   php artisan olt:snmp-probe 1 --interfaces --filter=ONT
 */
class OltSnmpProbe extends Command
{
    protected $signature = 'olt:snmp-probe
                            {olt : ID de la OLT}
                            {--ont= : ID de una ONT concreta (por defecto, la primera)}
                            {--interfaces : Lista las descripciones de interfaz (ifDescr)}
                            {--filter= : Filtra las interfaces por texto}';

    protected $description = 'Verifica la conectividad SNMP y los OIDs configurados contra una OLT real';

    public function __construct(private readonly OltSnmpService $snmp)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $olt = Olt::find($this->argument('olt'));

        if (!$olt) {
            $this->error('No existe esa OLT.');

            return self::FAILURE;
        }

        $this->info("OLT: {$olt->name} ({$olt->ip_address}:{$olt->snmp_port}) — marca: {$olt->brand}");
        $this->newLine();

        // ---- 1. Conectividad ----
        $client = SnmpClient::forOlt($olt);

        if (!$client) {
            $this->error('La OLT no tiene community SNMP de lectura configurada.');

            return self::FAILURE;
        }

        $start = microtime(true);
        $reachable = $client->isReachable();
        $pingMs = round((microtime(true) - $start) * 1000, 1);

        if (!$reachable) {
            $this->error("Sin respuesta SNMP tras {$pingMs} ms.");
            $this->line('Verifique: IP y puerto, community de lectura, y que la OLT permita SNMP desde este servidor.');

            return self::FAILURE;
        }

        $this->info("Responde SNMP correctamente ({$pingMs} ms).");
        $client->close();

        // ---- 2. Interfaces (opcional) ----
        if ($this->option('interfaces')) {
            return $this->listInterfaces($olt);
        }

        // ---- 3. Métricas de una ONT ----
        $ont = $this->option('ont')
            ? Ont::where('olt_id', $olt->id)->find($this->option('ont'))
            : Ont::where('olt_id', $olt->id)->whereNotNull('if_index')->first();

        if (!$ont) {
            $this->warn('No hay ONTs registradas en esta OLT para probar los OIDs.');
            $this->line('Use --interfaces para revisar las interfaces que expone el equipo.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("ONT de prueba: {$ont->sn} — slot {$ont->slot}, puerto {$ont->port}, onu {$ont->onu_id}");
        $this->line("Índice SNMP óptico: {$ont->if_index}.{$ont->onu_id}");
        $this->line('Índice SNMP de tráfico: ' . ($ont->traffic_if_index ?: 'sin resolver'));
        $this->newLine();

        $result = $this->snmp->getOntMetrics($olt, $ont, useCache: false);
        $definitions = $this->snmp->metricDefinitions($olt);

        $rows = [];

        foreach ($definitions as $key => $def) {
            $metric = $result['metrics'][$key] ?? null;
            $value = $metric['value'] ?? null;

            $rows[] = [
                $key,
                $def['oid'] . ".{$ont->if_index}.{$ont->onu_id}",
                $metric['raw'] ?? '(sin respuesta)',
                $value === null ? '—' : $value . ' ' . ($def['unit'] ?? ''),
                $value === null ? 'REVISAR' : 'OK',
            ];
        }

        $this->table(['Métrica', 'OID consultado', 'Valor crudo', 'Normalizado', 'Estado'], $rows);

        $this->newLine();
        $this->info("Tiempo total de la consulta: {$result['query_ms']} ms");
        $this->comment('Compare con SSH: la misma información por CLI tarda varios segundos.');

        if (collect($rows)->contains(fn ($r) => $r[4] === 'REVISAR')) {
            $this->newLine();
            $this->warn('Hay métricas sin valor. Posibles causas:');
            $this->line('  · El OID no existe en este modelo/firmware de OLT → ajústelo en config/olt_snmp.php');
            $this->line('  · La escala (scale) no corresponde y el valor queda fuera del rango min/max');
            $this->line('  · La ONT está apagada o fuera de línea en este momento');
        }

        return self::SUCCESS;
    }

    /**
     * Lista las descripciones de interfaz del equipo: sirve para
     * confirmar los patrones pon_port_pattern y ont_if_pattern.
     */
    private function listInterfaces(Olt $olt): int
    {
        $this->info('Consultando ifDescr...');

        $start = microtime(true);
        $descriptions = $this->snmp->interfaceDescriptions($olt);
        $ms = round((microtime(true) - $start) * 1000, 1);

        if (empty($descriptions)) {
            $this->error('El equipo no devolvió descripciones de interfaz.');

            return self::FAILURE;
        }

        $filter = $this->option('filter');

        if ($filter) {
            $descriptions = array_filter(
                $descriptions,
                fn ($d) => stripos($d, $filter) !== false
            );
        }

        $this->info(count($descriptions) . " interfaces ({$ms} ms para recorrer la tabla completa).");
        $this->newLine();

        $rows = [];
        foreach (array_slice($descriptions, 0, 60, true) as $ifIndex => $descr) {
            $rows[] = [$ifIndex, $descr];
        }

        $this->table(['ifIndex', 'Descripción'], $rows);

        if (count($descriptions) > 60) {
            $this->comment('Mostrando las primeras 60. Use --filter para acotar.');
        }

        $this->newLine();
        $this->comment('Con esta lista puede ajustar en config/olt_snmp.php los patrones:');
        $this->line('  pon_port_pattern → interfaz del puerto PON (métricas ópticas)');
        $this->line('  ont_if_pattern   → interfaz por ONT (gráfica de tráfico)');

        return self::SUCCESS;
    }
}
