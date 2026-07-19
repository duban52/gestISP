<?php

namespace App\Services;

use App\Models\PppoeAccount;
use App\Models\PppoeSessionMetric;
use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * Muestreo del tráfico de las cuentas PPPoE.
 *
 * Consulta UNA vez por router los contadores de todas sus
 * interfaces (RouterOS crea una interfaz dinámica por sesión
 * activa) y reparte los datos entre las cuentas registradas: con
 * 500 clientes se hace una sola petición al router, no 500.
 *
 * El ancho de banda se calcula por diferencia entre los
 * contadores de esta lectura y los de la anterior.
 */
class PppoePoller
{
    public function __construct(
        private readonly MikrotikApiService $mikrotik,
    ) {
    }

    /**
     * Muestrea todas las cuentas de un router.
     *
     * @return array{accounts: int, connected: int, elapsed_ms: float, reachable: bool}
     */
    public function poll(Router $router): array
    {
        $start = microtime(true);

        $accounts = PppoeAccount::where('router_id', $router->id)->get();

        if ($accounts->isEmpty()) {
            return ['accounts' => 0, 'connected' => 0, 'elapsed_ms' => 0.0, 'reachable' => true];
        }

        // Una sola petición para todas las sesiones del router
        try {
            $counters = $this->mikrotik->getPppoeInterfaceCounters($router);
        } catch (\Exception $e) {
            Log::warning("PPPoE: el router {$router->name} no respondió: " . $e->getMessage());

            return [
                'accounts' => $accounts->count(),
                'connected' => 0,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
                'reachable' => false,
            ];
        }

        $measuredAt = now();
        $connected = 0;

        foreach ($accounts as $account) {
            $counter = $counters[$account->username] ?? null;

            // Sin interfaz dinámica = sesión no establecida. Se
            // guarda igualmente la muestra: así la gráfica muestra
            // los cortes de servicio, no solo el tráfico.
            if (!$counter) {
                PppoeSessionMetric::create([
                    'pppoe_account_id' => $account->id,
                    'connected' => false,
                    'measured_at' => $measuredAt,
                ]);

                continue;
            }

            $rates = $this->calculateRates($account, $counter['in'], $counter['out'], $measuredAt);

            PppoeSessionMetric::create([
                'pppoe_account_id' => $account->id,
                'in_octets' => $counter['in'],
                'out_octets' => $counter['out'],
                'in_bps' => $rates['in_bps'],
                'out_bps' => $rates['out_bps'],
                'connected' => true,
                'measured_at' => $measuredAt,
            ]);

            $connected++;
        }

        return [
            'accounts' => $accounts->count(),
            'connected' => $connected,
            'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
            'reachable' => true,
        ];
    }

    /**
     * Calcula bits por segundo a partir de la muestra anterior.
     *
     * Descarta el caso de contador reiniciado (al reconectarse la
     * sesión, RouterOS crea una interfaz nueva y los contadores
     * vuelven a cero): reportar la diferencia daría un pico falso.
     *
     * @return array{in_bps: int|null, out_bps: int|null}
     */
    private function calculateRates(PppoeAccount $account, int $inOctets, int $outOctets, $measuredAt): array
    {
        $previous = PppoeSessionMetric::where('pppoe_account_id', $account->id)
            ->where('connected', true)
            ->whereNotNull('in_octets')
            ->latest('measured_at')
            ->first();

        if (!$previous) {
            return ['in_bps' => null, 'out_bps' => null];
        }

        $seconds = $measuredAt->diffInSeconds($previous->measured_at);

        if ($seconds < 1 || $seconds > 3600) {
            return ['in_bps' => null, 'out_bps' => null];
        }

        $deltaIn = $inOctets - $previous->in_octets;
        $deltaOut = $outOctets - $previous->out_octets;

        // Sesión reconectada: contadores desde cero
        if ($deltaIn < 0 || $deltaOut < 0) {
            return ['in_bps' => null, 'out_bps' => null];
        }

        return [
            'in_bps' => (int) round($deltaIn * 8 / $seconds),
            'out_bps' => (int) round($deltaOut * 8 / $seconds),
        ];
    }

    /**
     * Elimina muestras antiguas para acotar el historial.
     */
    public function pruneOldMetrics(int $days = 30): int
    {
        return PppoeSessionMetric::where('measured_at', '<', now()->subDays($days))->delete();
    }
}
