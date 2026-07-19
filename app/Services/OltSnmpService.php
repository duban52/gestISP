<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\Ont;
use App\Services\Snmp\SnmpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de métricas de ONTs por SNMP.
 *
 * Sustituye a las consultas SSH para todo lo que sea LECTURA
 * (potencia óptica, temperatura, voltaje, corriente, distancia,
 * estado). El SSH queda reservado para las operaciones de
 * escritura que solo existen en la CLI: activar, mover, CATV.
 *
 * Rendimiento: la ficha completa de una ONT se obtiene con UNA
 * petición SNMP (milisegundos) en lugar de una sesión SSH con
 * varios comandos display (segundos).
 *
 * Los OIDs, escalas y unidades viven en config/olt_snmp.php para
 * poder ajustarlos al modelo real de OLT sin tocar el código.
 */
class OltSnmpService
{
    /**
     * Métricas en vivo de una ONT.
     *
     * Devuelve un array con cada métrica normalizada:
     *   ['rx_power' => ['value' => -21.5, 'unit' => 'dBm', 'label' => ...], ...]
     * más 'query_ms' con lo que tardó la consulta.
     *
     * @return array{metrics: array, query_ms: float, ok: bool}
     */
    public function getOntMetrics(Olt $olt, Ont $ont, bool $useCache = true): array
    {
        $cacheKey = "ont_metrics:{$ont->id}";
        $ttl = (int) config('olt_snmp.cache_ttl', 10);

        if ($useCache && $ttl > 0 && ($cached = Cache::get($cacheKey))) {
            $cached['cached'] = true;

            return $cached;
        }

        $start = microtime(true);

        $definitions = $this->metricDefinitions($olt);
        $client = SnmpClient::forOlt($olt);

        if (!$client || !$ont->if_index) {
            return [
                'ok' => false,
                'metrics' => [],
                'query_ms' => 0.0,
                'error' => $this->unavailableReason($client, $ont),
            ];
        }

        // Sufijo que identifica a la ONT dentro de las tablas
        $suffix = ".{$ont->if_index}.{$ont->onu_id}";

        // Todos los OIDs en UNA sola petición
        $oids = [];
        foreach ($definitions as $key => $def) {
            $oids[$key] = $def['oid'] . $suffix;
        }

        $raw = $client->getMany($oids);
        $client->close();

        $metrics = [];
        foreach ($definitions as $key => $def) {
            $metrics[$key] = $this->normalize($raw[$key] ?? null, $def);
        }

        $result = [
            'ok' => collect($metrics)->contains(fn ($m) => $m['value'] !== null),
            'metrics' => $metrics,
            'query_ms' => round((microtime(true) - $start) * 1000, 1),
            'cached' => false,
        ];

        if ($ttl > 0) {
            Cache::put($cacheKey, $result, $ttl);
        }

        return $result;
    }

    /**
     * Métricas de TODAS las ONTs indicadas, en pocas peticiones.
     *
     * Estrategia: se construye la lista de OIDs de las ONTs que el
     * sistema tiene registradas y se piden por lotes (60 OIDs por
     * paquete). Con 500 ONTs son ~67 peticiones en lugar de 4.000.
     *
     * ¿Por qué no un recorrido (walk) de la tabla completa, que
     * sería aún menos peticiones? Porque las tablas ópticas de
     * Huawei son dispersas y NO responden a un walk desde su OID
     * base: verificado contra el equipo real, el walk devuelve 0
     * filas mientras que el GET directo a un índice concreto sí
     * responde. Por eso se consulta por índice conocido.
     *
     * @param iterable|null $onts ONTs a consultar (por defecto, todas las de la OLT)
     * @return array<string, array> [ "{if_index}.{onu_id}" => [metricas] ]
     */
    public function bulkOntMetrics(Olt $olt, ?iterable $onts = null): array
    {
        $client = SnmpClient::forOlt($olt);

        if (!$client) {
            return [];
        }

        // Comprobar UNA vez que la OLT responde: si está apagada,
        // cada lote agotaría su propio tiempo de espera y el
        // muestreo completo se quedaría colgado varios minutos.
        if (!$client->isReachable()) {
            Log::warning("SNMP: la OLT {$olt->name} ({$olt->ip_address}) no responde; se omite el muestreo.");
            $client->close();

            return [];
        }

        $onts ??= Ont::where('olt_id', $olt->id)
            ->whereNotNull('if_index')
            ->get();

        $definitions = $this->metricDefinitions($olt);

        // Un OID por métrica y por ONT, identificado para poder
        // repartir las respuestas después
        $oids = [];
        foreach ($onts as $ont) {
            if (!$ont->if_index) {
                continue;
            }

            $suffix = ".{$ont->if_index}.{$ont->onu_id}";

            foreach ($definitions as $metric => $def) {
                $oids["{$ont->if_index}.{$ont->onu_id}|{$metric}"] = $def['oid'] . $suffix;
            }
        }

        if (empty($oids)) {
            $client->close();

            return [];
        }

        $raw = $client->getMany($oids);
        $client->close();

        $byOnt = [];

        foreach ($raw as $composedKey => $value) {
            [$index, $metric] = explode('|', $composedKey, 2);

            $byOnt[$index][$metric] = $this->normalize($value, $definitions[$metric]);
        }

        return $byOnt;
    }

    /**
     * Contadores de tráfico (octetos in/out) de las ONTs que tengan
     * su ifIndex propio resuelto.
     *
     * @return array<int, array{in: int|null, out: int|null}> [ifIndex => contadores]
     */
    public function bulkTrafficCounters(Olt $olt): array
    {
        $client = SnmpClient::forOlt($olt);
        $traffic = $this->brandConfig($olt)['traffic'] ?? null;

        if (!$client || !$traffic || !$client->isReachable()) {
            return [];
        }

        $in = $client->walk($traffic['in_octets']);
        $out = $client->walk($traffic['out_octets']);
        $client->close();

        $counters = [];

        foreach ($in as $index => $value) {
            $counters[(int) ltrim($index, '.')]['in'] = is_numeric($value) ? (int) $value : null;
        }

        foreach ($out as $index => $value) {
            $counters[(int) ltrim($index, '.')]['out'] = is_numeric($value) ? (int) $value : null;
        }

        return $counters;
    }

    /**
     * Recorre ifDescr y devuelve el mapa de descripciones de
     * interfaz. Se usa para resolver ifIndex de puertos PON y de
     * ONTs, y es lo que muestra el comando de diagnóstico.
     *
     * @return array<int, string> [ifIndex => descripción]
     */
    public function interfaceDescriptions(Olt $olt): array
    {
        $client = SnmpClient::forOlt($olt);

        if (!$client) {
            return [];
        }

        if (!$client->isReachable()) {
            Log::warning("SNMP: la OLT {$olt->name} no responde al leer sus interfaces.");
            $client->close();

            return [];
        }

        $oid = $this->brandConfig($olt)['if_descr'] ?? '.1.3.6.1.2.1.2.2.1.2';
        $result = [];

        foreach ($client->walk($oid) as $index => $descr) {
            $result[(int) ltrim($index, '.')] = trim($descr);
        }

        $client->close();

        return $result;
    }

    /**
     * ifIndex del PUERTO PON al que pertenece una ONT.
     * (Reemplaza al resolveIfIndex que estaba en el controlador.)
     */
    public function resolvePonPortIfIndex(Olt $olt, string $slot, string $port): ?int
    {
        $pattern = $this->brandConfig($olt)['pon_port_pattern'] ?? null;

        if (!$pattern) {
            return null;
        }

        $regex = str_replace(['%slot%', '%port%'], [preg_quote($slot, '/'), preg_quote($port, '/')], $pattern);

        foreach ($this->interfaceDescriptions($olt) as $ifIndex => $descr) {
            if (preg_match($regex, $descr)) {
                return $ifIndex;
            }
        }

        return null;
    }

    /**
     * ifIndex PROPIO de la ONT, necesario para las estadísticas de
     * tráfico (es distinto del ifIndex del puerto PON, que agrupa a
     * todas las ONTs de ese puerto).
     *
     * Devuelve null si el equipo no expone interfaces por ONT: en
     * ese caso simplemente no habrá gráfica de tráfico.
     */
    public function resolveOntIfIndex(Olt $olt, Ont $ont, ?array $descriptions = null): ?int
    {
        $pattern = $this->brandConfig($olt)['ont_if_pattern'] ?? null;

        if (!$pattern) {
            return null;
        }

        $regex = str_replace(
            ['%slot%', '%port%', '%onu%'],
            [preg_quote((string) $ont->slot, '/'), preg_quote((string) $ont->port, '/'), preg_quote((string) $ont->onu_id, '/')],
            $pattern
        );

        $descriptions ??= $this->interfaceDescriptions($olt);

        foreach ($descriptions as $ifIndex => $descr) {
            if (preg_match($regex, $descr)) {
                return $ifIndex;
            }
        }

        return null;
    }

    /**
     * Explica por qué no se pudo consultar, para mostrarlo en la
     * pantalla en lugar de un error genérico.
     */
    private function unavailableReason(?SnmpClient $client, Ont $ont): string
    {
        if (!SnmpClient::isAvailable()) {
            return 'La extensión SNMP de PHP no está instalada en el servidor. '
                . 'Instálela (apt install phpX.Y-snmp) y reinicie PHP-FPM.';
        }

        if (!$client) {
            return 'La OLT no tiene community SNMP de lectura configurada.';
        }

        return 'La ONT no tiene índice SNMP (if_index) resuelto. '
            . 'Ejecute: php artisan olt:sync-interfaces';
    }

    /**
     * Definiciones de métricas de la marca de la OLT.
     */
    public function metricDefinitions(Olt $olt): array
    {
        return $this->brandConfig($olt)['ont_metrics'] ?? [];
    }

    /**
     * Configuración de la marca de la OLT (huawei por defecto).
     */
    private function brandConfig(Olt $olt): array
    {
        $brand = strtolower($olt->brand ?: 'huawei');

        return config("olt_snmp.brands.{$brand}")
            ?? config('olt_snmp.brands.huawei', []);
    }

    /**
     * Convierte el valor crudo del OID en un valor utilizable,
     * aplicando escala, descartando los valores centinela de "sin
     * dato" y validando el rango plausible.
     *
     * @return array{value: float|string|null, raw: string|null, unit: string, label: string}
     */
    private function normalize(?string $rawValue, array $def): array
    {
        $result = [
            'value' => null,
            'raw' => $rawValue,
            'unit' => $def['unit'] ?? '',
            'label' => $def['label'] ?? '',
        ];

        if ($rawValue === null || $rawValue === '') {
            return $result;
        }

        // Los valores llegan como "INTEGER: -2150" o similares
        $numeric = preg_replace('/[^\-\d.]/', '', $rawValue);

        if ($numeric === '' || !is_numeric($numeric)) {
            return $result;
        }

        $number = (float) $numeric;

        // Valores centinela que significan "no disponible"
        if (in_array((int) $number, $def['invalid'] ?? [], true)) {
            return $result;
        }

        // Estados con traducción (ej. 1 => online)
        if (isset($def['map'])) {
            $result['value'] = $def['map'][(int) $number] ?? (string) (int) $number;

            return $result;
        }

        // scale convierte las unidades del equipo (centésimas,
        // milésimas...) y offset corrige los valores desplazados
        // (algunas OLT suman 10000 dBm para no reportar negativos)
        $value = round($number * ($def['scale'] ?? 1) + ($def['offset'] ?? 0), 2);

        // Rango plausible: descarta lecturas absurdas del equipo
        if (isset($def['min']) && $value < $def['min']) {
            return $result;
        }

        if (isset($def['max']) && $value > $def['max']) {
            return $result;
        }

        $result['value'] = $value;

        return $result;
    }

    /**
     * Compatibilidad: actualiza la potencia de todas las ONTs de
     * una OLT. El comando onts:sync-power lo sigue usando; internamente
     * ya no hace SNMPv1 sino recorridos masivos v2c.
     */
    public function syncRxPower(Olt $olt): int
    {
        $metrics = $this->bulkOntMetrics($olt);
        $updated = 0;

        foreach ($metrics as $index => $data) {
            [$ifIndex, $onuId] = array_pad(explode('.', $index), 2, null);

            if ($onuId === null) {
                continue;
            }

            $ont = Ont::where('olt_id', $olt->id)
                ->where('if_index', (int) $ifIndex)
                ->where('onu_id', (int) $onuId)
                ->first();

            if (!$ont) {
                continue;
            }

            $rxPower = $data['rx_power']['value'] ?? null;

            $ont->update([
                'rx_power' => $rxPower,
                'status' => $rxPower !== null ? 1 : 0,
            ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * Compatibilidad: refresca la potencia de una ONT concreta.
     */
    public function syncSingleOntPower(Olt $olt, Ont $ont): bool
    {
        $result = $this->getOntMetrics($olt, $ont, useCache: false);

        if (!$result['ok']) {
            return false;
        }

        $rxPower = $result['metrics']['rx_power']['value'] ?? null;

        $ont->update([
            'rx_power' => $rxPower,
            'status' => $rxPower !== null ? 1 : 0,
        ]);

        return true;
    }
}
