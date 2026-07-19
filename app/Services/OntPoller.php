<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\Ont;
use App\Models\OntMetric;
use Illuminate\Support\Facades\Log;

/**
 * Muestreo masivo de métricas de ONTs.
 *
 * Recorre las tablas SNMP de la OLT con GETBULK (unos pocos
 * paquetes por métrica, sin importar cuántas ONTs haya), actualiza
 * el estado actual de cada ONT y guarda una muestra en el
 * historial para las gráficas.
 *
 * El ancho de banda se calcula por diferencia entre los contadores
 * de esta lectura y los de la anterior, que es como funcionan
 * todos los sistemas de monitoreo: los equipos no reportan
 * velocidad, sino octetos acumulados.
 */
class OntPoller
{
    public function __construct(
        private readonly OltSnmpService $snmp,
    ) {
    }

    /**
     * Muestrea todas las ONTs de una OLT.
     *
     * @return array{onts: int, updated: int, with_traffic: int, elapsed_ms: float}
     */
    public function poll(Olt $olt, bool $resolveTrafficIndexes = false): array
    {
        $start = microtime(true);

        $onts = Ont::where('olt_id', $olt->id)->get();

        if ($onts->isEmpty()) {
            return ['onts' => 0, 'updated' => 0, 'with_traffic' => 0, 'elapsed_ms' => 0.0, 'reachable' => true];
        }

        // Un recorrido por métrica para TODA la OLT. Si la OLT no
        // responde, bulkOntMetrics lo detecta con una sola consulta
        // y devuelve vacío: no tiene sentido intentar el resto.
        $metrics = $this->snmp->bulkOntMetrics($olt, $onts);

        if (empty($metrics)) {
            return [
                'onts' => $onts->count(),
                'updated' => 0,
                'with_traffic' => 0,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
                'reachable' => false,
            ];
        }

        // Los contadores de tráfico solo se piden si alguna ONT
        // tiene su ifIndex propio resuelto: en los modelos que no
        // exponen interfaz por ONT, recorrer la tabla completa
        // costaría segundos para no obtener nada.
        $counters = $onts->contains(fn ($o) => $o->traffic_if_index)
            ? $this->snmp->bulkTrafficCounters($olt)
            : [];

        // Resolver los ifIndex de tráfico que falten (recorre
        // ifDescr una sola vez para todas las ONTs)
        if ($resolveTrafficIndexes) {
            $this->resolveMissingTrafficIndexes($olt, $onts);
        }

        $measuredAt = now();
        $updated = 0;
        $withTraffic = 0;

        foreach ($onts as $ont) {
            $key = "{$ont->if_index}.{$ont->onu_id}";
            $data = $metrics[$key] ?? null;

            if (!$data) {
                continue;
            }

            $sample = [
                'ont_id' => $ont->id,
                'measured_at' => $measuredAt,
            ];

            foreach (['rx_power', 'tx_power', 'olt_rx_power', 'temperature', 'voltage', 'bias_current', 'distance'] as $field) {
                $sample[$field] = $data[$field]['value'] ?? null;
            }

            $sample['run_status'] = $data['run_status']['value'] ?? null;

            // ---- Tráfico ----
            $counter = $ont->traffic_if_index ? ($counters[$ont->traffic_if_index] ?? null) : null;

            if ($counter) {
                $sample['in_octets'] = $counter['in'] ?? null;
                $sample['out_octets'] = $counter['out'] ?? null;

                $rates = $this->calculateRates($ont, $sample['in_octets'], $sample['out_octets'], $measuredAt);
                $sample['in_bps'] = $rates['in_bps'];
                $sample['out_bps'] = $rates['out_bps'];

                if ($rates['in_bps'] !== null) {
                    $withTraffic++;
                }
            }

            OntMetric::create($sample);

            // Estado actual de la ONT (lo que se ve en los listados)
            $ont->update([
                'rx_power' => $sample['rx_power'],
                'status' => $sample['rx_power'] !== null ? 1 : 0,
            ]);

            $updated++;
        }

        return [
            'onts' => $onts->count(),
            'updated' => $updated,
            'with_traffic' => $withTraffic,
            'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
            'reachable' => true,
        ];
    }

    /**
     * Calcula bits por segundo a partir de la diferencia con la
     * muestra anterior.
     *
     * Contempla el reinicio del contador (cuando el equipo se
     * reinicia el contador vuelve a cero): en ese caso se descarta
     * la muestra en vez de reportar un pico falso.
     *
     * @return array{in_bps: int|null, out_bps: int|null}
     */
    private function calculateRates(Ont $ont, ?int $inOctets, ?int $outOctets, $measuredAt): array
    {
        $previous = OntMetric::where('ont_id', $ont->id)
            ->whereNotNull('in_octets')
            ->latest('measured_at')
            ->first();

        if (!$previous || $inOctets === null || $outOctets === null) {
            return ['in_bps' => null, 'out_bps' => null];
        }

        $seconds = $measuredAt->diffInSeconds($previous->measured_at);

        // Muestras demasiado juntas o demasiado separadas no dan un
        // promedio útil
        if ($seconds < 1 || $seconds > 3600) {
            return ['in_bps' => null, 'out_bps' => null];
        }

        $deltaIn = $inOctets - $previous->in_octets;
        $deltaOut = $outOctets - $previous->out_octets;

        // Contador reiniciado
        if ($deltaIn < 0 || $deltaOut < 0) {
            return ['in_bps' => null, 'out_bps' => null];
        }

        return [
            'in_bps' => (int) round($deltaIn * 8 / $seconds),
            'out_bps' => (int) round($deltaOut * 8 / $seconds),
        ];
    }

    /**
     * Resuelve el ifIndex de tráfico de las ONTs que no lo tengan,
     * recorriendo ifDescr UNA sola vez para todas.
     */
    private function resolveMissingTrafficIndexes(Olt $olt, $onts): void
    {
        $pending = $onts->whereNull('traffic_if_index');

        if ($pending->isEmpty()) {
            return;
        }

        $descriptions = $this->snmp->interfaceDescriptions($olt);

        if (empty($descriptions)) {
            return;
        }

        foreach ($pending as $ont) {
            $ifIndex = $this->snmp->resolveOntIfIndex($olt, $ont, $descriptions);

            if ($ifIndex) {
                $ont->update(['traffic_if_index' => $ifIndex]);
            }
        }
    }

    /**
     * Elimina muestras antiguas para que el historial no crezca sin
     * límite. Por defecto conserva 30 días.
     */
    public function pruneOldMetrics(int $days = 30): int
    {
        return OntMetric::where('measured_at', '<', now()->subDays($days))->delete();
    }
}
